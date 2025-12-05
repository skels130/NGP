# Config_Gen

Multi-vendor configuration generator for IP telephony devices. This web server dynamically generates device configuration files by querying the ns-api for SIP credentials and applying them to customizable templates.

## Features

- **Multi-Vendor Support**: Automatically selects appropriate template based on device brand and model
- **Dynamic Authentication**: Validates devices using provisioning credentials from ns-api
- **Dynamic Configuration**: Generates configs based on MAC address and ns-api data
- **Intelligent Template Selection**:
  - Exact brand/model matching
  - Wildcard pattern matching (e.g., `gxw42*` matches all GXW4200 models)
  - Brand-level fallbacks
- **Custom Template Parser**: Variables, conditionals, and loops
- **Flexible Authentication Modes**: Static, dynamic, or hybrid
- **Comprehensive Logging**: Debug, info, warning, and error levels

## Requirements

- PHP 7.4 or higher
- Apache or Nginx web server
- curl extension enabled
- ns-api credentials

## Architecture

Config_Gen is designed to work with NetSapiens NDP (Network Device Provisioning) server:

```
Gateway → NetSapiens NDP Server (/gateway/) → Config_Gen PHP Server
```

Gateways request configs from the NDP server at `/gateway/{MAC}.cfg`, and the NDP server proxies these requests to your Config_Gen server. See **DEPLOYMENT.md** for complete proxy setup instructions.

## Installation

1. **Clone or copy the project to your web server**
   ```bash
   cd /var/www/
   cp -r /path/to/Config_Gen ./config-gen
   ```

2. **Set up configuration**
   ```bash
   cd config-gen
   cp config/config.example.php config/config.php
   ```

3. **Edit configuration file**
   Edit `config/config.php` and update:
   - HTTP authentication credentials
   - ns-api base URL and API key
   - Template path (if different)
   - Logging preferences

4. **Set permissions**
   ```bash
   chmod 755 public
   mkdir -p logs
   chmod 775 logs
   chown www-data:www-data logs  # Adjust user/group for your system
   ```

5. **Configure web server**

   **Apache:**
   - Ensure mod_rewrite is enabled: `a2enmod rewrite`
   - Point DocumentRoot to `public/` directory
   - Ensure .htaccess is allowed (AllowOverride All)

   **Nginx:**
   Add this location block to your server configuration:
   ```nginx
   location ~ ^/([A-Fa-f0-9]{12})\.cfg$ {
       try_files $uri /index.php$is_args$args;
   }
   ```

## Configuration

### config/config.php

```php
return [
    'auth' => [
        'enabled' => true,
        'mode' => 'dynamic',  // 'static', 'dynamic', or 'both'
        'username' => 'admin',
        'password' => 'your-secure-password',
    ],
    'nsapi' => [
        'base_url' => 'https://api.netspectrum.com',
        'api_key' => 'your-api-key',
        'timeout' => 10,
    ],
    'templates' => [
        'base_path' => __DIR__ . '/../templates',
        'template_filename' => 'config.xml',
    ],
    'logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/../logs/config_gen.log',
        'level' => 'info',
    ],
];
```

### Authentication Modes

**Dynamic Mode** (Recommended):
- Devices authenticate using provisioning credentials from ns-api
- Credentials are retrieved from `/phones/{mac}` API response
- No need to configure per-device passwords

**Static Mode**:
- All devices use the same username/password from config file
- Traditional authentication method

**Both Mode**:
- Tries dynamic authentication first
- Falls back to static credentials if device has no provisioning creds
- Most flexible option

### Template Organization

Templates are organized in a directory hierarchy:

```
templates/
├── grandstream/
│   ├── gxw-4216/
│   │   └── config.xml
│   ├── gxw-4224/
│   │   └── config.xml
│   ├── gxw-4248/
│   │   └── config.xml
│   └── default/          (optional brand fallback)
│       └── config.xml
├── yealink/
│   ├── t46s/
│   │   └── config.xml
│   └── default/
│       └── config.xml
```

**Selection Logic:**
1. Try `templates/{brand}/{model}/config.xml` - Exact match
2. Try `templates/{brand}/default/config.xml` - Brand fallback (optional)
3. Return 404 if no template found

**Path Normalization:**
- Brand/model names are normalized for filesystem compatibility
- Converted to lowercase, spaces → hyphens, special characters removed
- Example: "GXW-4248" → "gxw-4248"

**Adding New Device Support:**
```bash
# Create directory for new model
mkdir -p templates/yealink/t48s

# Copy existing template as starting point
cp templates/grandstream/gxw-4216/config.xml templates/yealink/t48s/config.xml

# Edit template for the new device
nano templates/yealink/t48s/config.xml
```

No code changes needed - just create the directory structure!

## Usage

### Requesting Configuration Files

