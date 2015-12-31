<?php
class RealMeService extends Object
{
    /**
     * Current RealMe supported environments.
     */
    const ENV_MTS = 'mts';
    const ENV_ITE = 'ite';
    const ENV_PROD = 'prod';

    /**
     * the valid AuthN context values for each supported RealMe environment.
     */
    const AUTHN_LOW_STRENGTH = 'urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:LowStrength';
    const AUTHN_MOD_STRENTH = 'urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength';
    const AUTHN_MOD_MOBILE_SMS = 'urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength::OTP:Mobile:SMS';
    const AUTHN_MOD_TOKEN_SID = 'urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength::OTP:Token:SID';

    /**
     * @var ArrayData|null User data returned by RealMe. Provided by {@link self::ensureLogin()}.
     *
     * Data within this ArrayData is as follows:
     * - NameID:       ArrayData   Includes the UserFlt and associated formatting information
     * - UserFlt:      string      RealMe pseudonymous username / identity
     * - Attributes:   ArrayData   User attributes returned by RealMe
     * - Expire:       SS_Datetime The expiry date & time of this authentication session
     * - SessionIndex: string      Unique identifier used to identify a user with both IdP and SP for given user.
     */
    private static $user_data = null;

    /**
     * @config
     * @var string The authentication source to use, which ultimately determines which RealMe environment is
     * authenticated against. This should be set by Config, and generally be different per environment (e.g. developer
     * environments would generally use 'realme-mts', UAT/staging sites might use 'realme-ite', and production sites
     * would use 'realme-prod'.
     */
    private static $auth_source_name = 'realme-mts';

    /**
     * @config
     * @var string The base url path that is passed through to SimpleSAMLphp. This should be relative to the web root,
     * and is passed through to SimpleSAMLphp's config.php for it to base all its URLs from. The default is
     * 'vendor/madmatt/simplesamlphp/www/', which implies that '//your-site-url.com/vendor/madmatt/simplesamlphp/www/' 
     * is routed through to the SimpleSAMLphp `www` directory.
     * @see RealMeSetupTask for more information on how this is configured
     */
    private static $simplesaml_base_url_path = 'vendor/madmatt/simplesamlphp/www/';

    /**
     * @config
     * @var string The complete password that will be passed to SimpleSAMLphp for admin logins to the SimpleSAMLphp web
     * interface. If set to the default `null`, the @link self::findOrMakeSimpleSAMLPassword() will make a random
     * password which won't be accessible again later. If this value is set via the Config API, then it should be in
     * the format required by SimpleSAMLphp. To generate a password in this format, see the bin/pwgen.php file in the
     * SimpleSAMLphp base directory.
     * @see self::findOrMakeSimpleSAMLPassword()
     */
    private static $simplesaml_hashed_admin_password = null;

    /**
     * @config
     * @var string A 32-byte salt that is used by SimpleSAMLphp when signing content. Stored in SimpleSAMLphp's config
     * if required.
     * @see self::generateSimpleSAMLSalt()
     */
    private static $simplesaml_secret_salt = null;

    /**
     * @config
     * @var array The RealMe environments that can be used. If this is changed, then the RealMeSetupTask would need to
     * be run again, and updated environment names would need to be put into the authsources.php and
     * saml20-idp-remote.php files.
     */
    private static $allowed_realme_environments = array(self::ENV_MTS, self::ENV_ITE, self::ENV_PROD);

    /**
     * @config
     * @var array Stores the entity ID value for each supported RealMe environment. This needs to be setup prior to
     * running the `RealMeSetupTask` build task. For more information, see the module documentation. An entity ID takes
     * the form of a URL, e.g. https://www.agency.govt.nz/privacy-realm-name/application-name
     */
    private static $entity_ids = array(
        self::ENV_MTS => null,
        self::ENV_ITE => null,
        self::ENV_PROD => null
    );

