<?php
/**
 * HTTP Basic Authentication handler
 * Supports both static config-based auth and dynamic credential validation
 */
class Auth
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check if the request is authenticated against static config credentials
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

        // Check credentials
        if ($username === $this->config['username'] && $password === $this->config['password']) {
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

        // Validate credentials match
        return ($providedUsername === $expectedUsername && $providedPassword === $expectedPassword);
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