In a NetSapiens deployment, devices request configurations from the NDP server:
```
http://ndp-server.netspectrum.com/gateway/{MAC}.cfg
```

The NDP server proxies this to Config_Gen:
```
https://config.example.com/gateway/{MAC}.cfg
or
https://config.example.com/{MAC}.cfg (if prefix is stripped)
```

Example gateway request:
```
http://ndp-server.netspectrum.com/gateway/C074AD7C6934.cfg
```

For direct testing (bypassing NDP):
```
https://config.example.com/C074AD7C6934.cfg
```

The server will:
1. Extract MAC address from request
2. Query ns-api for device information (brand, model, provisioning credentials)
3. **Authenticate** using device provisioning credentials (dynamic mode)
4. Query ns-api for SIP credentials
5. **Select template** based on brand and model
6. Parse template with device variables
7. Return generated XML configuration

### Device Configuration

Configure your devices to:
1. Use HTTP/HTTPS provisioning
2. Set provisioning server to: `http://your-server.com`
3. Set provisioning path to: `{MAC}.cfg`
4. Use HTTP Basic Authentication with provisioning credentials from ns-api

### Template Syntax

The template parser supports the following syntax:

#### Variable Substitution
```xml
<P47>{{device_info.sip_server}}</P47>
<P4060>{{device_info.username}}</P4060>
```

#### Conditionals
```xml
{{if device_info.outbound_proxy}}
<P48>{{device_info.outbound_proxy}}</P48>
{{else}}
<P48></P48>
{{endif}}
```

#### Loops
```xml
{{for port in 1..48}}
<!-- FXS Port {{port}} -->
<P4060>{{device_info.username}}</P4060>
{{endfor}}
```

## API Flow

1. **GET /phones/{mac}**
   - Returns: domain, user, device identifier, brand, model
   - Returns: device-provisioning-username, device-provisioning-password
   - Returns: SIP URIs for all lines (device1-device48)
   - Returns: Line enable status (line1_enable-line48_enable)

2. **GET /domains/{domain}/users/{extension}/devices/{extension}x** (called for each configured line)
   - Returns: SIP registration password for that specific extension
   - Called once per configured line (up to 48 times for fully configured device)
   - Each line receives its own unique password

## Project Structure

```
Config_Gen/
├── config/
│   ├── config.example.php    # Example configuration
│   └── config.php             # Your configuration (create from example)
├── logs/
│   └── config_gen.log         # Application logs
├── public/
│   ├── .htaccess              # Apache rewrite rules
│   └── index.php              # Application entry point
├── src/
│   ├── Auth.php               # HTTP Basic Authentication (static + dynamic)
│   ├── Logger.php             # Logging functionality
│   ├── NsApiClient.php        # ns-api integration
│   ├── TemplateParser.php     # Template parsing engine
│   └── TemplateSelector.php   # Brand/model template selection
├── CLAUDE.md                  # Claude Code documentation
└── README.md                  # This file
```

## Logging

Logs are written to the path specified in configuration (default: `logs/config_gen.log`).

Log levels:
- **debug**: Detailed information for debugging
- **info**: General information about requests
- **warning**: Warning messages
- **error**: Error messages

View logs:
```bash
tail -f logs/config_gen.log
```

## Troubleshooting

### 404 Not Found
- Check that mod_rewrite is enabled (Apache)
- Verify .htaccess is being read (check AllowOverride)
- Ensure MAC address format is correct (12 hex characters)

### 401 Unauthorized
- Verify HTTP Basic Auth credentials in config.php
- Check that authentication is enabled

### 500 Internal Server Error
- Check PHP error logs
- Verify file permissions
- Check logs/config_gen.log for application errors

### Empty or Invalid Configuration
- Verify ns-api credentials
- Check that MAC address exists in ns-api
- Review logs for API errors
- Verify template path in configuration

## Security Considerations

- Always use HTTPS in production (uncomment HTTPS redirect in .htaccess)
- Use strong HTTP Basic Auth credentials
- Restrict access to config.php and sensitive files
- Regularly rotate API keys
- Monitor logs for suspicious activity
- Keep PHP and dependencies updated

## Development

To modify the template parser or add features:

1. **Test locally** with a development server:
   ```bash
   cd public
   php -S localhost:8000
   ```

2. **Enable debug logging** in config.php:
   ```php
   'logging' => [
       'level' => 'debug',
   ]
   ```

3. **Monitor logs** during testing:
   ```bash
   tail -f logs/config_gen.log
   ```

## Production Deployment

For production deployment with NetSapiens NDP proxy configuration, see **DEPLOYMENT.md** which covers:
- NetSapiens NDP server proxy setup (Apache/Nginx)
- Security hardening and firewall configuration
- Production checklist and verification steps
- Troubleshooting common deployment issues

## License

Internal use only.
