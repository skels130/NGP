# Deployment Guide - NGP

This guide covers deploying NGP in a NetSapiens environment where the NDP server proxies requests to your PHP application.

## Architecture Overview

```
Gateway Device                NetSapiens NDP Server           NGP PHP Server
─────────────                ──────────────────────          ──────────────────────
   |                                  |                              |
   | GET /gateway/{MAC}.cfg           |                              |
   |--------------------------------->|                              |
   |                                  |                              |
   |                                  | Proxy: GET /{MAC}.cfg        |
   |                                  | (or GET /gateway/{MAC}.cfg)  |
   |                                  |----------------------------->|
   |                                  |                              |
   |                                  |                              | Query ns-api
   |                                  |                              | Generate config
   |                                  |                              |
   |                                  |      Return config.xml       |
   |                                  |<-----------------------------|
   |                                  |                              |
   |      Return config.xml           |                              |
   |<---------------------------------|                              |
```

## Deployment Steps

### 1. Install NGP on PHP Server

```bash
# Choose installation location
sudo mkdir -p /var/www/config-gen
cd /var/www/config-gen

# Copy project files
sudo cp -r /path/to/NGP/* .

# Create configuration from example
sudo cp config/config.example.php config/config.php

# Edit configuration
sudo nano config/config.php
# Set ns-api credentials, authentication mode, etc.

# Set proper ownership
sudo chown -R www-data:www-data /var/www/config-gen

# Set secure permissions
sudo chmod 600 config/config.php
sudo chmod 755 public
sudo chmod 755 src
sudo chmod 755 templates
sudo mkdir -p logs
sudo chmod 750 logs
```

### 2. Configure Web Server (Apache)

Create Apache virtual host: `/etc/apache2/sites-available/config-gen.conf`

```apache
<VirtualHost *:443>
    ServerName config.example.com
    DocumentRoot /var/www/config-gen/public

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /path/to/ssl/cert.pem
    SSLCertificateKeyFile /path/to/ssl/key.pem

    # Public directory settings
    <Directory /var/www/config-gen/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Deny access to parent directory (safety net)
    <Directory /var/www/config-gen>
        Require all denied
    </Directory>

    # Explicitly allow public directory
    <Directory /var/www/config-gen/public>
        Require all granted
    </Directory>

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/config-gen-error.log
    CustomLog ${APACHE_LOG_DIR}/config-gen-access.log combined
</VirtualHost>

# HTTP to HTTPS redirect
<VirtualHost *:80>
    ServerName config.example.com
    Redirect permanent / https://config.example.com/
</VirtualHost>
```

Enable site and restart Apache:
```bash
sudo a2enmod rewrite ssl
sudo a2ensite config-gen
sudo systemctl restart apache2
```

### 3. Configure NetSapiens NDP Proxy

On your NetSapiens NDP server, configure it to proxy `/gateway/` requests to your NGP server.

#### Option A: Apache Proxy (if NDP uses Apache)

Add to NDP server Apache configuration:

```apache
# Load required modules
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so

# Proxy configuration for gateway configs
<Location /gateway>
    ProxyPass https://config.example.com/gateway
    ProxyPassReverse https://config.example.com/gateway

    # Pass authentication headers
    ProxyPreserveHost Off

    # SSL verification
    SSLProxyEngine On
    SSLProxyVerify require
    SSLProxyCheckPeerName on
</Location>
```

#### Option B: Nginx Proxy (if NDP uses Nginx)

Add to NDP server Nginx configuration:

```nginx
location /gateway/ {
    proxy_pass https://config.example.com/gateway/;
    proxy_http_version 1.1;

    # Pass headers
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # Pass authentication
    proxy_pass_request_headers on;

    # SSL verification
    proxy_ssl_verify on;
    proxy_ssl_trusted_certificate /etc/ssl/certs/ca-certificates.crt;
}
```

#### Option C: Strip /gateway/ Prefix (Alternative)

If you prefer to strip the `/gateway/` prefix before proxying:

**Apache:**
```apache
<Location /gateway>
    RewriteEngine On
    RewriteRule ^/gateway/(.*)$ https://config.example.com/$1 [P,L]
    ProxyPassReverse https://config.example.com/
</Location>
```

**Nginx:**
```nginx
location /gateway/ {
    rewrite ^/gateway/(.*)$ /$1 break;
    proxy_pass https://config.example.com;
}
```

### 4. Configure Gateway Devices

Set gateway provisioning configuration:

- **Config Server URL**: `http://ndp-server.com/gateway/`
- **Config File Path**: `{MAC}.cfg` or `cfg{MAC}.xml` (device-specific)
- **HTTP Authentication**: Use device provisioning credentials from ns-api

Example for Grandstream GXW4200:
- **P212** (Config Server Path): `http://ndp-server.example.com/gateway/`
- **P237** (HTTP User): `{device-provisioning-username}`
- **P238** (HTTP Password): `{device-provisioning-password}`

### 5. Verify Deployment

#### Test 1: Direct Access to NGP Server
```bash
# Should return 401 (authentication required)
curl https://config.example.com/C074AD893044.cfg

# Should return config (with valid credentials)
curl -u username:password https://config.example.com/C074AD893044.cfg
```