    /**
     * @config
     * @var array Stores the AuthN context values for each supported RealMe environment. This needs to be setup prior to
     * running the `RealMeSetupTask` build task. For more information, see the module documentation. An AuthN context
     * can be one of the following:
     *
     * Username and password only:
     * - urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:LowStrength
     *
     * Username, password, and any moderate strength second level of authenticator (RSA token, Google Auth, SMS)
     * - urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength
     *
     * The following two are less often used, and shouldn't be used unless there's a specific need.
     *
     * Username, password, and only SMS 2FA token
     * - urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength::OTP:Mobile:SMS
     *
     * Username, password, and only RSA 2FA token
     * - urn:nzl:govt:ict:stds:authn:deployment:GLS:SAML:2.0:ac:classes:ModStrength::OTP:Token:SID
     */
    private static $authn_contexts = array(
        self::ENV_MTS => null,
        self::ENV_ITE => null,
        self::ENV_PROD => null
    );

    /**
     * @config $allowed_authn_context_list
     * @var $allowed_authn_context_list array
     *
     * A list of the valid authn context values supported for realme.
     */
    private static $allowed_authn_context_list = array(
        self::AUTHN_LOW_STRENGTH,
        self::AUTHN_MOD_STRENTH,
        self::AUTHN_MOD_MOBILE_SMS,
        self::AUTHN_MOD_TOKEN_SID
    );


    /**
     * @config
     * @var array Stores the proxy_host values used when creating the back-channel SoapClient connection to the RealMe
     * artifact resolution service. This can either be:
     * - null (indicating no proxy is required),
     * - a plain string (e.g. gateway.your-network.govt.nz),
     * - the name of an environment variable that can be called (via getenv()) to retrieve the proxy URL from
     *       (e.g. env:http_proxy). In this case, it is assumed that a full URL would exist in this environment variable
     *       (e.g. tcp://gateway.your-network.govt.nz:8080) as it is intended to be used to mimic how curl handles HTTP
     *       proxy (if you specify the http_proxy env-var, curl will automatically parse it as a full URL and use that
     *       for resolving all requests by default.
     */
    private static $backchannel_proxy_hosts = array(
        self::ENV_MTS => null,
        self::ENV_ITE => null,
        self::ENV_PROD => null
    );

    /**
     * @config
     * @var array Stores the proxy_port values used when creating the back-channel SoapClient connection to the RealMe
     * artifact resolution service.
     *
     * See the definition for self::$backchannel_proxy_hosts for more information on the
     * valid values.
     */
    private static $backchannel_proxy_ports = array(
        self::ENV_MTS => null,
        self::ENV_ITE => null,
        self::ENV_PROD => null
    );

    /**
     * @config
     * @var array Domain names for metadata files. Used in @link RealMeSetupTask when outputting metadata XML
     */
    private static $metadata_assertion_service_domains = array(
        self::ENV_MTS => null,
        self::ENV_ITE => null,
        self::ENV_PROD => null
    );

    /**
     * @config
     * @var string|null The organisation name to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_organisation_name = null;

    /**
     * @config
     * @var string|null The organisation display name to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_organisation_display_name = null;

    /**
     * @config
     * @var string|null The organisation URL to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_organisation_url = null;

    /**
     * @config
     * @var string|null The support contact's company name to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_contact_support_company = null;

    /**
     * @config
     * @var string|null The support contact's first name(s) to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_contact_support_firstnames = null;

    /**
     * @config
     * @var string|null The support contact's surname to be used in metadata XML that is submitted to RealMe
     */
    private static $metadata_contact_support_surname = null;

    /**
     * @return bool true if the user is correctly authenticated, false if there was an error with login
     * NB: If the user is not authenticated, they will be redirected to RealMe to login, so a boolean false return here
     * indicates that there was a failure during the authentication process (perhaps a communication issue)
     */
    public function enforceLogin()
    {
        $auth = new SimpleSAML_Auth_Simple($this->config()->auth_source_name);

        $auth->requireAuth(array(
            'ReturnTo' => '/Security/realme/acs',
            'ErrorURL' => '/Security/realme/error'
        ));

        $loggedIn = false;
        $authData = $this->getAuthData($auth);

        if (is_null($authData)) {
            // no-op, $loggedIn stays false and no data is written
        } else {
            $this->config()->user_data = $authData;
            Session::set('RealMeSessionDataSerialized', serialize($authData));
            $loggedIn = true;
        }
        return $loggedIn;
    }

