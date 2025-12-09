<?php
/**
 * NGP - NetSapiens Gateway Provisioning
 * Entry point for configuration requests
 */

// Production error handling - log errors but don't display them
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

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

// Match pattern with optional gateway and cfg prefixes:
// /{mac}.cfg, /cfg/{mac}.cfg, /cfg{mac}.cfg
// /gateway/{mac}.cfg, /gateway/cfg/{mac}.cfg, /gateway/cfg{mac}.cfg
// Both .cfg and .xml extensions are supported
if (preg_match('/^\/(?:gateway\/)?(?:cfg\/|cfg)?([A-Fa-f0-9]{12})\.(cfg|xml)$/', $path, $matches)) {
    $macAddress = strtoupper($matches[1]);
    $fileExtension = $matches[2];

    // Input validation - MAC address length
    if (strlen($macAddress) !== 12) {
        http_response_code(400);
        exit;
    }

    try {
        // Initialize logger
        $logger = new Logger($config['logging']);
        $logger->info("Config request received for MAC: $macAddress");

        // Initialize rate limiter
        $rateLimiter = new RateLimiter($logger, __DIR__ . '/../logs/ratelimit');

        // Check rate limiting
        if ($rateLimiter->isRateLimited()) {
            $logger->error("Rate limit exceeded for IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            http_response_code(429);
            header('Content-Type: text/plain');
            header('Retry-After: 300');
            echo "Too many requests";
            exit;
        }

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
        $globalOneTimePass = $phoneInfo['global_one_time_pass'] ?? 'no';

        // Input validation - prevent excessively long values
        if (strlen($brand ?? '') > 100 || strlen($model ?? '') > 100) {
            $logger->error("Brand or model name too long: brand=$brand, model=$model");
            http_response_code(400);
            exit;
        }

        // Ensure provisioning credentials exist (generate new ones if needed)
        // This makes credentials available for both authentication and template variables
        $provisioningCreds = $nsapi->ensureProvisioningCredentials(
            $macAddress,
            $provisioningUsername,
            $provisioningPassword
        );
        $provisioningUsername = $provisioningCreds['username'];
        $provisioningPassword = $provisioningCreds['password'];

        $logger->debug("Found device: domain=$domain, user=$user, device=$device, brand=$brand, model=$model, global-one-time-pass=$globalOneTimePass");

        // HTTP Authentication
        // Mode 'dynamic': Use device-specific provisioning credentials from ns-api
        // Mode 'static': Use global one-time password from config.php
        // Mode 'both': Try dynamic first, fall back to global one-time password
        $auth = new Auth($config['auth']);
        $authMode = $config['auth']['mode'] ?? 'dynamic';

        $authenticated = false;
        $usedOneTimePass = false;

        if ($authMode === 'dynamic' || $authMode === 'both') {
            // Try device-specific provisioning credentials from ns-api
            if ($provisioningUsername && $provisioningPassword) {
                if ($auth->validateCredentials($provisioningUsername, $provisioningPassword)) {
                    $logger->debug("Authentication successful (device credentials)");
                    $authenticated = true;
                } else {
                    $logger->warning("Authentication validation failed (device credentials)");
                }
            }
        }

        if (!$authenticated && ($authMode === 'static' || $authMode === 'both')) {
            // Try global one-time password from config.php
            // Only allowed if device has global-one-time-pass=yes in ns-api
            if ($globalOneTimePass === 'yes') {
                if ($config['auth']['enabled'] && $auth->authenticate()) {
                    $logger->debug("Authentication successful (global one-time password)");
                    $authenticated = true;
                    $usedOneTimePass = true;
                }
            } else {
                $logger->debug("Global one-time password not available for this device");
            }
        }

        if (!$authenticated) {
            $logger->error("Authentication failed for MAC: $macAddress");
            $rateLimiter->recordFailedAttempt();
            $auth->requireAuth();
            exit;
        }

        // Clear rate limit on successful authentication
        $rateLimiter->recordSuccess();

        // Get device configuration (SIP info) for all lines
        // This will query ns-api for each configured extension to get registration data
        $logger->info("Fetching SIP configuration for all lines in domain: $domain");
        $deviceInfo = $nsapi->getDeviceInfo($domain, $phoneInfo, $provisioningUsername, $provisioningPassword);

        if (!$deviceInfo) {
            $logger->error("Failed to retrieve device configuration for domain: $domain");
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Failed to retrieve device configuration";
            exit;
        }

        // Count how many lines have registration data
        $linesWithPasswords = array_filter($deviceInfo['lines'], function($line) {
            return !empty($line['password']);
        });
        $logger->info("Retrieved registration data for " . count($linesWithPasswords) . " out of " .
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

        // Merge parameter overrides from device-models-overrides-blob as top-level variables
        // This allows templates to use {{P2917}} directly instead of {{phone_info.overrides.P2917}}
        if (!empty($phoneInfo['overrides'])) {
            $logger->debug("Merging " . count($phoneInfo['overrides']) . " parameter overrides into template variables");
            $variables = array_merge($variables, $phoneInfo['overrides']);
        }

        // Generate configuration
        $logger->debug("Generating configuration");
        $configXml = $templateParser->parse($variables);

        // Send response
        $logger->info("Configuration generated successfully for MAC: $macAddress");
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $macAddress . '.' . $fileExtension . '"');
        echo $configXml;

        // If we used the global one-time password, disable it and set device-specific credentials
        // This ensures the device can only use the one-time password once for initial provisioning
        // Pass the credentials that were already generated and sent in the config to avoid mismatch
        if ($usedOneTimePass) {
            $logger->info("Disabling global one-time password and setting device-specific credentials for MAC: $macAddress");
            $nsapi->updateGlobalOneTimePass($macAddress, 'no', $provisioningUsername, $provisioningPassword);
        }

    } catch (Exception $e) {
        $logger->error("Error generating config: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "Internal server error";
    }

} else {
    // Invalid request - return 404 without explanation
    http_response_code(404);
    exit;
}
