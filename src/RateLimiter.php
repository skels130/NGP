<?php
/**
 * Rate Limiter for authentication attempts
 * Prevents brute force attacks by limiting attempts per IP address
 */
class RateLimiter
{
    private $logger;
    private $storageDir;
    private $maxAttempts;
    private $timeWindow;
    private $lockoutDuration;
    private $trustedProxies;

    /**
     * @param Logger $logger Logger instance
     * @param string $storageDir Directory for storing rate limit data
     * @param int $maxAttempts Maximum attempts allowed within time window
     * @param int $timeWindow Time window in seconds (default: 60)
     * @param int $lockoutDuration Lockout duration in seconds after max attempts (default: 300)
     * @param array $trustedProxies List of trusted proxy IPs (only trust X-Forwarded-For from these IPs)
     */
    public function __construct(Logger $logger, string $storageDir, int $maxAttempts = 20, int $timeWindow = 60, int $lockoutDuration = 300, array $trustedProxies = [])
    {
        $this->logger = $logger;
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxAttempts = $maxAttempts;
        $this->timeWindow = $timeWindow;
        $this->lockoutDuration = $lockoutDuration;
        $this->trustedProxies = $trustedProxies;

        // Create storage directory if it doesn't exist
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Check if IP address is currently rate limited
     *
     * @param string|null $ip IP address to check (uses $_SERVER['REMOTE_ADDR'] if null)
     * @return bool True if rate limited (too many attempts)
     */
    public function isRateLimited(?string $ip = null): bool
    {
        $ip = $ip ?? $this->getClientIP();
        $attempts = $this->getAttempts($ip);

        // Check if in lockout period
        if (isset($attempts['lockout_until']) && time() < $attempts['lockout_until']) {
            $this->logger->warning("Rate limit: IP $ip is locked out until " . date('Y-m-d H:i:s', $attempts['lockout_until']));
            return true;
        }

        // Check if too many attempts in time window
        $recentAttempts = $this->countRecentAttempts($attempts);
        if ($recentAttempts >= $this->maxAttempts) {
            // Trigger lockout
            $this->lockout($ip);
            $this->logger->warning("Rate limit: IP $ip exceeded max attempts ($recentAttempts/$this->maxAttempts)");
            return true;
        }

        return false;
    }

    /**
     * Record a failed authentication attempt
     *
     * @param string|null $ip IP address (uses $_SERVER['REMOTE_ADDR'] if null)
     */
    public function recordFailedAttempt(?string $ip = null): void
    {
        $ip = $ip ?? $this->getClientIP();
        $attempts = $this->getAttempts($ip);

        // Add new attempt
        $attempts['attempts'][] = time();

        // Clean old attempts outside time window
        $attempts['attempts'] = array_filter($attempts['attempts'], function($timestamp) {
            return $timestamp > (time() - $this->timeWindow);
        });

        $this->saveAttempts($ip, $attempts);
        $this->logger->debug("Rate limit: Recorded failed attempt for IP $ip (total: " . count($attempts['attempts']) . ")");
    }

    /**
     * Record a successful authentication (clears attempts)
     *
     * @param string|null $ip IP address (uses $_SERVER['REMOTE_ADDR'] if null)
     */
    public function recordSuccess(?string $ip = null): void
    {
        $ip = $ip ?? $this->getClientIP();
        $this->clearAttempts($ip);
        $this->logger->debug("Rate limit: Cleared attempts for IP $ip after successful auth");
    }

    /**
     * Get remaining attempts before rate limit
     *
     * @param string|null $ip IP address (uses $_SERVER['REMOTE_ADDR'] if null)
     * @return int Number of remaining attempts
     */
    public function getRemainingAttempts(?string $ip = null): int
    {
        $ip = $ip ?? $this->getClientIP();
        $attempts = $this->getAttempts($ip);
        $recentAttempts = $this->countRecentAttempts($attempts);

        return max(0, $this->maxAttempts - $recentAttempts);
    }

    /**
     * Lock out an IP address
     *
     * @param string $ip IP address
     */
    private function lockout(string $ip): void
    {
        $attempts = $this->getAttempts($ip);
        $attempts['lockout_until'] = time() + $this->lockoutDuration;
        $this->saveAttempts($ip, $attempts);
    }

    /**
     * Clear all attempts for an IP address
     *
     * @param string $ip IP address
     */
    private function clearAttempts(string $ip): void
    {
        $file = $this->getStorageFile($ip);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Get attempts data for IP address
     *
     * @param string $ip IP address
     * @return array Attempts data
     */
    private function getAttempts(string $ip): array
    {
        $file = $this->getStorageFile($ip);

        if (!file_exists($file)) {
            return ['attempts' => []];
        }

        $data = file_get_contents($file);
        $attempts = json_decode($data, true);

        return $attempts ?: ['attempts' => []];
    }

    /**
     * Save attempts data for IP address
     *
     * @param string $ip IP address
     * @param array $attempts Attempts data
     */
    private function saveAttempts(string $ip, array $attempts): void
    {
        $file = $this->getStorageFile($ip);
        file_put_contents($file, json_encode($attempts), LOCK_EX);
        chmod($file, 0640);
    }

    /**
     * Count recent attempts within time window
     *
     * @param array $attempts Attempts data
     * @return int Count of recent attempts
     */
    private function countRecentAttempts(array $attempts): int
    {
        if (!isset($attempts['attempts']) || !is_array($attempts['attempts'])) {
            return 0;
        }

        $cutoff = time() - $this->timeWindow;
        $recent = array_filter($attempts['attempts'], function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });

        return count($recent);
    }

    /**
     * Get storage file path for IP address
     *
     * @param string $ip IP address
     * @return string File path
     */
    private function getStorageFile(string $ip): string
    {
        // Use hash to avoid storing raw IPs in filenames
        $hash = hash('sha256', $ip . 'rate_limit_salt');
        return $this->storageDir . '/ratelimit_' . $hash . '.json';
    }

    /**
     * Get client IP address
     * Only trusts X-Forwarded-For header when request comes from a trusted proxy
     *
     * @return string Client IP address
     */
    private function getClientIP(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // If no trusted proxies configured, use REMOTE_ADDR directly (safer default)
        if (empty($this->trustedProxies)) {
            $this->logger->debug("Rate limit: Using REMOTE_ADDR (no trusted proxies): $remoteAddr");
            return $remoteAddr;
        }

        // Only trust X-Forwarded-For if request comes from a trusted proxy
        if ($this->isTrustedProxy($remoteAddr)) {
            // Try X-Forwarded-For first (standard proxy header)
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($ips[0]); // First IP is the original client
                $this->logger->debug("Rate limit: Using X-Forwarded-For (trusted proxy $remoteAddr): $clientIp");
                return $clientIp;
            }

            // Fall back to X-Real-IP
            if (isset($_SERVER['HTTP_X_REAL_IP'])) {
                $clientIp = $_SERVER['HTTP_X_REAL_IP'];
                $this->logger->debug("Rate limit: Using X-Real-IP (trusted proxy $remoteAddr): $clientIp");
                return $clientIp;
            }
        } else {
            $this->logger->debug("Rate limit: Untrusted proxy $remoteAddr, using REMOTE_ADDR");
        }

        // Fall back to direct connection IP
        return $remoteAddr;
    }

    /**
     * Check if IP is a trusted proxy
     *
     * @param string $ip IP address to check
     * @return bool True if IP is in trusted proxies list
     */
    private function isTrustedProxy(string $ip): bool
    {
        return in_array($ip, $this->trustedProxies, true);
    }

    /**
     * Clean up old rate limit files
     * Should be called periodically (e.g., via cron)
     */
    public function cleanup(): void
    {
        $files = glob($this->storageDir . '/ratelimit_*.json');
        $cutoff = time() - $this->lockoutDuration - $this->timeWindow;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $this->logger->debug("Rate limit: Cleaned up old file $file");
            }
        }
    }
}
