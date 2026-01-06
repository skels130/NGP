<?php
/**
 * Configuration file for NGP
 * Copy this file to config.php and update with your settings
 */

return [
    // HTTP Basic Authentication
    'auth' => [
        'enabled' => true,
        'mode' => 'dynamic',  // 'static' - always use global password (ignores global-one-time-pass)
                               // 'dynamic' - use global-one-time-pass field to determine auth method

        // Global one-time password (used for initial device provisioning)
        // In dynamic mode:
        //   - If global-one-time-pass=yes: this password is required
        //   - If global-one-time-pass=no: device-specific credentials are required
        //   - After first successful auth with global password, it is auto-disabled
        // In static mode:
        //   - This password is always required (global-one-time-pass is ignored)
        'username' => 'admin',
        'password' => 'changeme',  // Change this!
    ],

    // ns-api Configuration
    'nsapi' => [
        'base_url' => 'https://api.example.com',
        'api_key' => '',  // Your ns-api key
        'timeout' => 10,  // Request timeout in seconds
    ],

    // Template Configuration
    'templates' => [
        // Base path for template directory structure
        // Templates are organized as: {base_path}/{brand}/{model}/{template_filename}
        // Example: templates/grandstream/gxw-4248/config.xml
        'base_path' => __DIR__ . '/../templates',

        // Template filename (same name used in all model directories)
        'template_filename' => 'config.xml',

        // Template directory structure:
        // templates/{brand}/{model}/config.xml         - Specific model template
        // templates/{brand}/default/config.xml         - Brand fallback (optional)
        //
        // Example structure:
        // templates/grandstream/gxw-4216/config.xml
        // templates/grandstream/gxw-4224/config.xml
        // templates/grandstream/gxw-4248/config.xml
        // templates/grandstream/default/config.xml     - Optional fallback for any Grandstream
        // templates/yealink/t46s/config.xml
        //
        // Note: If no template is found, a 404 error will be returned
    ],

    // Trusted Proxies (for X-Forwarded-For support)
    // When behind a proxy (like NetSapiens NDP), only trust X-Forwarded-For from these IPs
    'trusted_proxies' => [
        // Add your NDP server IPs here
        // '192.168.1.100',
        // '10.0.0.50',
    ],

    // Logging
    'logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/../logs/ngp.log',
        'level' => 'info',  // debug, info, warning, error
    ],

    // Cache (optional - for future use)
    'cache' => [
        'enabled' => false,
        'ttl' => 300,  // Time to live in seconds
    ],
];
