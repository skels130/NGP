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
        return [
            'domain' => $response['domain'] ?? null,
            'user' => $response['user'] ?? $response['extension'] ?? null,
            'device' => $response['device'] ?? $response['device_id'] ?? null,
            'brand' => $response['brand'] ?? $response['make'] ?? $response['vendor'] ?? null,
            'model' => $response['model'] ?? null,
            'provisioning_username' => $response['device-provisioning-username'] ??
                                      $response['provisioning-username'] ??
                                      $response['provisioning_username'] ?? null,
            'provisioning_password' => $response['device-provisioning-password'] ??
                                      $response['provisioning-password'] ??
                                      $response['provisioning_password'] ?? null,
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
        $sipServer = $provisioningData['ndphostname'] ?? null;

        // Parse lines (device1-device48 with corresponding line{N}_enable)
        $lines = [];
        $buttonCount = $provisioningData['device-models-count-buttons'] ?? 48;

        for ($i = 1; $i <= $buttonCount; $i++) {
            $deviceKey = "device{$i}";
            $enableKey = "line{$i}_enable";
            $sipUriKey = "device-provisioning-sip-uri-{$i}";

            $sipUri = $provisioningData[$deviceKey] ?? null;
            $enabled = ($provisioningData[$enableKey] ?? 'no') === 'yes';
            $provisioningUri = $provisioningData[$sipUriKey] ?? 'n/a';

            // Parse SIP URI to extract username (e.g., "sip:1004@domain" -> "1004")
            $username = null;
            $extension = null;
            $password = null;

            if ($sipUri && $sipUri !== 'n/a' && preg_match('/^sip:([^@]+)@/', $sipUri, $matches)) {
                $username = $matches[1];
                $extension = $matches[1];

                // Query device endpoint for this specific extension's credentials
                // The device identifier follows pattern: extension + 'x' (e.g., "1004x")
                $deviceId = $extension . 'x';
                $this->logger->debug("Fetching credentials for line $i: $extension (device: $deviceId)");

                $deviceEndpoint = "/domains/$domain/users/$extension/devices/$deviceId";
                $deviceResponse = $this->makeRequest('GET', $deviceEndpoint);

                if ($deviceResponse && isset($deviceResponse['device-sip-registration-password'])) {
                    $password = $deviceResponse['device-sip-registration-password'];
                    $this->logger->debug("Retrieved password for line $i: $extension");
                } else {
                    $this->logger->warning("Failed to retrieve password for line $i: $extension");
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

        return [
            'sip_server' => $sipServer,
            'outbound_proxy' => null,
            'transport' => $provisioningData['device-provisioning-sip-transport-protocol'] ?? 'udp',
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
            $this->logger->error("ns-api returned error: HTTP $httpCode - $response");
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
}
