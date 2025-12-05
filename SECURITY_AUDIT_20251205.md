# Security Audit Report
**Date:** December 5, 2025
**Auditor:** Claude (Automated Security Review)
**Application:** NGP (NetSapiens Gateway Provisioning)

## Executive Summary

This security audit identified **12 issues** across various severity levels:
- **CRITICAL:** 3 issues
- **HIGH:** 4 issues
- **MEDIUM:** 3 issues
- **LOW:** 2 issues

**Immediate action required** on all CRITICAL and HIGH severity issues before production deployment.

---

## Critical Severity Issues

### 1. XML Injection Vulnerability
**File:** `src/TemplateParser.php:110`
**Risk:** Code Injection, Data Manipulation
**CVSS Score:** 9.1 (Critical)

**Issue:**
Variables are substituted directly into XML output without escaping. If untrusted data from ns-api contains XML special characters, it could break XML structure or inject malicious content.

**Example Attack:**
```php
// If username = "</P47><P999>ATTACK</P999><P47>"
// Output: <P47></P47><P999>ATTACK</P999><P47></P47>
```

**Recommendation:**
```php
private function processVariables(string $content): string
{
    $pattern = '/\{\{([a-zA-Z0-9_\.]+)\}\}/';
    
    return preg_replace_callback($pattern, function ($matches) {
        $varPath = $matches[1];
        $value = $this->getVariableValue($varPath);
        
        // XML-escape the value
        return $value !== null ? htmlspecialchars($value, ENT_XML1, 'UTF-8') : '';
    }, $content);
}
```

---

### 2. Information Disclosure via Debug Mode
**File:** `public/index.php:8-9`
**Risk:** Information Leakage
**CVSS Score:** 8.2 (High)

