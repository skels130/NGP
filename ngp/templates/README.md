# Templates Directory

This directory contains configuration templates organized by device brand and model.

## Directory Structure

Templates are organized hierarchically:

```
templates/
├── {brand}/
│   ├── {model}/
│   │   └── config.xml
│   └── default/          (optional brand-level fallback)
│       └── config.xml
```

## Example

```
templates/
├── grandstream/
│   ├── gxw-4216/
│   │   └── config.xml
│   ├── gxw-4224/
│   │   └── config.xml
│   ├── gxw-4248/
│   │   └── config.xml
│   └── default/          (optional: used for any Grandstream model without specific template)
│       └── config.xml
├── yealink/
│   ├── t46s/
│   │   └── config.xml
│   └── default/
│       └── config.xml
```

## Adding a New Template

To add support for a new device model:

1. **Create the directory structure:**
   ```bash
   mkdir -p templates/{brand}/{model}
   ```

2. **Add the template file:**
   ```bash
   # Copy an existing template as a starting point
   cp templates/grandstream/gxw-4216/config.xml templates/{brand}/{model}/config.xml
   ```

3. **Edit the template:**
   - Use template syntax: `{{variable}}`, `{{if condition}}`, `{{for...}}`
   - Available variables:
     - `{{mac}}` - Device MAC address
     - `{{brand}}` - Device brand
     - `{{model}}` - Device model
     - `{{device_info.sip_server}}` - SIP server from ns-api
     - `{{device_info.username}}` - SIP username
     - `{{device_info.password}}` - SIP password
     - `{{device_info.auth_id}}` - SIP auth ID
     - See full variable list in main README.md

## Template Selection Logic

When a device requests configuration, the system:

1. Extracts brand and model from ns-api `/phones/{mac}` response
2. Normalizes to lowercase and sanitizes (e.g., "GXW-4248" → "gxw-4248")
3. Looks for template in this order:
   - `templates/{brand}/{model}/config.xml` (exact match)
   - `templates/{brand}/default/config.xml` (brand fallback)
   - Returns 404 if no template found

## Brand/Model Normalization

Brand and model names are normalized for filesystem compatibility:
- Converted to lowercase
- Spaces replaced with hyphens
- Special characters removed
- Only alphanumeric, hyphens, and underscores allowed

Examples:
- "Grandstream" → "grandstream"
- "GXW-4248" → "gxw-4248"
- "T46S" → "t46s"

## Template File Name

All templates must be named `config.xml` (configurable in `config/config.php`).

## No Default Fallback

If no template is found for a device, the system returns a 404 error. This is intentional to prevent misconfigured devices from receiving incorrect templates.
