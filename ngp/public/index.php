<?php
/**
 * NGP - NetSapiens Gateway Provisioning
 * Entry point for configuration requests
 */

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Autoload classes
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Parse the request URI to extract MAC address
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Match pattern: /{mac}.cfg or /cfg/{mac}.cfg
if (preg_match('/\/([A-Fa-f0-9]{12})\.cfg$/', $path, $matches)) {
    $macAddress = strtoupper($matches[1]);

    try {
        // Initialize logger
        $logger = new Logger($config['logging']);
        $logger->info("Config request received for MAC: $macAddress");

        // Initialize ns-api client
        $nsapi = new NsApiClient($config['nsapi'], $logger);

        // Get device information from ns-api (including provisioning credentials)
        $logger->debug("Querying ns-api for MAC: $macAddress");
        $phoneInfo = $nsapi->getPhoneInfo($macAddress);

        if (!$phoneInfo) {
            $logger->error("MAC address not found in ns-api: $macAddress");
            http_response_code(404);
            header('Content-Type: text/plain');
            echo "Device not found";
            exit;
        }

        $domain = $phoneInfo['domain'];
        $user = $phoneInfo['user'];
        $device = $phoneInfo['device'];
        $brand = $phoneInfo['brand'];
        $model = $phoneInfo['model'];
        $provisioningUsername = $phoneInfo['provisioning_username'];
        $provisioningPassword = $phoneInfo['provisioning_password'];

        $logger->debug("Found device: domain=$domain, user=$user, device=$device, brand=$brand, model=$model");

        // HTTP Authentication - use dynamic credentials if available, otherwise fall back to config
        $auth = new Auth($config['auth']);
        $authMode = $config['auth']['mode'] ?? 'dynamic'; // 'static', 'dynamic', or 'both'

        $authenticated = false;

        if ($authMode === 'dynamic' || $authMode === 'both') {
            // Validate against device provisioning credentials
            if ($provisioningUsername && $provisioningPassword) {
                if ($auth->validateCredentials($provisioningUsername, $provisioningPassword)) {
                    $logger->debug("Authenticated using device provisioning credentials");
                    $authenticated = true;
                } else {
                    $logger->warning("Device provisioning credentials validation failed");
                }
            }
        }

        if (!$authenticated && ($authMode === 'static' || $authMode === 'both')) {
            // Fall back to static config credentials
            if ($config['auth']['enabled'] && $auth->authenticate()) {
                $logger->debug("Authenticated using static config credentials");
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            $logger->error("Authentication failed for MAC: $macAddress");
            $auth->requireAuth();
            exit;
        }

        // Get device credentials (SIP info) for all lines
        // This will query ns-api for each configured extension to get individual passwords
        $logger->info("Fetching SIP credentials for all configured lines in domain: $domain");
        $deviceInfo = $nsapi->getDeviceInfo($domain, $phoneInfo);

        if (!$deviceInfo) {
            $logger->error("Failed to retrieve device credentials for domain: $domain");
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Failed to retrieve device credentials";
            exit;
        }

        // Count how many lines have passwords
        $linesWithPasswords = array_filter($deviceInfo['lines'], function($line) {
            return !empty($line['password']);
        });
        $logger->info("Retrieved credentials for " . count($linesWithPasswords) . " out of " .
                      count($deviceInfo['lines']) . " lines");

        // Select appropriate template based on brand and model
        $templateSelector = new TemplateSelector($config['templates'], $logger);
        $templatePath = $templateSelector->selectTemplate($brand, $model);

        if (!$templatePath) {
            $logger->error("No template found for brand=$brand, model=$model");
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "No template available for this device model";
            exit;
        }

        $logger->info("Using template: $templatePath");

        // Load and parse template
        $templateParser = new TemplateParser($templatePath, $logger);

        // Prepare variables for template
        $variables = [
            'mac' => $macAddress,
            'domain' => $domain,
            'user' => $user,
            'device' => $device,
            'brand' => $brand,
            'model' => $model,
            'phone_info' => $phoneInfo,
            'device_info' => $deviceInfo,
        ];

        // Generate configuration
        $logger->debug("Generating configuration");
        $configXml = $templateParser->parse($variables);

        // Send response
        $logger->info("Configuration generated successfully for MAC: $macAddress");
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $macAddress . '.cfg"');
        echo $configXml;

    } catch (Exception $e) {
        $logger->error("Error generating config: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Internal server error";
    }

} else {
    // Invalid request
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "Not found. Expected format: /{MAC}.cfg where MAC is a 12-digit hex address.";
}