#### Test 2: Proxied Access via NDP Server
```bash
# Test through NDP proxy
curl -u username:password http://ndp-server.com/gateway/C074AD893044.cfg
```

#### Test 3: Security Checks
```bash
# Should all return 403 Forbidden
curl https://config.example.com/config/config.php
curl https://config.example.com/src/NsApiClient.php
curl https://config.example.com/../config/config.php
```

#### Test 4: Monitor Logs
```bash
# On NGP server
tail -f /var/www/config-gen/logs/ngp.log

# Watch for successful requests:
# [INFO] Config request received for MAC: C074AD893044
# [INFO] Authenticated using device provisioning credentials
# [INFO] Configuration generated successfully for MAC: C074AD893044
```

### 6. Production Checklist

Before going live:

- [ ] **SSL Certificates**: Valid SSL certs installed on both servers
- [ ] **HTTPS Redirect**: Enable HTTPS redirect in `public/.htaccess` (line 11-12)
- [ ] **Error Display**: Disable error display in `public/index.php`:
  ```php
  // Change lines 8-9 to:
  error_reporting(0);
  ini_set('display_errors', 0);
  ```
- [ ] **Logging Level**: Set to 'info' or 'warning' in `config/config.php`:
  ```php
  'logging' => [
      'level' => 'info',  // Not 'debug' in production
  ],
  ```
- [ ] **File Permissions**: Verify restrictive permissions
  - `config/config.php`: 0600
  - `logs/`: 0750
  - `public/`: 0755
- [ ] **Authentication Mode**: Set appropriate mode in `config/config.php`:
  ```php
  'auth' => [
      'enabled' => true,
      'mode' => 'dynamic',  // 'dynamic', 'static', or 'both'
  ],
  ```
- [ ] **DocumentRoot**: Verify Apache/Nginx DocumentRoot points to `public/`
- [ ] **Firewall Rules**: Only allow NDP server to access NGP server
- [ ] **API Keys**: Use production ns-api credentials
- [ ] **Templates**: Verify all required device templates are installed
- [ ] **Backup Config**: Backup `config/config.php` securely (encrypted)

## Troubleshooting

### Proxy Not Working

**Symptom**: Gateway gets 404 or 502 error

**Check**:
1. Verify NDP proxy configuration is correct
2. Check NDP server can reach NGP server:
   ```bash
   # From NDP server
   curl -I https://config.example.com/C074AD893044.cfg
   ```
3. Check firewall rules between NDP and NGP servers
4. Review NDP proxy logs for errors

### Authentication Failing

**Symptom**: Gateway gets 401 Unauthorized

**Check**:
1. Verify device has provisioning credentials in ns-api
2. Check authentication mode in `config/config.php`
3. Review NGP logs:
   ```bash
   grep "Authentication failed" /var/www/config-gen/logs/ngp.log
   ```
4. Test with static credentials to isolate issue

### Config Not Generating

**Symptom**: Gateway gets 500 Internal Server Error

**Check**:
1. Review NGP logs for errors
2. Check PHP error logs:
   ```bash
   tail -f /var/log/apache2/config-gen-error.log
   ```
3. Verify ns-api connectivity:
   ```bash
   curl -H "Authorization: Bearer YOUR_API_KEY" \
        https://api.example.com/phones/C074AD893044
   ```
4. Check template exists for device brand/model

### Template Not Found

**Symptom**: "No template available for this device model"

**Check**:
1. Verify device brand/model in ns-api response
2. Check template directory exists:
   ```bash
   ls -la /var/www/config-gen/templates/{brand}/{model}/
   ```
3. Verify template filename is `config.xml`
4. Check template file permissions (should be readable by www-data)

## Proxy URL Patterns

The NGP server handles multiple URL patterns:

| Device Request | NDP Receives | NDP Proxies | NGP Receives |
|----------------|--------------|-------------|---------------------|
| `/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` | → `https://config.example.com/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` ✓ |
| `/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` | → `https://config.example.com/{MAC}.cfg` | `/{MAC}.cfg` ✓ |

Both patterns are supported by the `.htaccess` rewrite rules.

## Security Notes

- **Network Isolation**: Ideally, NGP server should only be accessible from NDP server
- **Firewall Rules**: Lock down NGP server to only accept connections from NDP server IP
- **HTTPS Required**: All proxy communication should use HTTPS
- **Credential Rotation**: Regularly rotate API keys and static authentication credentials
- **Log Monitoring**: Monitor logs for suspicious activity or authentication failures
- **Rate Limiting**: Consider adding rate limiting to prevent abuse

## Scaling Considerations

For high-volume deployments:

- **Caching**: Add Redis/Memcached for ns-api response caching
- **Load Balancing**: Deploy multiple NGP servers behind load balancer
- **CDN**: Use CDN for static template assets (if applicable)
- **Connection Pooling**: Configure PHP-FPM with appropriate process limits
- **Monitoring**: Implement APM (Application Performance Monitoring)

## Support

For deployment issues:
1. Review logs: `/var/www/config-gen/logs/ngp.log`
2. Check SECURITY_REVIEW.md for security best practices
3. Verify ns-api connectivity and credentials
4. Test direct access before testing through proxy
