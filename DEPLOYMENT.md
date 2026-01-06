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
        Options -Indexes +FollowSymLinks

        # IP Restriction: Only allow NDP proxy servers (recommended for production)
        # Uncomment and add your NDP server IPs to restrict access
        # Require ip 192.168.1.100
        # Require ip 10.0.0.50
        # Or allow from all (less secure)
        Require all granted
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

#### Option A: Apache Proxy

First, enable the required Apache modules:

**Debian/Ubuntu:**
```bash
sudo a2enmod proxy proxy_http headers ssl rewrite
sudo systemctl restart apache2
```

**Red Hat/CentOS:** Modules are typically pre-loaded. If not, add to your config:
```apache
LoadModule proxy_module modules/mod_proxy.so
LoadModule proxy_http_module modules/mod_proxy_http.so
LoadModule headers_module modules/mod_headers.so
```

Then add to NDP server Apache configuration (e.g., `/etc/apache2/conf-available/ngp.conf`):

```apache
# SSL proxy settings (must be outside <Location> block)
SSLProxyEngine On
SSLProxyVerify require
SSLProxyCheckPeerName on

# Proxy configuration for gateway configs
<Location /gateway>
    ProxyPass https://config.example.com/gateway
    ProxyPassReverse https://config.example.com/gateway

    # Pass authentication headers
    ProxyPreserveHost Off

    # IMPORTANT: Forward original client IP for rate limiting
    # This allows NGP to rate-limit individual devices, not just the proxy
    RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}e"
</Location>
```

**Note**: After configuring the proxy, you must also configure NGP to trust the proxy IP - see "Configure Trusted Proxies" section below.

#### Option B: Strip /gateway/ Prefix (Alternative)

If you prefer to strip the `/gateway/` prefix before proxying:

**Apache (requires modules enabled as shown in Option A above):**
```apache
<Location /gateway>
    # Forward original client IP for rate limiting
    RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}e"

    RewriteEngine On
    RewriteRule ^/gateway/(.*)$ https://config.example.com/$1 [P,L]
    ProxyPassReverse https://config.example.com/
</Location>
```

### Configure Trusted Proxies (Required for Rate Limiting)

When NGP is behind a proxy, you **must** configure trusted proxy IPs so rate limiting works correctly. Without this, all requests appear to come from the proxy IP, making rate limiting ineffective.

**Edit `/var/www/config-gen/config/config.php`:**

```php
// Trusted Proxies (for X-Forwarded-For support)
// Add your NDP server IPs here
'trusted_proxies' => [
    '192.168.1.100',  // NDP server 1
    '10.0.0.50',      // NDP server 2
],
```

**How It Works:**
1. NDP proxy forwards original device IP in `X-Forwarded-For` header
2. NGP checks if request comes from a trusted proxy IP
3. If trusted, NGP uses the `X-Forwarded-For` IP for rate limiting
4. If not trusted, NGP ignores `X-Forwarded-For` (security protection)
5. Each device gets its own rate limit, not shared with the proxy

**Important**: Only add IPs of servers you control. Untrusted sources could spoof client IPs.

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

#### Test 5: Verify Rate Limiting with Proxy
```bash
# Enable debug logging in config.php:
'logging' => ['level' => 'debug']

# Make a request through the proxy
curl -u username:password http://ndp-server.com/gateway/C074AD893044.cfg

# Check logs - should show original device IP, not proxy IP:
tail -f /var/www/config-gen/logs/ngp.log | grep "Rate limit"
# Should see: "Rate limit: Using X-Forwarded-For (trusted proxy 192.168.1.100): <device-ip>"
# NOT: "Rate limit: Using REMOTE_ADDR"

# If you see REMOTE_ADDR instead of X-Forwarded-For:
# 1. Check NDP proxy has RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}e"
# 2. Check config.php has NDP IP in trusted_proxies array
# 3. Restart Apache on NDP server: sudo systemctl restart apache2
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
- [ ] **IP Restrictions**: Enable Apache IP restrictions to only allow NDP server (see Security Notes)
- [ ] **Firewall Rules**: Configure firewall to only allow NDP server to access NGP server
- [ ] **API Keys**: Use production ns-api credentials
- [ ] **Trusted Proxies**: Configure trusted proxy IPs in `config/config.php` for rate limiting
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

### Rate Limiting Not Working with Proxy

**Symptom**: All devices get blocked when one device fails authentication too many times

**Cause**: NGP is using proxy IP for rate limiting instead of original device IP

**Check**:
1. Verify NDP proxy sends `X-Forwarded-For` header:
   ```bash
   # On NDP server Apache config
   grep -r "X-Forwarded-For" /etc/apache2/
   # Should show: RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}e"
   ```

2. Verify NGP config has trusted proxies:
   ```bash
   # On NGP server
   grep -A 5 "trusted_proxies" /var/www/config-gen/config/config.php
   # Should show your NDP server IPs
   ```

3. Check NGP logs to see which IP is being used:
   ```bash
   # Enable debug logging first
   tail -f /var/www/config-gen/logs/ngp.log | grep "Rate limit"

   # Make a test request, look for:
   # "Rate limit: Using X-Forwarded-For (trusted proxy X.X.X.X): Y.Y.Y.Y"
   ```

4. If still using `REMOTE_ADDR`:
   - Verify headers module loaded on NDP: `apache2ctl -M | grep headers`
   - Restart Apache on NDP server: `sudo systemctl restart apache2`
   - Clear any existing rate limit locks: `rm /var/www/config-gen/logs/ratelimit/*.json`

## Proxy URL Patterns

The NGP server handles multiple URL patterns:

| Device Request | NDP Receives | NDP Proxies | NGP Receives |
|----------------|--------------|-------------|---------------------|
| `/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` | → `https://config.example.com/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` ✓ |
| `/gateway/{MAC}.cfg` | `/gateway/{MAC}.cfg` | → `https://config.example.com/{MAC}.cfg` | `/{MAC}.cfg` ✓ |

Both patterns are supported by the `.htaccess` rewrite rules.

## Security Notes

### IP Restriction (Recommended)

Restrict NGP access to only your NetSapiens NDP proxy servers using one of these methods:

#### Apache .htaccess Method
Add to `/var/www/config-gen/public/.htaccess`:
```apache
# Allow only from NDP proxy servers
Order Deny,Allow
Deny from all
Allow from 192.168.1.100
Allow from 10.0.0.50
# Add more NDP server IPs as needed
```

#### Apache VirtualHost Method (Preferred)
In your Apache virtual host configuration `/etc/apache2/sites-available/config-gen.conf`:
```apache
<Directory /var/www/config-gen/public>
    # Only allow NDP proxy server IPs
    Require ip 192.168.1.100
    Require ip 10.0.0.50
    # IPv6 if needed
    # Require ip 2001:db8::1
</Directory>
```

After making changes:
```bash
sudo systemctl reload apache2
```

#### Firewall Method (OS Level)
Using iptables to block all except NDP servers:
```bash
# Allow only from specific IPs
sudo iptables -A INPUT -p tcp --dport 443 -s 192.168.1.100 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -s 10.0.0.50 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -j DROP

# Save rules
sudo netfilter-persistent save
```

### Additional Security Practices

- **Network Isolation**: Deploy NGP server on private network only accessible from NDP server
- **HTTPS Required**: All proxy communication should use HTTPS with valid certificates
- **Credential Rotation**: Regularly rotate API keys and static authentication credentials
- **Log Monitoring**: Monitor logs for suspicious activity or authentication failures
- **Rate Limiting**: Built-in rate limiting helps prevent brute force attacks

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
