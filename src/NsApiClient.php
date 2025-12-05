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

        return [
            'domain' => $response['domain'] ?? null,
            'user' => $response['user'] ?? $response['extension'] ?? null,
            'device' => $response['device'] ?? $response['device_id'] ?? null,
            'brand' => $brand,
            'model' => $model,
            'provisioning_username' => $response['device-provisioning-username'] ??
                                      $response['provisioning-username'] ??
                                      $response['provisioning_username'] ?? null,
            'provisioning_password' => $response['device-provisioning-password'] ??
                                      $response['provisioning-password'] ??
                                      $response['provisioning_password'] ?? null,
            'global_one_time_pass' => $response['global-one-time-pass'] ?? 'no',
            'raw' => $response,
        ];
    }

    /**
     * Get device information including SIP credentials for all configured lines
     * This queries the device endpoint for each extension to get individual passwords
     *
     * @param string $domain Domain name
     * @param array $phoneInfo Phone info from getPhoneInfo() with line configuration
     * @return array|null Device info with SIP credentials or null if not found
     */
    public function getDeviceInfo(string $domain, array $phoneInfo = []): ?array
    {
        if (empty($phoneInfo['raw'])) {
            $this->logger->error("getDeviceInfo requires phoneInfo from getPhoneInfo()");
            return null;
        }

        $provisioningData = $phoneInfo['raw'];

        // Extract SIP server/hostname
        $sipServer = $provisioningData['device-provisioning-ndp-hostname'] ?? null;

        // Parse lines (device-provisioning-sip-uri-X with corresponding device-provisioning-line-X-enabled)
        $lines = [];
        $buttonCount = $provisioningData['device-models-count-buttons'] ?? 48;

        for ($i = 1; $i <= $buttonCount; $i++) {
            $sipUriKey = "device-provisioning-sip-uri-{$i}";
            $enableKey = "device-provisioning-line-{$i}-enabled";

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

        return [
            'sip_server' => $sipServer,
            'outbound_proxy' => null,
            'transport' => $transport,
            'ndp_hostname' => $sipServer,  // Alias for template compatibility
            'sip_transport_protocol' => $transport,  // Alias for template compatibility
            'lines' => $lines,
            'button_count' => $buttonCount,
            'raw' => $provisioningData,
        ];
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
            $this->logger->error("ns-api returned error: HTTP $httpCode");
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
     * When setting to 'no', also generates and sets random provisioning credentials
     *
     * @param string $mac MAC address (12 hex characters)
     * @param string $value New value ('yes' or 'no')
     * @return bool Success or failure
     */
    public function updateGlobalOneTimePass(string $mac, string $value): bool
    {
        $endpoint = "/phones/" . strtoupper($mac);
        $data = ['global-one-time-pass' => $value];

        // When disabling one-time pass, generate random credentials
        if ($value === 'no') {
            $username = $this->generateRandomString(12);
            $password = $this->generateRandomString(30);

            $data['device-provisioning-username'] = $username;
            $data['device-provisioning-password'] = $password;

            $this->logger->debug("Generated random provisioning parameters for $mac");
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
     * Generate a cryptographically secure random string
     * Uses special characters for higher entropy
     *
     * @param int $length Length of the string to generate
     * @return string Random string containing alphanumeric and special characters
     */
    private function generateRandomString(int $length): string
    {
        // Include special characters for higher entropy
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()-_=+[]{}|;:,.<>?';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }
}
