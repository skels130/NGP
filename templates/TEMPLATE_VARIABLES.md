# Template Variables Reference

This document describes the variables available in configuration templates and how they are populated from ns-api.

## API Call Flow

### 1. GET /phones/{mac}
First call retrieves device provisioning information including:
- `domain` - Domain name
- `user` - User/extension
- `device` - Device identifier
- `brand` - Device brand (from `device-models-brand-and-model`)
- `model` - Device model (from `device-models-brand-and-model`)
- `device-provisioning-username` - HTTP auth username
- `device-provisioning-password` - HTTP auth password
- `ndphostname` - SIP server hostname
- `device1` through `device48` - SIP URIs for each line (e.g., "sip:1004@domain")
- `line1_enable` through `line48_enable` - Line enabled status ("yes" or "no")
- `device-models-count-buttons` - Number of lines/buttons on device

### 2. GET /domains/{domain}/users/{extension}/devices (called for each configured line)
Multiple calls retrieve SIP registration credentials for each line:
- `device-sip-registration-password` - SIP password for this specific extension
- Each configured line/extension gets its own individual password

## Available Variables

### Device Level Variables

| Variable | Source | Example | Description |
|----------|--------|---------|-------------|
| `{{mac}}` | Request | `C074AD893044` | Device MAC address from request |
| `{{brand}}` | /phones/{mac} | `grandstream` | Device brand (normalized) |
| `{{model}}` | /phones/{mac} | `gxw-4248` | Device model (normalized) |
| `{{domain}}` | /phones/{mac} | `ArrowResidential` | Domain name |
| `{{user}}` | /phones/{mac} | `1000` | User/extension |
| `{{device}}` | /phones/{mac} | `1000x` | Device identifier |

### SIP Configuration Variables

| Variable | Source | Example | Description |
|----------|--------|---------|-------------|
| `{{device_info.sip_server}}` | /phones/{mac} `ndphostname` | `endpoints-01-chi.calldecibel.com` | SIP server hostname |
| `{{device_info.outbound_proxy}}` | Not currently provided | - | Outbound proxy (empty) |
| `{{device_info.transport}}` | /phones/{mac} `device-provisioning-sip-transport-protocol` | `udp` | SIP transport protocol |

### Line-Specific Variables

Lines are accessed as an array from 0-47 (for 48-port devices):

| Variable | Source | Example | Description |
|----------|--------|---------|-------------|
| `{{device_info.lines.N.line_number}}` | Calculated | `1` (for index 0) | Line number (1-48) |
| `{{device_info.lines.N.username}}` | Parsed from `deviceN` field | `1004` | SIP username (from "sip:1004@domain") |
| `{{device_info.lines.N.auth_id}}` | Same as username | `1004` | SIP authentication ID |
| `{{device_info.lines.N.password}}` | /domains/{domain}/users/{ext}/devices/{ext}x | `abc123...` | SIP password for this specific line |
| `{{device_info.lines.N.extension}}` | Same as username | `1004` | Extension number |
| `{{device_info.lines.N.enabled}}` | /phones/{mac} `lineN_enable` | `true` | Whether line is enabled |
| `{{device_info.lines.N.sip_uri}}` | /phones/{mac} `deviceN` | `sip:1004@ArrowResidential` | Full SIP URI |

**Note**: `N` is the array index (0-47), which corresponds to FXS ports 1-48.

## Grandstream GXW Template Usage

### Example for FXS Port 1 (array index 0):

```xml
<!-- FXS 1 -->
<P4060>{{device_info.lines.0.username}}</P4060>      <!-- SIP User ID: 1004 -->
<P4090>{{device_info.lines.0.auth_id}}</P4090>       <!-- Auth ID: 1004 -->
<P4120>{{device_info.lines.0.password}}</P4120>      <!-- Password: abc123... -->
<P4180>{{device_info.lines.0.extension}}</P4180>     <!-- Name/Extension: 1004 -->
```

### Example for FXS Port 48 (array index 47):

```xml
<!-- FXS 48 -->
<P21015>{{device_info.lines.47.username}}</P21015>   <!-- SIP User ID -->
<P21079>{{device_info.lines.47.auth_id}}</P21079>    <!-- Auth ID -->
<P21143>{{device_info.lines.47.password}}</P21143>   <!-- Password -->
<P21207>{{device_info.lines.47.extension}}</P21207>  <!-- Name/Extension -->
```

### Profile 1 SIP Server Configuration:

```xml
<!-- SIP Server -->
<P47>{{device_info.sip_server}}</P47>                 <!-- endpoints-01-chi.calldecibel.com -->

<!-- Primary Outbound Proxy -->
<P48>{{device_info.outbound_proxy}}</P48>             <!-- Empty if not provided -->
```

## Important Notes

1. **Individual Line Passwords**: Each configured line gets its own unique SIP password by querying the device endpoint for that specific extension. This results in multiple API calls per config request (one per configured line).

2. **Line Indexing**: Template uses 0-based indexing (`lines.0` through `lines.47`) for 48 lines.

3. **Empty Lines**: If a line is not configured in ns-api:
   - `username`, `auth_id`, `extension` will be `null` (renders as empty string)
   - `password` will be `null` if the API call fails or line is not configured
   - `enabled` will be `false`
   - `sip_uri` will be `null` or "n/a"

4. **MAC Address Normalization**: MAC addresses are converted to uppercase in templates.

5. **Brand/Model Normalization**: Brand and model names are normalized (lowercase, special characters removed) for filesystem paths but originals are preserved in variables.

## Data Flow Summary

```
Request: http://server.com/C074AD893044.cfg

1. Extract MAC → C074AD893044
2. Call /phones/C074AD893044
   └─ Get: brand, model, domain, user, device, lines config, provisioning creds
3. Authenticate using device-provisioning-username/password
4. For each configured line (1-48):
   a. Parse SIP URI to extract extension (e.g., "sip:1004@domain" → "1004")
   b. Call /domains/{domain}/users/{extension}/devices
   c. Get: device-sip-registration-password for this specific extension
5. Merge data:
   - Lines config from step 2
   - Individual passwords from step 4 (one per line)
6. Select template based on brand/model
7. Parse template with merged variables
8. Return generated config
```
