# Templates Directory

Configuration templates organized by device brand and model.

## Directory Structure

```
templates/
├── {brand}/
│   ├── {model}/
│   │   └── config.xml
│   └── default/          (optional brand fallback)
│       └── config.xml
```

## Adding a New Template

```bash
# 1. Create directory
mkdir -p templates/{brand}/{model}

# 2. Copy existing template as starting point
cp templates/grandstream/gxw-4216/config.xml templates/{brand}/{model}/config.xml

# 3. Edit template with device-specific configuration
nano templates/{brand}/{model}/config.xml
```

**No code changes needed** - just create the directory structure!

## Available Variables

### Standard Variables
- `{{mac}}` - Device MAC address
- `{{domain}}`, `{{user}}`, `{{device}}` - Device identifiers
- `{{device_info.sip_server}}` - SIP server hostname
- `{{device_info.lines.N.username}}` - Line N username (0-47)
- `{{device_info.lines.N.password}}` - Line N SIP password
- `{{device_info.lines.N.auth_id}}` - Line N auth ID
- `{{device_info.lines.N.extension}}` - Line N extension

### Dynamic Variables (Parameter Overrides)
Any parameter from `device-models-overrides-blob` in ns-api becomes available:

**Example ns-api field:**
```
device-models-overrides-blob: P2917="https://example.com/logo.jpg" P2916="1"
```

**Template usage:**
```xml
<P2916>{{P2916}}</P2916>
<P2917>{{P2917}}</P2917>
```

Perfect for device-specific customization:
- Custom logos/backgrounds
- Display settings
- Time zones
- Any vendor-specific parameter

**See TEMPLATE_VARIABLES.md for complete variable reference.**

## Template Syntax

### Variables
```xml
<P47>{{device_info.sip_server}}</P47>
<P2917>{{P2917}}</P2917>
```

### Conditionals
```xml
{{if device_info.lines.0.enabled}}
<P4060>{{device_info.lines.0.username}}</P4060>
{{endif}}
```

### Loops
```xml
{{for port in 1..48}}
<!-- Port {{port}} configuration -->
{{endfor}}
```

## Template Selection

When a device requests configuration:

1. Extract `brand` and `model` from ns-api `/phones/{mac}`
2. Normalize: lowercase, spaces→hyphens (e.g., "GXW-4248" → "gxw-4248")
3. Try templates in order:
   - `templates/{brand}/{model}/config.xml` (exact match)
   - `templates/{brand}/default/config.xml` (brand fallback)
   - Return 404 if not found

## Template File Name

All templates must be named `config.xml` (configurable in `config/config.php`).

## Security

- All variables are automatically XML-escaped
- Template paths validated to prevent traversal attacks
- No default fallback to prevent misconfiguration
