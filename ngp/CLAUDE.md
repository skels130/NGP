# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

NGP (NetSapiens Gateway Provisioning) is a multi-vendor configuration generation tool for IP telephony gateways. It runs as a web server behind the NetSapiens NDP (Network Device Provisioning) server, where configuration files are requested in the form of {mac}.cfg where {mac} is an actual MAC address of a device. Using that MAC address, we query the ns-api to get the SIP credentials, and fill in variables in a template that is stored on the server. The template system supports logic functions that are evaluated at configuration generation time to set parameters dynamically.


## Development Commands

### Setup
```bash
# Copy example configuration
cp config/config.example.php config/config.php

# Edit configuration with your settings
nano config/config.php

# Create logs directory
mkdir -p logs
chmod 775 logs

# Run local development server
cd public && php -S localhost:8000
```

### Testing
```bash
# Test configuration generation (replace MAC with actual device)
curl -u username:password http://localhost:8000/C074AD7C6934.cfg

# Monitor logs in real-time
tail -f logs/ngp.log

# Test ns-api connectivity
curl -H "Authorization: Bearer YOUR_API_KEY" https://api.netspectrum.com/phones/C074AD7C6934
```

### Deployment
```bash
# Copy to web server
sudo cp -r . /var/www/ngp

# Set permissions
sudo chown -R www-data:www-data /var/www/ngp
sudo chmod 755 /var/www/ngp/public

# For Apache, ensure mod_rewrite is enabled
sudo a2enmod rewrite
sudo systemctl reload apache2
```

## Architecture

### Deployment Architecture
NGP operates as a backend service behind the NetSapiens NDP (Network Device Provisioning) server:

```
Gateway Device → NetSapiens NDP Server → NGP PHP Server → ns-api
     |                  |                      |             |
     |  /gateway/       |  proxy:              |  queries    |
     |  {MAC}.cfg       |  /{MAC}.cfg          |  credentials|
     |                  |  (with auth)         |             |
```

See **DEPLOYMENT.md** for complete proxy setup instructions.

### Request Flow
1. Gateway requests `/gateway/{mac}.cfg` from NetSapiens NDP server with HTTP Basic Auth credentials
2. NDP server proxies request to NGP PHP server (with or without `/gateway/` prefix)
3. PHP server extracts MAC address from the request
4. Query ns-api `/phones/{mac}` to get:
   - Domain, user, and device identifier
   - Device brand and model
   - Device provisioning credentials (username/password)
   - SIP URIs for all lines (device1-device48)
   - Line enable status (line1_enable-line48_enable)
5. **Dynamic Authentication**: Validate HTTP Basic Auth credentials against provisioning credentials from API
   - Mode: `dynamic` - use device provisioning credentials from ns-api
   - Mode: `static` - use credentials from config file
   - Mode: `both` - try dynamic first, fall back to static
6. **Per-Line Credential Retrieval**: For each configured line, query ns-api `/domains/{domain}/users/{extension}/devices/{extension}x` to get that line's SIP password
   - Each line gets its own unique password
   - Up to 48 API calls for fully configured device
7. **Template Selection**: Select appropriate template based on brand and model
   - Check exact match: `brand:model`
   - Check pattern match: `brand:model*` (wildcard support)
   - Check brand-only match: `brand`
   - Fall back to default template
8. Parse template and evaluate custom logic expressions
9. Replace variables with values from ns-api response
10. Return generated XML configuration to NDP server (which forwards to gateway)

### Configuration Template Structure
- **Format**: XML with Grandstream P-code parameters (e.g., `<P47>`, `<P4060>`)
- **Location**: `/home/skelsey@losh.local/Documents/config-template/gxw42xx_v2_config_1.0.15.2.xml`
- **Size**: ~6651 lines
- **Key Parameters**:
  - `<P47>`: SIP Server
  - `<P48>`: Primary Outbound Proxy
  - `<P967>`: Failover SIP Server
  - `<P4060-P4095>`: FXS Port 1-48 SIP User IDs
  - `<P4090-P4125>`: FXS Port 1-48 Authenticate IDs
  - `<P4120-P4155>`: FXS Port 1-48 Passwords
  - `<P4150-P4185>`: FXS Port 1-48 Profile IDs (0-3 for Profiles 1-4)

### Custom Logic Parser
The template parser must support:
- Variable substitution (e.g., `{{sip_server}}`, `{{username}}`, `{{password}}`)
- Conditional logic (e.g., `{{if condition}}...{{endif}}`)
- Loop constructs for multiple FXS ports
- Basic expressions for parameter calculation

### ns-api Integration
- **MCP Tools Available**: Use `mcp__ns-api__*` functions for API calls (in Claude Code context)
- **Production**: Uses direct HTTP/curl requests via NsApiClient class
- **Authentication**: API key in Authorization header
- **Required Endpoints**:
  - `GET /phones/{mac}` - Returns domain, user, brand, model, provisioning credentials, and line configuration (device1-device48, line1_enable-line48_enable)
  - `GET /domains/{domain}/users/{extension}/devices/{extension}x` - Returns SIP password for specific extension (called once per configured line)

### Security & Authentication
- **Dynamic Authentication**: Validates device HTTP credentials against ns-api provisioning credentials
  - Devices authenticate using their provisioning username/password from ns-api
  - No hardcoded credentials needed per device
  - Falls back to static config credentials if provisioning creds unavailable