    /**
     * Clear the RealMe credentials from Session, and also remove SimpleSAMLphp session information.
     * @return void
     */
    public function clearLogin()
    {
        Session::clear('RealMeSessionDataSerialized');
        $this->config()->__set('user_data', null);

        $session = SimpleSAML_Session::getSessionFromRequest();

        if ($session instanceof SimpleSAML_Session) {
            $session->doLogout($this->config()->auth_source_name);
        }
    }

    /**
     * Return the user data which was saved to session from the first RealMe auth.
     * Note: Does not check authenticity or expiry of this data
     *
     * @return ArrayData
     */
    public function getUserData()
    {
        if (is_null($this->config()->user_data)) {
            $sessionData = Session::get('RealMeSessionDataSerialized');

            if (!is_null($sessionData) && unserialize($sessionData) !== false) {
                $this->config()->user_data = unserialize($sessionData);
            }
        }

        return $this->config()->user_data;
    }

    /**
     * @param SimpleSAML_Auth_Simple $auth The authentication context as returned from RealMe
     * @return ArrayData
     */
    private function getAuthData(SimpleSAML_Auth_Simple $auth)
    {
        // returns null if the current auth is invalid or timed out.
        $data = $auth->getAuthDataArray();
        $returnedData = null;

        if (
            is_array($data) &&
            isset($data['saml:sp:IdP']) &&
            isset($data['saml:sp:NameID']) &&
            is_array($data['saml:sp:NameID']) &&
            isset($data['saml:sp:NameID']['Value']) &&
            isset($data['Expire']) &&
            isset($data['Attributes']) &&
            isset($data['saml:sp:SessionIndex'])
        ) {
            $returnedData = new ArrayData(array(
                'NameID' => new ArrayData($data['saml:sp:NameID']),
                'UserFlt' => $data['saml:sp:NameID']['Value'],
                'Attributes' => new ArrayData($data['Attributes']),
                'Expire' => $data['Expire'],
                'SessionIndex' => $data['saml:sp:SessionIndex']
            ));
        }
        return $returnedData;
    }

    /**
     * @return string A BackURL as specified originally when accessing /Security/login, for use after authentication
     */
    public function getBackURL()
    {
        if (!empty($_REQUEST['BackURL'])) {
            $url = $_REQUEST['BackURL'];
        } elseif (Session::get('BackURL')) {
            $url = Session::get('BackURL');
            Session::clear('BackURL'); // Ensure we don't redirect back to the same error twice
        }

        if (isset($url) && Director::is_site_url($url)) {
            $url = Director::absoluteURL($url);
        } else {
            // Spoofing attack or no back URL set, redirect to homepage instead of spoofing url
            $url = Director::absoluteBaseURL();
        }

        return $url;
    }

    /**
     * @return string|null Either the directory where SimpleSAMLphp configuration is stored, or null if undefined
     */
    public function getSimpleSamlConfigDir()
    {
        return (defined('REALME_CONFIG_DIR') ? rtrim(REALME_CONFIG_DIR, '/') : null);
    }

    /**
     * @return string The path to SimpleSAMLphp's metadata. This will either be defined in config, or just '/metadata'
     */
    public function getSimpleSamlMetadataDir()
    {
        return sprintf('%s/metadata', $this->getSimpleSamlConfigDir());
    }

    /**
     * @return string Either the value for baseurlpath in SimpleSAML's config, or a default value if it's been unset
     */
    public function getSimpleSamlBaseUrlPath()
    {
        if (strlen($this->config()->simplesaml_base_url_path) > 0) {
            return $this->config()->simplesaml_base_url_path;
        } else {
            return 'simplesaml/';
        }
    }

    /**
     * @return string|null Either the directory where certificates are stored, or null if undefined
     */
    public function getCertDir()
    {
        return (defined('REALME_CERT_DIR') ? REALME_CERT_DIR : null);
    }

    /**
     * @return string|null Either the directory where logging information is kept by SimpleSAMLphp, or null if undefined
     */
    public function getLoggingDir()
    {
        return (defined('REALME_LOG_DIR') ? REALME_LOG_DIR : null);
    }

    /**
     * @return string|null Either the directory where temp files can be written by SimpleSAMLphp, or null if undefined
     */
    public function getTempDir()
    {
        return (defined('REALME_TEMP_DIR') ? REALME_TEMP_DIR : null);
    }