**Issue:**
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```
These settings expose detailed error messages including file paths, stack traces, and internal application structure to attackers.

**Recommendation:**
```php
// Production settings
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
```

---

### 3. Path Traversal Risk
**File:** `src/TemplateSelector.php:74`
**Risk:** Arbitrary File Read
**CVSS Score:** 7.5 (High)

**Issue:**
While path components are sanitized, there's no validation that the final resolved path is within `$basePath`. Symlinks or other filesystem tricks could potentially bypass sanitization.

**Recommendation:**
```php
private function validateTemplate(string $templatePath): bool
{
    // Resolve to real path and verify it's within base path
    $realPath = realpath($templatePath);
    $realBasePath = realpath($this->basePath);
    
    if ($realPath === false || $realBasePath === false) {
        return false;
    }
    
    // Ensure the template is within the base path
    if (strpos($realPath, $realBasePath) !== 0) {
        $this->logger->warning("Path traversal attempt: $templatePath");
        return false;
    }
    
    return is_file($realPath) && is_readable($realPath);
}
```

---

## High Severity Issues

### 4. Timing Attack on Authentication
**File:** `src/Auth.php:68, 104`
**Risk:** Password Enumeration
**CVSS Score:** 6.8 (Medium)

**Issue:**
Password comparison uses `===` operator which is vulnerable to timing attacks. An attacker could potentially determine password length and characters by measuring response times.

**Recommendation:**
```php
// Use hash_equals for timing-safe comparison
if (hash_equals($username, $this->config['username']) && 
    hash_equals($password, $this->config['password'])) {
    return true;
}
```

---

### 5. No Rate Limiting
**File:** `src/Auth.php`, `public/index.php`
**Risk:** Brute Force Attacks
**CVSS Score:** 6.5 (Medium)

**Issue:**
No rate limiting on authentication attempts allows unlimited brute force attacks.

**Recommendation:**
Implement rate limiting:
- Max 5 failed attempts per IP per minute
- Progressive delays after failures
- Account lockout after 10 failed attempts
- Consider implementing fail2ban integration

---

### 6. Weak Random String Generation
**File:** `src/NsApiClient.php:281-292`
**Risk:** Predictable Credentials
**CVSS Score:** 6.0 (Medium)

**Issue:**
The character set for random generation includes only alphanumeric characters. While `random_int()` is cryptographically secure, the entropy could be improved.

**Recommendation:**
```php
private function generateRandomString(int $length): string
{
    // Use full ASCII printable range for higher entropy
    $bytes = random_bytes($length);
    return substr(base64_encode($bytes), 0, $length);
}
```
Or use special characters:
```php
$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()';
```

---

### 7. Log File Permissions
**File:** System configuration
**Risk:** Information Disclosure
**CVSS Score:** 5.5 (Medium)

**Issue:**
Log file `/var/www/ngp/logs/ngp.log` has 644 permissions, making it world-readable. Logs may contain sensitive information including MAC addresses, API responses, and debugging data.

**Recommendation:**
```bash
chmod 640 /var/www/ngp/logs/*.log
```

---

## Medium Severity Issues

### 8. API Key in Configuration File
**File:** `config/config.php:21`
**Risk:** Credential Exposure
**CVSS Score:** 5.0 (Medium)

**Issue:**
API key stored in plain text in configuration file. While file permissions are restrictive (640), any compromise of the web server exposes the key.

**Recommendation:**
- Store API key in environment variable: `$_ENV['NSAPI_KEY']`
- Or use encrypted configuration with a key stored outside web root
- Implement key rotation policy
- Use read-only API keys when possible

---

### 9. No HTTPS Enforcement
**File:** `public/.htaccess:15-17`
**Risk:** Man-in-the-Middle Attacks
**CVSS Score:** 7.4 (High)

**Issue:**
HTTPS enforcement is commented out:
```apache
# RewriteCond %{HTTPS} off
# RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

**Recommendation:**
Enable HTTPS enforcement and add HSTS header:
```apache
# Force HTTPS
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Add HSTS header
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
```

---

### 10. Missing Security Headers
**File:** `public/index.php`, `.htaccess`
**Risk:** Various client-side attacks
**CVSS Score:** 4.5 (Medium)

**Issue:**
Missing important security headers:
- X-Frame-Options
- X-Content-Type-Options
- Content-Security-Policy
- Referrer-Policy

**Recommendation:**
Add to `.htaccess`:
```apache
<IfModule mod_headers.c>
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Content-Security-Policy "default-src 'self'"
</IfModule>
```

---

## Low Severity Issues

### 11. Verbose Error Messages in API Responses
**File:** `src/NsApiClient.php:186-188`
**Risk:** Information Disclosure
**CVSS Score:** 3.0 (Low)

**Issue:**
Error messages in logs may contain full API responses including sensitive data.

**Recommendation:**
Sanitize API responses in error logs, removing sensitive fields.

---

### 12. No Input Length Limits
**File:** Multiple
**Risk:** Denial of Service
**CVSS Score:** 3.5 (Low)

**Issue:**
No validation of input length for MAC addresses, brand names, model names. Very long inputs could cause resource exhaustion.

**Recommendation:**
Add length validation:
```php
if (strlen($macAddress) > 12) {
    throw new Exception("Invalid MAC address length");
}
```

---

## Security Best Practices Recommendations

### 1. Implement Content Security Policy
Add CSP headers to prevent XSS attacks.

### 2. Add Request ID Tracking
Include unique request IDs in logs for better audit trails.

### 3. Implement API Response Validation
Validate that API responses match expected schema before processing.

### 4. Add Health Check Endpoint
Create a `/health` endpoint for monitoring (without authentication).

### 5. Implement Logging Rotation
Configure log rotation to prevent disk space exhaustion:
```bash
/var/www/ngp/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 640 www-data www-data
}
```

### 6. Database for Audit Trail
Consider adding database logging for authentication attempts and configuration requests for better auditing.

---

## Compliance Considerations

### PCI DSS
If handling payment card data:
- Implement TLS 1.2 minimum
- Enable full request/response logging
- Implement key rotation

### GDPR
- Log retention policy needed
- Consider PII in MAC addresses
- Implement data subject access controls

---

## Priority Fix Recommendations

**Before Production Deployment:**
1. ✅ Fix XML Injection (Issue #1) - CRITICAL
2. ✅ Disable display_errors (Issue #2) - CRITICAL
3. ✅ Add path traversal protection (Issue #3) - CRITICAL
4. ✅ Enable HTTPS enforcement (Issue #9) - HIGH
5. ✅ Implement rate limiting (Issue #5) - HIGH
6. ✅ Fix timing attacks (Issue #4) - HIGH

**Within 30 Days:**
7. Fix log permissions (Issue #7)
8. Add security headers (Issue #10)
9. Move API key to environment variable (Issue #8)
10. Improve random string generation (Issue #6)

**Nice to Have:**
11. Address information disclosure (Issue #11)
12. Add input length validation (Issue #12)

---

## Testing Recommendations

1. **Penetration Testing**: Conduct external penetration test before production
2. **Fuzzing**: Fuzz test template parser with malformed XML
3. **Load Testing**: Test rate limiting under load
4. **Security Scanning**: Run OWASP ZAP or similar tool

---

## Conclusion

The application has a solid foundation but requires immediate attention to critical security issues before production deployment. The authentication system and template parsing are the primary areas of concern.

**Estimated Remediation Time:** 16-24 hours
**Recommended Review Cycle:** Quarterly security audits

---

**Report Generated:** $(date)
**Next Review Due:** $(date -d '+3 months' +%Y-%m-%d)
