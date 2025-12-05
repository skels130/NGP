<?php
/**
 * Template Selector - Finds appropriate template files using directory hierarchy
 * Structure: /templates/{brand}/{model}/config.xml
 */
class TemplateSelector
{
    private $config;
    private $logger;
    private $basePath;
    private $templateFileName;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->basePath = rtrim($config['base_path'] ?? '/templates', '/');
        $this->templateFileName = $config['template_filename'] ?? 'config.xml';
    }

    /**
     * Select the appropriate template based on device brand and model
     * Uses directory hierarchy: /templates/{brand}/{model}/config.xml
     *
     * Fallback order:
     * 1. /templates/{brand}/{model}/config.xml - Exact model match
     * 2. /templates/{brand}/default/config.xml - Brand fallback (optional)
     *
     * @param string|null $brand Device brand/manufacturer
     * @param string|null $model Device model
     * @return string|null Path to template file or null if not found
     */
    public function selectTemplate(?string $brand, ?string $model): ?string
    {
        if (!$brand || !$model) {
            $this->logger->error("Brand or model is null, cannot select template");
            return null;
        }

        // Normalize brand and model (lowercase, trim, sanitize for filesystem)
        $brand = $this->sanitizePathComponent($brand);
        $model = $this->sanitizePathComponent($model);

        $this->logger->debug("Selecting template for brand='$brand', model='$model'");

        // 1. Try exact brand/model match: /templates/{brand}/{model}/config.xml
        $exactPath = $this->buildTemplatePath($brand, $model);
        if ($this->validateTemplate($exactPath)) {
            $this->logger->info("Found exact template match: $exactPath");
            return $exactPath;
        }

        // 2. Try brand default: /templates/{brand}/default/config.xml (optional fallback)
        $brandDefaultPath = $this->buildTemplatePath($brand, 'default');
        if ($this->validateTemplate($brandDefaultPath)) {
            $this->logger->info("Found brand default template: $brandDefaultPath");
            return $brandDefaultPath;
        }

        // No template found
        $this->logger->error("No template found for brand='$brand', model='$model'");
        return null;
    }

    /**
     * Build template path from brand and model
     *
     * @param string $brand Brand name (sanitized)
     * @param string $model Model name (sanitized)
     * @return string Full path to template file
     */
    private function buildTemplatePath(string $brand, string $model): string
    {
        return $this->basePath . '/' . $brand . '/' . $model . '/' . $this->templateFileName;
    }


    /**
     * Validate that a template file exists and is readable
     * Also ensures the template is within the base path (prevents path traversal)
     *
     * @param string $templatePath Path to template file
     * @return bool True if template exists and is readable
     */
    private function validateTemplate(string $templatePath): bool
    {
        // Resolve to real path and verify it's within base path
        $realPath = realpath($templatePath);
        $realBasePath = realpath($this->basePath);

        if ($realPath === false || $realBasePath === false) {
            return false;
        }

        // Ensure the template is within the base path (prevents path traversal)
        if (strpos($realPath, $realBasePath) !== 0) {
            $this->logger->warning("Path traversal attempt detected: $templatePath");
            return false;
        }

        // Check if it's a file and readable
        if (!is_file($realPath) || !is_readable($realPath)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize a path component (brand or model name) for filesystem use
     * Converts to lowercase, removes special characters, keeps alphanumeric and hyphens
     *
     * @param string $component Path component to sanitize
     * @return string Sanitized component
     */
    private function sanitizePathComponent(string $component): string
    {
        // Convert to lowercase and trim
        $component = strtolower(trim($component));

        // Replace spaces with hyphens
        $component = str_replace(' ', '-', $component);

        // Remove any character that's not alphanumeric, hyphen, or underscore
        $component = preg_replace('/[^a-z0-9\-_]/', '', $component);

        return $component;
    }

    /**
     * Get the base path for templates
     *
     * @return string Base path
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * List all available templates in the templates directory
     * Scans the filesystem and returns brand/model combinations
     *
     * @return array Array of templates with brand, model, and path
     */
    public function listAvailableTemplates(): array
    {
        $templates = [];

        if (!is_dir($this->basePath)) {
            return $templates;
        }

        // Scan brand directories
        $brands = scandir($this->basePath);
        foreach ($brands as $brand) {
            if ($brand === '.' || $brand === '..') {
                continue;
            }

            $brandPath = $this->basePath . '/' . $brand;
            if (!is_dir($brandPath)) {
                continue;
            }

            // Scan model directories
            $models = scandir($brandPath);
            foreach ($models as $model) {
                if ($model === '.' || $model === '..') {
                    continue;
                }

                $modelPath = $brandPath . '/' . $model;
                if (!is_dir($modelPath)) {
                    continue;
                }

                $templatePath = $modelPath . '/' . $this->templateFileName;
                if (file_exists($templatePath)) {
                    $templates[] = [
                        'brand' => $brand,
                        'model' => $model,
                        'path' => $templatePath,
                    ];
                }
            }
        }

        return $templates;
    }
}
