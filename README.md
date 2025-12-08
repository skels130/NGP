# NGP

Multi-vendor configuration generator for IP telephony devices. This web server dynamically generates device configuration files by querying the ns-api for SIP credentials and applying them to customizable templates.

## Features

- **Multi-Vendor Support**: Automatically selects appropriate template based on device brand and model
- **Dynamic Authentication**: Validates devices using provisioning credentials from ns-api
- **Dynamic Configuration**: Generates configs based on MAC address and ns-api data
- **Dynamic Variables**: Device-specific parameter overrides from ns-api `device-models-overrides-blob`
- **Intelligent Template Selection**:
  - Exact brand/model matching
  - Wildcard pattern matching (e.g., `gxw42*` matches all GXW4200 models)
  - Brand-level fallbacks
- **Custom Template Parser**: Variables, conditionals, and loops
- **Flexible Authentication Modes**: Static, dynamic, or hybrid
- **Comprehensive Logging**: Debug, info, warning, and error levels
- **Production-Ready Security**: Rate limiting, input validation, timing-safe auth, HTTPS enforcement
- **One-Time Password Support**: Bootstrap new devices with temporary static credentials

## Requirements

- PHP 7.4 or higher
- Apache or Nginx web server
- curl extension enabled
- ns-api credentials

## Security Features

NGP implements comprehensive security hardening:

- **XML Injection Protection**: All template variables are XML-escaped to prevent injection attacks
- **Path Traversal Prevention**: Template paths validated with `realpath()` to ensure files are within allowed directories
- **Timing-Safe Authentication**: Uses `hash_equals()` for credential comparison to prevent timing attacks
- **Rate Limiting**: 5 failed attempts per minute per IP, 5-minute lockout after threshold
- **HTTPS Enforcement**: Automatic HTTP→HTTPS redirect with HSTS headers
- **Security Headers**: CSP, X-Frame-Options, X-Content-Type-Options, XSS Protection, Referrer-Policy
- **Input Validation**: MAC address format validation, length limits on brand/model names
- **Secure Random Generation**: Cryptographically secure credential generation using `random_int()`
- **Sanitized Logging**: No passwords, credentials, or sensitive data logged
- **SSL Certificate Validation**: All ns-api requests validate SSL certificates

See `SECURITY_AUDIT_20251205.md` for complete security audit report.

## Architecture

NGP is designed to work with NetSapiens NDP (Network Device Provisioning) server:

```
Gateway → NetSapiens NDP Server (/gateway/) → NGP PHP Server
```

Gateways request configs from the NDP server at `/gateway/{MAC}.cfg`, and the NDP server proxies these requests to your NGP server. See **DEPLOYMENT.md** for complete proxy setup instructions.

## Installation

1. **Clone or copy the project to your web server**
   ```bash
   cd /var/www/
   cp -r /path/to/NGP ./config-gen
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
        'base_url' => 'https://api.example.com',
        'api_key' => 'your-api-key',
        'timeout' => 10,
    ],
    'templates' => [
        'base_path' => __DIR__ . '/../templates',
        'template_filename' => 'config.xml',
    ],
    'logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/../logs/ngp.log',
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
http://ndp-server.example.com/gateway/{MAC}.cfg
```

The NDP server proxies this to NGP:
```
https://config.example.com/gateway/{MAC}.cfg
or
https://config.example.com/{MAC}.cfg (if prefix is stripped)
```

