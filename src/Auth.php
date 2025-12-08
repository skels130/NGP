<?php
/**
 * HTTP Basic Authentication handler
 *
 * Supports two authentication modes:
 * 1. Global one-time password (from config) - Used for initial device provisioning
 * 2. Dynamic device-specific credentials (from ns-api) - Used for ongoing provisioning
 *
 * The global one-time password allows new devices to authenticate for their first
 * configuration request. After successful authentication with the one-time password,
 * it is automatically disabled and device-specific credentials are generated.
 */
class Auth
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        // Parse Authorization header if not already done by Apache
        $this->parseAuthorizationHeader();
    }

    /**
     * Parse HTTP Authorization header and populate $_SERVER variables
     * This is needed when Apache passes the header via CGI/FastCGI but doesn't parse it
     */
    private function parseAuthorizationHeader(): void
    {
        // Skip if already parsed by Apache
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return;
        }

        // Check various possible header locations
        $authHeader = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }

        // Parse Basic Auth header
        if ($authHeader && preg_match('/^Basic\s+(.*)$/i', $authHeader, $matches)) {
            $credentials = base64_decode($matches[1]);
            if ($credentials && strpos($credentials, ':') !== false) {
                list($username, $password) = explode(':', $credentials, 2);
                $_SERVER['PHP_AUTH_USER'] = $username;
                $_SERVER['PHP_AUTH_PW'] = $password;
            }
        }
    }

    /**
     * Check if the request is authenticated against global one-time password
     *
     * This checks the credentials from config.php which serves as the global
     * one-time password for initial device provisioning. Only works when the
     * device has global-one-time-pass=yes in ns-api.
     *
     * @return bool
     */
    public function authenticate(): bool
    {
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }

        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];

        // Check credentials using timing-safe comparison
        if (hash_equals($this->config['username'], $username) &&
            hash_equals($this->config['password'], $password)) {
            return true;
        }

        return false;
    }

    /**
     * Validate request credentials against provided username and password
     * Used for dynamic per-device authentication
     *
     * @param string|null $expectedUsername Expected username
     * @param string|null $expectedPassword Expected password
     * @return bool True if credentials match or if both expected credentials are null
     */
    public function validateCredentials(?string $expectedUsername, ?string $expectedPassword): bool
    {
        // If both credentials are null, device has no provisioning creds - allow
        if ($expectedUsername === null && $expectedPassword === null) {
            return true;
        }

        // If only one is null, this is an error condition - reject
        if ($expectedUsername === null || $expectedPassword === null) {
            return false;
        }

        // Get provided credentials from request
        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            return false;
        }

        $providedUsername = $_SERVER['PHP_AUTH_USER'];
        $providedPassword = $_SERVER['PHP_AUTH_PW'];

        // Validate credentials match using timing-safe comparison
        return (hash_equals($expectedUsername, $providedUsername) &&
                hash_equals($expectedPassword, $providedPassword));
    }

    /**
     * Get the credentials provided in the current request
     *
     * @return array Array with 'username' and 'password' keys, or null values if not provided
     */
    public function getProvidedCredentials(): array
    {
        return [
            'username' => $_SERVER['PHP_AUTH_USER'] ?? null,
            'password' => $_SERVER['PHP_AUTH_PW'] ?? null,
        ];
    }

    /**
     * Check if credentials were provided in the request
     *
     * @return bool
     */
    public function hasCredentials(): bool
    {
        return isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']);
    }

    /**
     * Send authentication required headers
     */
    public function requireAuth(): void
    {
        header('WWW-Authenticate: Basic realm="NGP"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Authentication required';
    }
}