    /**
     * This looks first to a Config variable that can be set in YML configuration, and falls back to generating a
     * salted SHA256-hashed password. To generate a password in this format, see the bin/pwgen.php file in the
     * SimpleSAMLphp vendor directory (normally vendor/madmatt/simplesamlphp/bin/pwgen.php). If setting a password
     * via Config, ensure it contains {SSHA256} at the start of the line.
     *
     * @return string|null The administrator password set for SimpleSAMLphp. If null, it means a strong hash couldn't be
     * created due to the code being deployed on an older machine, and a generated password will need to be set.
     */
    public function findOrMakeSimpleSAMLPassword()
    {
        if (strlen($this->config()->simplesaml_hashed_admin_password) > 0) {
            $password = $this->config()->simplesaml_hashed_admin_password;

            if (strpos($password, '{SSHA256}') !== 0) {
                $password = null; // Ensure password is salted SHA256
            }
        } else {
            $salt = openssl_random_pseudo_bytes(8, $strongSalt); // SHA256 needs 8 bytes
            $password = openssl_random_pseudo_bytes(32, $strongPassword); // Make a random 32-byte password

            if (!$strongSalt || !$strongPassword || !$salt || !$password) {
                $password = null; // Ensure the password is strong, return null if we can't guarantee a strong one
            } else {
                $hash = hash('sha256', $password.$salt, true);
                $password = sprintf('{SSHA256}%s', base64_encode($hash.$salt));
            }
        }

        return $password;
    }

    /**
     * @return string A 32-byte salt string for SimpleSAML to use when signing content
     */
    public function generateSimpleSAMLSalt()
    {
        if (strlen($this->config()->simplesaml_secret_salt) > 0) {
            $salt = $this->config()->simplesaml_secret_salt;
        } else {
            $salt = base64_encode(openssl_random_pseudo_bytes(32, $strongSalt));

            if (!$salt || !$strongSalt) {
                $salt = null; // Ensure salt is strong, return null if we can't generate a strong one
            }
        }

        return $salt;
    }

    /**
     * Returns the appropriate entity ID for RealMe, given the environment passed in. The entity ID may be different per
     * environment, and should be a full URL, including privacy realm and application name. For example, this may be:
     * https://www.agency.govt.nz/privacy-realm-name/application-name
     *
     * @param string $env The environment to return the entity ID for. Must be one of the RealMe environment names
     * @return string|null Returns the entity ID for the given $env, or null if no entity ID exists
     */
    public function getEntityIDForEnvironment($env)
    {
        return $this->getConfigurationVarByEnv('entity_ids', $env);
    }

    /**
     * Returns the appropriate AuthN Context, given the environment passed in. The AuthNContext may be different per
     * environment, and should be one of the strings as defined in the static {@link self::$authn_contexts} at the top
     * of this class.
     *
     * @param string $env The environment to return the AuthNContext for. Must be one of the RealMe environment names
     * @return string|null Returns the AuthNContext for the given $env, or null if no context exists
     */
    public function getAuthnContextForEnvironment($env)
    {
        return $this->getConfigurationVarByEnv('authn_contexts', $env);
    }

    /**
     * Gets the proxy host (if required) for back-channel SOAP requests. The proxy host can begin with the string 'env:'
     * in which case the script will call getenv() on the returned value and attempt to parse it as a full URL. This is
     * designed primarily to be compatible with the 'http_proxy' that curl uses by default. In other words, passing in
     * `env:http_proxy` is the equivalent of saying 'use the same HTTP proxy that curl will use in this environment'.
     *
     * @param string $env The environment to return the proxy_host for. Must be one of the RealMe environment names
     * @return string|null Returns the SOAPClient `proxy_host` param, or null if there isn't one
     */
    public function getProxyHostForEnvironment($env)
    {
        $host = $this->getConfigurationVarByEnv('backchannel_proxy_hosts', $env);

        // Allow usage of an environment variable to define this
        if (substr($host, 0, 4) === 'env:') {
            $host = getenv(substr($host, 4));

            if ($host === false) {
                // getenv() didn't return a valid environment var, it's either mis-spelled or doesn't exist
                $host = null;
            } else {
                $host = parse_url($host, PHP_URL_HOST);

                // This may happen on seriously malformed URLs, in which case we should return null
                if ($host === false) {
                    $host = null;
                }
            }
        }

        return $host;
    }

