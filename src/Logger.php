<?php
/**
 * Simple logging class
 */
class Logger
{
    private $config;
    private $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(array $config)
    {
        $this->config = $config;

        // Create logs directory if it doesn't exist
        if ($this->config['enabled']) {
            $logDir = dirname($this->config['path']);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
        }
    }

    /**
     * Log a debug message
     */
    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    /**
     * Log an info message
     */
    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    /**
     * Log a warning message
     */
    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    /**
     * Log an error message
     */
    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    /**
     * Write log message to file
     */
    private function log(string $level, string $message): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        // Check if this level should be logged
        $configLevel = $this->config['level'] ?? 'info';
        if ($this->levels[$level] < $this->levels[$configLevel]) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [" . strtoupper($level) . "] $message" . PHP_EOL;

        file_put_contents($this->config['path'], $logMessage, FILE_APPEND);
    }
}
