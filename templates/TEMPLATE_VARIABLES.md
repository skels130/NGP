# Template Variables Reference

Quick reference for variables available in NGP configuration templates.

## Variable Types

### 1. Standard Variables
Built-in variables from MAC request and ns-api responses.

### 2. Dynamic Variables (Parameter Overrides)
Custom parameters from `device-models-overrides-blob` field in ns-api.
- Any parameter returned in the blob is available as `{{parameter_name}}`
- Example: `P2917="https://example.com/logo.jpg"` → use `{{P2917}}`

## Available Standard Variables

### Device Information
| Variable | Example | Description |
|----------|---------|-------------|
| `{{mac}}` | `C074AD893044` | Device MAC address |
| `{{brand}}` | `grandstream` | Device brand |
| `{{model}}` | `gxw-4248` | Device model |
| `{{domain}}` | `ArrowResidential` | Domain name |
| `{{user}}` | `1000` | User/extension |
| `{{device}}` | `1000x` | Device identifier |

### SIP Server Configuration
| Variable | Example | Description |
|----------|---------|-------------|
| `{{device_info.sip_server}}` | `endpoints.example.com` | SIP server hostname |
| `{{device_info.transport}}` | `udp` | Transport protocol (udp/tcp/tls) |
| `{{device_info.button_count}}` | `48` | Number of lines/buttons |

### Line Configuration (Per-Line)
Access lines as array: `device_info.lines.N` where N is 0-47 (for 48 ports).

| Variable | Example | Description |
|----------|---------|-------------|
| `{{device_info.lines.N.line_number}}` | `1` | Line number (1-48) |
| `{{device_info.lines.N.username}}` | `1004` | SIP username |
| `{{device_info.lines.N.auth_id}}` | `1004` | SIP auth ID |
| `{{device_info.lines.N.password}}` | `abc123def` | SIP password |
| `{{device_info.lines.N.extension}}` | `1004` | Extension number |
| `{{device_info.lines.N.enabled}}` | `true` / `false` | Line enabled status |
| `{{device_info.lines.N.sip_uri}}` | `sip:1004@domain` | Full SIP URI |

## Template Syntax Examples

### Variables
```xml
<P47>{{device_info.sip_server}}</P47>
<P2917>{{P2917}}</P2917>  <!-- Dynamic variable from overrides blob -->
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
<!-- Port {{port}} config -->
{{endfor}}
```

## Grandstream GXW Quick Reference

### SIP Server (Profile 1)
```xml
<P47>{{device_info.sip_server}}</P47>
```

### FXS Port 1 (lines.0)
```xml
<P4060>{{device_info.lines.0.username}}</P4060>   <!-- SIP User ID -->
<P4090>{{device_info.lines.0.auth_id}}</P4090>    <!-- Auth ID -->
<P4120>{{device_info.lines.0.password}}</P4120>   <!-- Password -->
<P4180>{{device_info.lines.0.extension}}</P4180>  <!-- Extension -->
```

### FXS Port 48 (lines.47)
```xml
<P21015>{{device_info.lines.47.username}}</P21015>
<P21079>{{device_info.lines.47.auth_id}}</P21079>
<P21143>{{device_info.lines.47.password}}</P21143>
<P21207>{{device_info.lines.47.extension}}</P21207>
```

## Dynamic Variables (Parameter Overrides)

The `device-models-overrides-blob` field from ns-api allows per-device parameter customization.

**Example ns-api Response:**
```
device-models-overrides-blob: P2917="https://losh.com/logos/reece_logo_480x272.jpg" P2916="1"
```

**Template Usage:**
```xml
<P2916>{{P2916}}</P2916>  <!-- Renders: <P2916>1</P2916> -->
<P2917>{{P2917}}</P2917>  <!-- Renders: <P2917>https://losh.com/logos/reece_logo_480x272.jpg</P2917> -->
```

**Features:**
- Any parameter in the blob becomes a top-level template variable
- Supports double quotes, single quotes, or unquoted values
- Overrides are device-specific (per MAC address)
- Perfect for custom logos, backgrounds, display settings, etc.

## API Call Flow

### Step 1: GET /phones/{mac}
Returns:
- Device info: `domain`, `user`, `device`, `brand`, `model`
- Provisioning: `device-provisioning-username`, `device-provisioning-password`
- SIP server: `device-provisioning-ndp-hostname`
- Line config: `device-provisioning-sip-uri-1` through `device-provisioning-sip-uri-48`
- Line status: `device-provisioning-line-1-enabled` through `device-provisioning-line-48-enabled`
- **Parameter overrides**: `device-models-overrides-blob`

### Step 2: GET /domains/{domain}/users/{extension}/devices
Called once per configured line to retrieve:
- `device-sip-registration-password` - Unique password for each line

### Step 3: Merge and Render
- Standard variables populated from API responses
- Dynamic variables extracted from `device-models-overrides-blob`
- Template rendered with all variables

## Important Notes

1. **Line Indexing**: Arrays use 0-based indexing (lines.0 = Port 1, lines.47 = Port 48)
2. **Empty Values**: Unconfigured lines render as empty strings
3. **XML Escaping**: All variables are automatically XML-escaped for security
4. **Dynamic Priority**: Dynamic variables override standard variables if names conflict
