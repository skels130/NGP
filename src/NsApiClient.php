<?php
/**
 * ns-api Client for retrieving device and SIP credential information
 */
class NsApiClient
{
    private $config;
    private $logger;
    private $baseUrl;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->baseUrl = rtrim($config['base_url'], '/');
    }

    /**
     * Get phone information by MAC address
     * Returns domain, user, device identifier, brand, model, and provisioning credentials
     *
     * @param string $mac MAC address (12 hex characters)
     * @return array|null Phone info or null if not found
     */
    public function getPhoneInfo(string $mac): ?array
    {
        $endpoint = "/phones/" . strtoupper($mac);
        $response = $this->makeRequest('GET', $endpoint);

        if (!$response) {
            return null;
        }

        // Extract all relevant information from response
        // The actual field names may vary - adjust based on actual ns-api response

        // Parse brand and model from combined field "device-models-brand-and-model"
        $brand = null;
        $model = null;
        $brandAndModel = $response['device-models-brand-and-model'] ??
                        $response['brand'] ??
                        $response['make'] ??
                        $response['vendor'] ?? null;

        if ($brandAndModel && strpos($brandAndModel, ' ') !== false) {
            // Split "Grandstream GXW-4248" into brand and model
            list($brand, $model) = explode(' ', $brandAndModel, 2);
        } elseif ($brandAndModel) {
            // If no space, treat entire string as brand
            $brand = $brandAndModel;
        }

        // Allow override from separate fields if present
        $brand = $response['brand'] ?? $response['make'] ?? $response['vendor'] ?? $brand;
        $model = $response['model'] ?? $model;

        // Parse device-models-overrides-blob for dynamic parameter overrides
        $overridesBlob = $response['device-models-overrides-blob'] ?? null;
        $overrides = $this->parseOverridesBlob($overridesBlob);

        return [
            'domain' => $response['domain'] ?? null,
            'user' => $response['user'] ?? $response['extension'] ?? null,
            'device' => $response['device'] ?? $response['device_id'] ?? null,
            'brand' => $brand,
            'model' => $model,
            'brand_and_model' => $brandAndModel,
            'provisioning_username' => $response['device-provisioning-username'] ??
                                      $response['provisioning-username'] ??
                                      $response['provisioning_username'] ?? null,
            'provisioning_password' => $response['device-provisioning-password'] ??
                                      $response['provisioning-password'] ??
                                      $response['provisioning_password'] ?? null,
            'global_one_time_pass' => $response['global-one-time-pass'] ?? 'no',
            'overrides' => $overrides,
            'raw' => $response,
        ];
    }

    /**
     * Get device information including SIP credentials for all configured lines
     * This queries the device endpoint for each extension to get individual passwords
     *
     * @param string $domain Domain name
     * @param array $phoneInfo Phone info from getPhoneInfo() with line configuration
     * @param string|null $provisioningUsername Provisioning username (existing or newly generated)
     * @param string|null $provisioningPassword Provisioning password (existing or newly generated)
     * @return array|null Device info with SIP credentials or null if not found
     */
    public function getDeviceInfo(string $domain, array $phoneInfo = [], ?string $provisioningUsername = null, ?string $provisioningPassword = null): ?array
    {
        if (empty($phoneInfo['raw'])) {
            $this->logger->error("getDeviceInfo requires phoneInfo from getPhoneInfo()");
            return null;
        }

        $provisioningData = $phoneInfo['raw'];

        // SIP server is the domain name
        $sipServer = $domain;

        // Get outbound proxy and ports by querying the registration server
        $outboundProxy = null;
        $tcpPort = null;
        $tlsPort = null;
        $registrationServer = $provisioningData['device-provisioning-registration-core-server'] ?? null;
        if ($registrationServer) {
            $this->logger->debug("Fetching server info from registration server: $registrationServer");
            $serverInfo = $this->getServerInfo($registrationServer);
            if ($serverInfo) {
                // Use the FQDN as the outbound proxy
                $outboundProxy = $serverInfo['device-provisioning-core-server-postfix-fqdn'] ?? null;
                $tcpPort = $serverInfo['device-provisioning-core-server-tcp-port'] ?? null;
                $tlsPort = $serverInfo['device-provisioning-core-server-tls-port'] ?? null;

                $this->logger->debug("Retrieved server info - Proxy: " . ($outboundProxy ?? 'null') .
                                    ", TCP: " . ($tcpPort ?? 'null') .
                                    ", TLS: " . ($tlsPort ?? 'null'));
            } else {
                $this->logger->warning("Failed to retrieve server info for: $registrationServer");
            }
        }

        // Parse lines (device-provisioning-sip-uri-X with corresponding device-provisioning-line-X-enabled)
        $lines = [];
        $buttonCount = $provisioningData['device-models-count-buttons'] ?? 48;

        for ($i = 1; $i <= $buttonCount; $i++) {
            // Lines 1-24 use device-provisioning-sip-uri-X, lines 25-48 use deviceX
            $sipUriKey = ($i <= 24) ? "device-provisioning-sip-uri-{$i}" : "device{$i}";
            $enableKey = "line{$i}_enable";

            $sipUri = $provisioningData[$sipUriKey] ?? null;
            $enabled = ($provisioningData[$enableKey] ?? 'no') === 'yes';
            $provisioningUri = $sipUri;

            // Parse SIP URI to extract username (e.g., "sip:1004@domain" -> "1004")
            $username = null;
            $extension = null;
            $password = null;

            if ($sipUri && $sipUri !== 'n/a' && preg_match('/^sip:([^@]+)@/', $sipUri, $matches)) {
                $username = $matches[1];
                $extension = $matches[1];

                // Query devices list endpoint for this extension's credentials
                $this->logger->debug("Fetching SIP registration data for line $i");

                $devicesEndpoint = "/domains/$domain/users/$extension/devices";
                $devicesResponse = $this->makeRequest('GET', $devicesEndpoint);

                // Find the device matching this extension and extract password
                if ($devicesResponse && is_array($devicesResponse)) {
                    foreach ($devicesResponse as $device) {
                        if (isset($device['device']) && $device['device'] === $extension) {
                            $password = $device['device-sip-registration-password'] ?? null;
                            if ($password) {
                                $this->logger->debug("Retrieved SIP registration data for line $i");
                            }
                            break;
                        }
                    }
                }

                if (!$password) {
                    $this->logger->warning("Failed to retrieve SIP registration data for line $i");
                }
            }

            $lines[] = [
                'line_number' => $i,
                'enabled' => $enabled,
                'sip_uri' => $sipUri,
                'username' => $username,
                'extension' => $extension,
                'auth_id' => $username,
                'password' => $password,
                'provisioning_uri' => $provisioningUri !== 'n/a' ? $provisioningUri : null,
            ];
        }

        $transport = $provisioningData['device-provisioning-sip-transport-protocol'] ?? 'udp';
        $provisioningServer = $provisioningData['ndphostname'] ??
                              $provisioningData['device-provisioning-ndp-hostname'] ?? null;

        return [
            'sip_server' => $sipServer,
            'outbound_proxy' => $outboundProxy,
            'transport' => $transport,
            'tcp_port' => $tcpPort,
            'tls_port' => $tlsPort,
            'provisioning_username' => $provisioningUsername,
            'provisioning_password' => $provisioningPassword,
            'provisioning_server' => $provisioningServer,
            'ndp_hostname' => $provisioningServer,  // Keep for backward compatibility
            'sip_transport_protocol' => $transport,  // Alias for template compatibility
            'lines' => $lines,
            'button_count' => $buttonCount,
            'raw' => $provisioningData,
        ];
    }

    /**
     * Get server information from ns-api
     *
     * @param string $serverName Server name (e.g., "server-01")
     * @return array|null Server info or null if not found
     */
    public function getServerInfo(string $serverName): ?array
    {
        $endpoint = "/phones/servers/" . urlencode($serverName);
        $response = $this->makeRequest('GET', $endpoint);

        if (!$response) {
            return null;
        }

        return $response;
    }

    /**
     * Get model defaults from ns-api
     * Retrieves default parameter overrides for a specific device model
     *
     * @param string $brand Device brand (e.g., "Grandstream")
     * @param string $model Device model (e.g., "GXW-4248")
     * @return array Associative array of parameter => value pairs (empty if not found)
     */
    public function getModelDefaults(?string $brand, ?string $model): array
    {
        if (empty($brand) || empty($model)) {
            $this->logger->debug("Cannot fetch model defaults: brand or model is empty");
            return [];
        }

        $endpoint = "/phones/models?" . http_build_query([
            'brand' => $brand,
            'model' => $model,
        ]);

        $this->logger->debug("Fetching model defaults for brand=$brand, model=$model");
        $response = $this->makeRequest('GET', $endpoint);

        if (!$response) {
            $this->logger->warning("Failed to retrieve model defaults for brand=$brand, model=$model");
            return [];
        }

        // Parse device-models-overrides-blob for default parameter values
        $overridesBlob = $response['device-models-overrides-blob'] ?? null;
        $defaults = $this->parseOverridesBlob($overridesBlob);

        if (!empty($defaults)) {
            $this->logger->debug("Retrieved " . count($defaults) . " model defaults for brand=$brand, model=$model");
        }

        return $defaults;
    }

    /**
     * Make HTTP request to ns-api
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint API endpoint (with leading slash)
     * @param array|null $data Request body data (for POST/PUT)
     * @return array|null Response data or null on failure
     */
    private function makeRequest(string $method, string $endpoint, ?array $data = null): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $this->logger->debug("ns-api request: $method $url");

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        // Enable SSL certificate verification for security
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        // Set headers
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        // Add API key authentication
        if (!empty($this->config['api_key'])) {
            $headers[] = 'Authorization: Bearer ' . $this->config['api_key'];
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Add request body for POST/PUT
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error("ns-api request failed: $error");
            return null;
        }

        if ($httpCode >= 400) {
            $this->logger->error("ns-api returned error: HTTP $httpCode - Response: $response");
            return null;
        }

        $this->logger->debug("ns-api response: HTTP $httpCode");

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Failed to decode ns-api response: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    /**
     * Update global-one-time-pass field for a device
     * When setting to 'no', uses provided credentials or generates new random ones
     *
     * @param string $mac MAC address (12 hex characters)
     * @param string $value New value ('yes' or 'no')
     * @param string|null $username Optional username to set (if null, generates random)
     * @param string|null $password Optional password to set (if null, generates random)
     * @param string|null $brandAndModel Combined brand and model (e.g., "Grandstream GXW-4216")
     * @param string|null $domain Device domain (required to maintain domain assignment)
     * @return bool Success or failure
     */
    public function updateGlobalOneTimePass(string $mac, string $value, ?string $username = null, ?string $password = null, ?string $brandAndModel = null, ?string $domain = null): bool
    {
        $endpoint = "/phones";
        $data = [
            'mac' => strtoupper($mac),
            'model' => $brandAndModel,
            'domain' => $domain,
            'global-one-time-pass' => $value,
        ];

        // When disabling one-time pass, use provided credentials or generate random ones
        if ($value === 'no') {
            // Use provided credentials if available, otherwise generate new ones
            if ($username === null || $password === null) {
                $username = $this->generateRandomString(12);
                $password = $this->generateRandomString(30);
                $this->logger->debug("Generated random provisioning parameters for $mac");
            } else {
                $this->logger->debug("Using provided provisioning parameters for $mac");
            }

            $data['device-provisioning-username'] = $username;
            $data['device-provisioning-password'] = $password;
        }

        $this->logger->debug("Updating global-one-time-pass for $mac to: $value");

        $response = $this->makeRequest('PUT', $endpoint, $data);

        if ($response) {
            if ($value === 'no') {
                $this->logger->info("Successfully updated global-one-time-pass and provisioning parameters for $mac");
            } else {
                $this->logger->info("Successfully updated global-one-time-pass for $mac");
            }
            return true;
        } else {
            $this->logger->error("Failed to update global-one-time-pass for $mac");
            return false;
        }
    }

    /**
     * Ensure provisioning credentials exist for a device
     * Generates and sets credentials if they don't exist
     *
     * @param string $mac MAC address (12 hex characters)
     * @param string|null $existingUsername Existing username from phone info
     * @param string|null $existingPassword Existing password from phone info
     * @param string|null $brandAndModel Combined brand and model (e.g., "Grandstream GXW-4216")
     * @param string|null $domain Device domain (required to maintain domain assignment)
     * @return array Array with 'username' and 'password' keys (existing or newly generated)
     */
    public function ensureProvisioningCredentials(string $mac, ?string $existingUsername, ?string $existingPassword, ?string $brandAndModel = null, ?string $domain = null): array
    {
        // If credentials already exist, return them
        if ($existingUsername && $existingPassword) {
            $this->logger->debug("Provisioning credentials already exist for $mac");
            return [
                'username' => $existingUsername,
                'password' => $existingPassword,
            ];
        }

        // Generate new credentials
        $username = $this->generateRandomString(12);
        $password = $this->generateRandomString(30);

        $this->logger->info("Generating new provisioning credentials for $mac");

        // Update ns-api with new credentials
        $endpoint = "/phones";
        $data = [
            'mac' => strtoupper($mac),
            'model' => $brandAndModel,
            'domain' => $domain,
            'device-provisioning-username' => $username,
            'device-provisioning-password' => $password,
        ];

        $response = $this->makeRequest('PUT', $endpoint, $data);

        if ($response) {
            $this->logger->info("Successfully set new provisioning credentials for $mac");
            return [
                'username' => $username,
                'password' => $password,
            ];
        } else {
            $this->logger->error("Failed to set provisioning credentials for $mac");
            // Return generated credentials anyway so config generation can proceed
            return [
                'username' => $username,
                'password' => $password,
            ];
        }
    }

    /**
     * Generate a cryptographically secure random string
     * Uses special characters for higher entropy
     *
     * @param int $length Length of the string to generate
     * @return string Random string containing alphanumeric and special characters
     */
    private function generateRandomString(int $length): string
    {
        // Include special characters for higher entropy
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Parse device-models-overrides-blob field
     * Extracts parameter=value pairs from the blob string
     * Example input: P2917="https://example.com/logo.jpg" P2916="1"
     *
     * @param string|null $blob The overrides blob from ns-api
     * @return array Associative array of parameter => value pairs
     */
    public function parseOverridesBlob(?string $blob): array
    {
        if (empty($blob)) {
            return [];
        }

        $overrides = [];

        // Match pattern: PARAMETER="VALUE" or PARAMETER='VALUE' or PARAMETER=VALUE
        // Use double quotes for PHP string to properly escape single quote in regex
        preg_match_all("/(\w+)=(?:\"([^\"]*)\"|'([^']*)'|(\S+))/", $blob, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $parameter = $match[1];
            // Value can be in different capture groups depending on quote type
            // Check which group actually captured the value
            if (array_key_exists(2, $match) && $match[2] !== '') {
                $value = $match[2];  // Double-quoted value
            } elseif (array_key_exists(3, $match) && $match[3] !== '') {
                $value = $match[3];  // Single-quoted value
            } elseif (array_key_exists(4, $match) && $match[4] !== '') {
                $value = $match[4];  // Unquoted value
            } else {
                $value = '';  // Empty value (e.g., P123="")
            }
            $overrides[$parameter] = $value;
        }

        $this->logger->debug("Parsed " . count($overrides) . " parameter overrides from blob");

        return $overrides;
    }
}
