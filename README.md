# NGP (NDP Gateway Provisioning)

Multi-vendor configuration generator for IP telephony devices. This web server dynamically generates device configuration files by querying the ns-api for SIP credentials and applying them to customizable templates.

## Features

- **Multi-Vendor Support**: Automatically selects appropriate template based on device brand and model
- **Dynamic Authentication**: Validates devices using provisioning credentials from ns-api
- **Dynamic Configuration**: Generates configs based on MAC address and ns-api data
- **Dynamic Variables**: Device-specific parameter overrides from ns-api `device-models-overrides-blob`
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



## Architecture

NGP is designed to work with NS NDP server:

```
Gateway → NS NDP Server (/gateway/) → NGP PHP Server
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

## Configuration

### config/config.php

```php
return [
    'auth' => [
        'enabled' => true,
        'mode' => 'dynamic',  // 'static', 'dynamic', or 'both'
        'username' => 'admin', #One time global username/password here
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

No code changes needed - just create the directory structure!

## Usage

### Requesting Configuration Files

In a NS deployment, devices request configurations from the NDP server:
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
- `{{device_info.sip_server}}` - SIP server (domain name)
- `{{device_info.outbound_proxy}}` - Outbound proxy FQDN
- `{{device_info.transport}}` - SIP transport (udp/tcp/tls)
- `{{device_info.tcp_port}}` - TCP port for SIP (e.g., 5060)
- `{{device_info.tls_port}}` - TLS port for SIP (e.g., 5061)
- `{{device_info.provisioning_username}}` - Provisioning username (HTTP Basic Auth)
- `{{device_info.provisioning_password}}` - Provisioning password (HTTP Basic Auth)
- `{{device_info.provisioning_server}}` - Provisioning server hostname (NDP server)
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
   - Returns: device-provisioning-registration-core-server (server name)
   - Returns: SIP URIs for all lines (device1-device48)
   - Returns: Line enable status (line1_enable-line48_enable)
   - Returns: **device-models-overrides-blob** (dynamic parameter overrides)

2. **GET /phones/servers/{server}**
   - Fetches server configuration using registration server from step 1
   - Returns: device-provisioning-core-server-postfix-fqdn (outbound proxy)
   - Returns: device-provisioning-core-server-tcp-port (TCP port)
   - Returns: device-provisioning-core-server-tls-port (TLS port)

3. **GET /domains/{domain}/users/{extension}/devices** (called for each configured line)
   - Returns: SIP registration password for that specific extension
   - Called once per configured line (up to 48 times for fully configured device)
   - Each line receives its own unique password

4. **Dynamic Variables Parsing**
   - Extracts parameter=value pairs from device-models-overrides-blob
   - Makes parameters available as top-level template variables
   - Enables device-specific customization without code changes

## Logging

Logs are written to the path specified in configuration (default: `logs/ngp.log`).

Log levels:
- **debug**: Detailed information for debugging
- **info**: General information about requests
- **warning**: Warning messages
- **error**: Error messages

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

## Production Deployment

For production deployment with NS NDP proxy configuration, see **DEPLOYMENT.md** which covers:
- NS NDP server proxy setup (Apache)
- Security hardening and firewall configuration
- Production checklist and verification steps
- Troubleshooting common deployment issues

## License

This project is licensed under the GNU General Public License v3.0 - see the [LICENSE](LICENSE) file for details.

**Copyright (C) 2025 NGP Contributors**

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