    /**
     * Gets the proxy port (if required) for back-channel SOAP requests. The proxy port can begin with the string 'env:'
     * in which case the script will call getenv() on the returned value and attempt to parse it as a full URL. This is
     * designed primarily to be compatible with the 'http_proxy' that curl uses by default. In other words, passing in
     * `env:http_proxy` is the equivalent of saying 'use the same HTTP proxy that curl will use in this environment'.
     *
     * @param string $env The environment to return the proxy_port for. Must be one of the RealMe environment names
     * @return string|null Returns the SOAPClient `proxy_port` param, or null if there isn't one
     */
    public function getProxyPortForEnvironment($env)
    {
        $port = $this->getConfigurationVarByEnv('backchannel_proxy_ports', $env);

        // Allow usage of an environment variable to define this
        if (substr($port, 0, 4) === 'env:') {
            $port = getenv(substr($port, 4));

            if ($port === false) {
                // getenv() didn't return a valid environment var, it's either mis-spelled or doesn't exist
                $port = null;
            } else {
                $port = parse_url($port, PHP_URL_PORT);

                // This may happen on seriously malformed URLs, in which case we should return null
                if ($port === false) {
                    $port = null;
                }
            }
        }

        return $port;
    }

    /**
     * @param string $cfgName The static configuration value to get. This should be an array
     * @param string $env The environment to return the value for. Must be one of the RealMe environment names
     * @return string|null Returns the value as defined in $cfgName for the given environment, or null if none exist
     */
    private function getConfigurationVarByEnv($cfgName, $env)
    {
        $value = null;

        if (in_array($env, $this->getAllowedRealMeEnvironments())) {
            $values = $this->config()->$cfgName;

            if (is_array($values) && isset($values[$env])) {
                $value = $values[$env];
            }
        }

        return $value;
    }

    /**
     * Returns the full path to the SAML signing certificate file, used by SimpleSAMLphp to sign all messages sent to
     * RealMe.
     *
     * @return string|null Either the full path to the SAML signing certificate file, or null if it doesn't exist
     */
    public function getSigningCertPath()
    {
        return $this->getCertPath('SIGNING');
    }

    /**
     * Returns the full path to the mutual back-channel certificate file, used by SimpleSAMLphp to communicate securely
     * with RealMe when connecting to the RealMe Assertion Resolution Service (Artifact Resolver).
     *
     * @return string|null Either the full path to the SAML mutual certificate file, or null if it doesn't exist
     */
    public function getMutualCertPath()
    {
        return $this->getCertPath('MUTUAL');
    }

    /**
     * @param string $certName The certificate name, either 'SIGNING' or 'MUTUAL'
     * @return string|null Either the full path to the certificate file, or null if it doesn't exist
     * @see self::getSigningCertPathForEnvironment(), self::getMutualCertPathForEnvironment()
     */
    private function getCertPath($certName)
    {
        $certPath = null;
        $certDir = $this->getCertDir();

        if (in_array($certName, array('SIGNING', 'MUTUAL'))) {
            $constName = sprintf('REALME_%s_CERT_FILENAME', strtoupper($certName));
            if (defined($constName)) {
                $filename = constant($constName);
                $certPath = Controller::join_links($certDir, $filename);
            }
        }

        // Ensure the file exists, if it doesn't then set it to null
        if (!is_null($certPath) && !file_exists($certPath)) {
            $certPath = null;
        }

        return $certPath;
    }

    /**
     * Returns the password (if any) necessary to decrypt the signing cert specified by self::getSigningCertPath(). If
     * no password is set, then this method returns null. MTS certificates require a password, however generally the
     * certificates used for ITE and production don't need one.
     *
     * @return string|null Either the password, or null if there is no password.
     */
    public function getSigningCertPassword()
    {
        return (defined('REALME_SIGNING_CERT_PASSWORD') ? REALME_SIGNING_CERT_PASSWORD : null);
    }

    /**
     * Returns the password (if any) necessary to decrypt the mutual back-channel cert specified by
     * self::getSigningCertPath(). If no password is set, then this method returns null. MTS certificates require a
     * password, however generally the certificates used for ITE and production don't need one.
     *
     * @return string|null Either the password, or null if there is no password.
     */
    public function getMutualCertPassword()
    {
        return (defined('REALME_MUTUAL_CERT_PASSWORD') ? REALME_MUTUAL_CERT_PASSWORD : null);
    }