- **Static Authentication**: Traditional config file username/password
- **Auth Modes**:
  - `dynamic` - Only accept device provisioning credentials
  - `static` - Only accept config file credentials
  - `both` - Accept either (dynamic first, then static fallback)

### Technology Stack
- **Language**: PHP 7.4+
- **Web Server**: Apache/Nginx with PHP support
- **Template Engine**: Custom parser (implemented in `src/TemplateParser.php`)
- **API Integration**: ns-api via HTTP/curl (implemented in `src/NsApiClient.php`)

### Implementation Files
- `public/index.php` - Main entry point, handles requests and orchestrates components
- `src/Auth.php` - HTTP Basic Authentication handler (supports both static and dynamic credentials)
- `src/Logger.php` - Logging functionality (supports debug, info, warning, error levels)
- `src/NsApiClient.php` - ns-api REST client using curl (extracts brand, model, provisioning creds)
- `src/TemplateSelector.php` - Template selection engine based on brand/model with wildcard support
- `src/TemplateParser.php` - Custom template parser supporting variables, conditionals, and loops
- `config/config.php` - Application configuration (create from config.example.php)
- `public/.htaccess` - Apache URL rewriting rules

### Template Parser Features
The `TemplateParser` class supports:
- **Variables**: `{{variable}}` or `{{device_info.username}}`
- **Conditionals**: `{{if condition}}...{{else}}...{{endif}}`
- **Loops**: `{{for port in 1..48}}...{{endfor}}`
- **Comparisons**: `==`, `!=`, `>`, `<`, `>=`, `<=`
- **Logical operators**: `&&`, `||`, `!`

Example template usage:
```xml
<mac>{{mac}}</mac>
<P47>{{device_info.sip_server}}</P47>
{{if device_info.outbound_proxy}}
<P48>{{device_info.outbound_proxy}}</P48>
{{endif}}
{{for port in 1..48}}
<P4060>{{device_info.username}}</P4060>
{{endfor}}
```

### Template Selector Features
The `TemplateSelector` class uses a directory-based hierarchy to find templates:

**Directory Structure:**
```
templates/
├── {brand}/
│   ├── {model}/
│   │   └── config.xml
│   └── default/          (optional brand fallback)
│       └── config.xml
```

**Selection Logic:**
1. Try `templates/{brand}/{model}/config.xml` - Exact brand/model match
2. Try `templates/{brand}/default/config.xml` - Brand fallback (optional)
3. Return 404 if no template found

**Path Normalization:**
- Brand/model names converted to lowercase
- Spaces replaced with hyphens
- Special characters removed
- Example: "GXW-4248" → "gxw-4248"

**Configuration:**
```php
'templates' => [
    'base_path' => __DIR__ . '/../templates',
    'template_filename' => 'config.xml',
],
```

**Adding New Templates:**
Simply create the directory structure - no code changes needed:
```bash
mkdir -p templates/yealink/t46s
cp existing_template.xml templates/yealink/t46s/config.xml
# Edit template to match device
```

## Notes

- **Directory-Based Templates**: No code changes needed to add new device support
  - Simply create `templates/{brand}/{model}/config.xml`
  - Brand/model from ns-api automatically maps to filesystem path
- **Dynamic Credentials**: Devices authenticate using provisioning credentials from ns-api
  - Field names: `device-provisioning-username`, `device-provisioning-password`
  - Alternative names: `provisioning-username`, `provisioning_username`, etc.
- **Template Organization**:
  - Each model has its own directory under `templates/{brand}/{model}/`
  - Optional brand-level fallback: `templates/{brand}/default/config.xml`
  - No system-wide default - returns 404 if template not found
- **Grandstream GXW4200 Series**:
  - Templates located in `templates/grandstream/gxw-4216/`, `gxw-4224/`, `gxw-4232/`, `gxw-4248/`
  - Contains 48 FXS port configurations (ports 1-48)
  - Each FXS port gets its own unique SIP password from ns-api
  - Template uses 0-based indexing: `{{device_info.lines.0.password}}` for port 1, `{{device_info.lines.47.password}}` for port 48
  - Each FXS port can be assigned to one of 4 SIP profiles
  - Supports multiple simultaneous profiles
- **Adding New Device Support**:
  1. Create directory: `mkdir -p templates/{brand}/{model}`
  2. Add template: `cp existing.xml templates/{brand}/{model}/config.xml`
  3. Edit template to match device configuration format
  4. Use parser syntax for dynamic values: `{{device_info.username}}`
- **API Response Fields**: NsApiClient tries multiple field name variations
  - Brand: `brand`, `make`, `vendor`
  - Model: `model`
  - Provisioning creds: `device-provisioning-username`, `provisioning-username`, `provisioning_username`
- **Per-Line Credential Retrieval**:
  - `NsApiClient::getDeviceInfo()` makes individual API calls for each configured line
  - Parses SIP URI from `deviceN` field (e.g., "sip:1004@domain" → extension "1004")
  - Queries `/domains/{domain}/users/{extension}/devices/{extension}x` for each line's password
  - Device ID pattern: extension + 'x' (e.g., "1004x" for extension 1004)
  - Results in multiple API calls per config request (one per configured line)
