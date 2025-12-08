<?php
/**
 * Configuration file for NGP
 * Copy this file to config.php and update with your settings
 */

return [
    // HTTP Basic Authentication
    'auth' => [
        'enabled' => true,
        'mode' => 'dynamic',  // 'static' - use global one-time password only
                               // 'dynamic' - use device provisioning credentials from API (recommended)
                               // 'both' - try dynamic first, fall back to global one-time password

        // Global one-time password (used for initial device provisioning)
        // This password is used when:
        // 1. Device has global-one-time-pass=yes in ns-api
        // 2. mode is 'static' or 'both'
        // After first successful auth, the one-time pass is disabled and
        // device-specific credentials are generated automatically
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