Example gateway request:
```
http://ndp-server.example.com/gateway/C074AD7C6934.cfg
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

The template parser supports variables, conditionals, and loops:

#### Variable Substitution
```xml
<P47>{{device_info.sip_server}}</P47>
<P4060>{{device_info.lines.0.username}}</P4060>
<P2917>{{P2917}}</P2917>  <!-- Dynamic variable from overrides -->
```

#### Conditionals
```xml
{{if device_info.lines.0.enabled}}
<P4060>{{device_info.lines.0.username}}</P4060>
{{endif}}
```

#### Loops
```xml
{{for port in 1..48}}
<!-- FXS Port {{port}} -->
{{endfor}}
```

### Template Variables

**Standard Variables** (always available):
- `{{mac}}` - Device MAC address
- `{{domain}}`, `{{user}}`, `{{device}}` - Device identifiers
- `{{device_info.sip_server}}` - SIP server hostname
- `{{device_info.transport}}` - SIP transport (udp/tcp/tls)
- `{{device_info.lines.N.username}}` - Username for line N (0-47)
- `{{device_info.lines.N.password}}` - SIP password for line N
- `{{device_info.lines.N.auth_id}}` - Auth ID for line N
- `{{device_info.lines.N.extension}}` - Extension for line N
- `{{device_info.lines.N.enabled}}` - Line enabled status

**Dynamic Variables** (device-specific overrides):
Any parameter from `device-models-overrides-blob` in ns-api automatically becomes available.

Example ns-api response:
```
device-models-overrides-blob: P2917="https://example.com/logo.jpg" P2916="1"
```

Template usage:
```xml
<P2916>{{P2916}}</P2916>  <!-- Renders as: 1 -->
<P2917>{{P2917}}</P2917>  <!-- Renders as: https://example.com/logo.jpg -->
```

Use dynamic variables for:
- Custom logos and backgrounds
- Display settings and time zones
- Any vendor-specific parameters
- Device-specific customization

**See `templates/TEMPLATE_VARIABLES.md` for complete reference.**

## API Flow

1. **GET /phones/{mac}**
   - Returns: domain, user, device identifier, brand, model
   - Returns: device-provisioning-username, device-provisioning-password
   - Returns: SIP URIs for all lines (device1-device48)
   - Returns: Line enable status (line1_enable-line48_enable)
   - Returns: **device-models-overrides-blob** (dynamic parameter overrides)

2. **GET /domains/{domain}/users/{extension}/devices** (called for each configured line)
   - Returns: SIP registration password for that specific extension
   - Called once per configured line (up to 48 times for fully configured device)
   - Each line receives its own unique password

3. **Dynamic Variables Parsing**
   - Extracts parameter=value pairs from device-models-overrides-blob
   - Makes parameters available as top-level template variables
   - Enables device-specific customization without code changes

## Project Structure

```
NGP/
├── config/
│   ├── config.example.php    # Example configuration
│   └── config.php             # Your configuration (create from example)
├── logs/
│   ├── ngp.log                # Application logs
│   ├── php_errors.log         # PHP error logs
│   └── ratelimit/             # Rate limiting data
├── public/
│   ├── .htaccess              # Apache rewrite rules & security headers
│   └── index.php              # Application entry point
├── src/
│   ├── Auth.php               # HTTP Basic Authentication (static + dynamic)
│   ├── Logger.php             # Logging functionality
│   ├── NsApiClient.php        # ns-api integration
│   ├── RateLimiter.php        # Rate limiting for authentication
│   ├── TemplateParser.php     # Template parsing engine
│   └── TemplateSelector.php   # Brand/model template selection
├── templates/                 # Device configuration templates
│   └── grandstream/           # Grandstream device templates
│       ├── gxw-4216/
│       ├── gxw-4224/
│       ├── gxw-4232/
│       └── gxw-4248/
├── CLAUDE.md                  # Claude Code documentation
├── DEPLOYMENT.md              # Production deployment guide
├── LICENSE                    # GNU GPLv3 license
├── SECURITY_AUDIT_20251205.md # Security audit report
└── README.md                  # This file
```

## Logging

Logs are written to the path specified in configuration (default: `logs/ngp.log`).

Log levels:
- **debug**: Detailed information for debugging
- **info**: General information about requests
- **warning**: Warning messages
- **error**: Error messages

View logs:
```bash
tail -f logs/ngp.log
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
- Check logs/ngp.log for application errors

### Empty or Invalid Configuration
- Verify ns-api credentials
- Check that MAC address exists in ns-api
- Review logs for API errors
- Verify template path in configuration

## Security Considerations

NGP implements extensive security hardening (see Security Features section above). Additional best practices:

- **HTTPS**: Automatically enforced with HSTS headers
- **Strong Credentials**: Use strong passwords; consider dynamic-only authentication mode
- **File Permissions**: Ensure config.php is 640, logs are 640, and rate limit directory is 750
- **API Key Rotation**: Regularly rotate ns-api keys
- **Monitor Logs**: Watch for rate limiting triggers and authentication failures
- **Updates**: Keep PHP and web server updated with security patches

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
   tail -f logs/ngp.log
   ```

## Production Deployment

For production deployment with NetSapiens NDP proxy configuration, see **DEPLOYMENT.md** which covers:
- NetSapiens NDP server proxy setup (Apache/Nginx)
- Security hardening and firewall configuration
- Production checklist and verification steps
- Troubleshooting common deployment issues

## Changelog

### v1.1.0 - 2025-12-08

**New Features:**
- **Dynamic Variables**: Device-specific parameter overrides via `device-models-overrides-blob`
  - Any parameter from ns-api blob automatically available as template variable
  - Supports double-quoted, single-quoted, and unquoted values
  - Perfect for custom logos, display settings, and vendor-specific parameters
  - No code changes needed - just add parameters to ns-api field

**Documentation:**
- Updated all documentation with dynamic variables feature
- Added concise variable reference in `templates/TEMPLATE_VARIABLES.md`
- Improved template examples with dynamic variable usage

### v1.0.0 - 2025-12-05

**Initial Release** - Production-ready provisioning system with comprehensive security

**Core Features:**
- Multi-vendor device support with template-based configuration
- Dynamic authentication using ns-api provisioning credentials
- Per-line SIP credential retrieval (up to 48 lines per device)
- One-time password support for device bootstrapping
- Intelligent template selection (exact, wildcard, brand fallback)
- Custom template parser with variables, conditionals, and loops

**Security Hardening:**
- XML injection protection (ENT_XML1 escaping)
- Path traversal prevention (realpath validation)
- Timing-safe credential comparison (hash_equals)
- Rate limiting (5 attempts/min, 5-min lockout)
- HTTPS enforcement with HSTS
- Security headers (CSP, X-Frame-Options, etc.)
- Input validation and length limits
- Secure random credential generation
- Sanitized logging (no credential exposure)
- SSL certificate validation

**Supported Devices:**
- Grandstream GXW-4216 (16-port FXS gateway)
- Grandstream GXW-4224 (24-port FXS gateway)
- Grandstream GXW-4232 (32-port FXS gateway)
- Grandstream GXW-4248 (48-port FXS gateway)

**Components:**
- Auth.php - Dynamic + static HTTP Basic Authentication
- RateLimiter.php - IP-based rate limiting with lockout
- NsApiClient.php - ns-api integration with per-line credential retrieval
- TemplateParser.php - XML-safe template parsing
- TemplateSelector.php - Path-traversal-safe template selection
- Logger.php - Sanitized logging system

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

**Copyright (C) 2025 NGP Contributors**

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