    /**
     * Returns the content of the SAML signing certificate. This is used by @link RealMeSetupTask to output metadata.
     * The metadata file requires just the certificate to be included, without the BEGIN/END CERTIFICATE lines
     * @return string|null The content of the signing certificate
     */
    public function getSigningCertContent()
    {
        $certPath = $this->getSigningCertPath();
        $certificate = null;

        if (!is_null($certPath)) {
            $certificateContents = file_get_contents($certPath);

            // This is a PEM key, and we need to extract just the certificate, stripping out the private key etc.
            // So we search for everything between '-----BEGIN CERTIFICATE-----' and '-----END CERTIFICATE-----'
            preg_match(
                '/-----BEGIN CERTIFICATE-----\n([^-]*)\n-----END CERTIFICATE-----/',
                $certificateContents,
                $matches
            );

            if (isset($matches) && is_array($matches) && isset($matches[1])) {
                $certificate = $matches[1];
            }
        }

        return $certificate;
    }

    /**
     * @param string $env The environment to return the entity ID for. Must be one of the RealMe environment names
     * @return string|null Either the assertion consumer service location, or null if information doesn't exist
     */
    public function getAssertionConsumerServiceUrlForEnvironment($env)
    {
        if (false === in_array($env, $this->getAllowedRealMeEnvironments())) {
            return null;
        }

        // Returns http://domain.govt.nz/vendor/madmatt/simplesamlphp/www/module.php/saml/sp/saml2-acs.php/realme-mts
        $domain = $this->getMetadataAssertionServiceDomainForEnvironment($env);
        if (false === filter_var($domain, FILTER_VALIDATE_URL)) {
            return null;
        }

        $basePath = $this->getSimpleSamlBaseUrlPath();
        $modulePath = 'module.php/saml/sp/saml2-acs.php/';
        $authSource = sprintf('realme-%s', $env);
        return Controller::join_links($domain, $basePath, $modulePath, $authSource);
    }

    /**
     * @param string $env The environment to return the domain name for. Must be one of the RealMe environment names
     * @return string|null Either the FQDN (e.g. https://www.realme-demo.govt.nz/) or null if none is specified
     */
    private function getMetadataAssertionServiceDomainForEnvironment($env)
    {
        return $this->getConfigurationVarByEnv('metadata_assertion_service_domains', $env);
    }

    /**
     * @return string|null The organisation name to be used in metadata XML output, or null if none exists
     */
    public function getMetadataOrganisationName()
    {
        $orgName = $this->config()->metadata_organisation_name;
        return (strlen($orgName) > 0) ? $orgName : null;
    }

    /**
     * @return string|null The organisation display name to be used in metadata XML output, or null if none exists
     */
    public function getMetadataOrganisationDisplayName()
    {
        $displayName = $this->config()->metadata_organisation_display_name;
        return (strlen($displayName) > 0) ? $displayName : null;
    }

    /**
     * @return string|null The organisation website URL to be used in metadata XML output, or null if none exists
     */
    public function getMetadataOrganisationUrl()
    {
        $url = $this->config()->metadata_organisation_url;
        return (strlen($url) > 0) ? $url: null;
    }

    /**
     * @return array The support contact details to be used in metadata XML output, with null values if they don't exist
     */
    public function getMetadataContactSupport()
    {
        $company = $this->config()->metadata_contact_support_company;
        $firstNames = $this->config()->metadata_contact_support_firstnames;
        $surname = $this->config()->metadata_contact_support_surname;

        return array(
            'company' => (strlen($company) > 0) ? $company : null,
            'firstNames' => (strlen($firstNames) > 0) ? $firstNames : null,
            'surname' => (strlen($surname) > 0) ? $surname : null
        );
    }

    /**
     * The list of RealMe environments that can be used. By default, we allow mts, ite and production.
     * @return array
     */
    public function getAllowedRealMeEnvironments()
    {
        return $this->config()->allowed_realme_environments;
    }

    /**
     * The list of valid realme AuthNContexts
     * @return array
     */
    public function getAllowedAuthNContextList()
    {
        return $this->config()->allowed_authn_context_list;
    }
}
