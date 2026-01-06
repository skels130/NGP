<?php
/**
 * Template Parser for configuration file generation
 * Supports variable substitution, conditionals, and loops
 */
class TemplateParser
{
    private $templatePath;
    private $logger;
    private $template;
    private $variables;

    public function __construct(string $templatePath, Logger $logger)
    {
        $this->templatePath = $templatePath;
        $this->logger = $logger;
    }

    /**
     * Parse template with provided variables
     *
     * @param array $variables Variables to substitute in template
     * @return string Parsed configuration
     */
    public function parse(array $variables): string
    {
        // Load template
        if (!file_exists($this->templatePath)) {
            throw new Exception("Template file not found: {$this->templatePath}");
        }

        $this->template = file_get_contents($this->templatePath);
        $this->variables = $variables;

        // Process template
        $output = $this->template;

        // 1. Process loops first ({{for...}}...{{endfor}})
        $output = $this->processLoops($output);

        // 2. Process conditionals ({{if...}}...{{endif}})
        $output = $this->processConditionals($output);

        // 3. Process variable substitutions ({{variable}})
        $output = $this->processVariables($output);

        return $output;
    }

    /**
     * Process loop constructs
     * Syntax: {{for var in start..end}}...{{endfor}}
     */
    private function processLoops(string $content): string
    {
        $pattern = '/\{\{for\s+(\w+)\s+in\s+(\d+)\.\.(\d+)\}\}(.*?)\{\{endfor\}\}/s';

        return preg_replace_callback($pattern, function ($matches) {
            $varName = $matches[1];
            $start = (int)$matches[2];
            $end = (int)$matches[3];
            $loopContent = $matches[4];

            $output = '';
            for ($i = $start; $i <= $end; $i++) {
                // Create temporary variable context for this iteration
                $iterationContent = str_replace("{{" . $varName . "}}", $i, $loopContent);
                $output .= $iterationContent;
            }

            return $output;
        }, $content);
    }

    /**
     * Process conditional statements
     * Syntax: {{if condition}}...{{endif}}
     * Syntax: {{if condition}}...{{else}}...{{endif}}
     */
    private function processConditionals(string $content): string
    {
        // Pattern for if-else-endif
        $pattern = '/\{\{if\s+(.*?)\}\}(.*?)(?:\{\{else\}\}(.*?))?\{\{endif\}\}/s';

        return preg_replace_callback($pattern, function ($matches) {
            $condition = trim($matches[1]);
            $ifContent = $matches[2];
            $elseContent = $matches[3] ?? '';

            // Evaluate condition
            $result = $this->evaluateCondition($condition);

            return $result ? $ifContent : $elseContent;
        }, $content);
    }

    /**
     * Process variable substitutions
     * Syntax: {{variable}} or {{object.property}}
     */
    private function processVariables(string $content): string
    {
        $pattern = '/\{\{([a-zA-Z0-9_\.]+)\}\}/';

        return preg_replace_callback($pattern, function ($matches) {
            $varPath = $matches[1];
            $value = $this->getVariableValue($varPath);

            // Return empty string if variable not found, otherwise XML-escape the value
            return $value !== null ? htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') : '';
        }, $content);
    }

    /**
     * Get variable value from dot notation path
     * e.g., "device_info.username" -> $variables['device_info']['username']
     */
    private function getVariableValue(string $path)
    {
        $parts = explode('.', $path);
        $value = $this->variables;

        foreach ($parts as $part) {
            if (is_array($value) && isset($value[$part])) {
                $value = $value[$part];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * Evaluate a condition expression
     * Supports: ==, !=, >, <, >=, <=, &&, ||, !
     */
    private function evaluateCondition(string $condition): bool
    {
        // First, extract and preserve quoted strings so we don't replace content inside them
        // Use a placeholder with NO letters so the variable regex won't match any part of it
        $quotedStrings = [];
        $condition = preg_replace_callback('/([\'"])([^\'"]*)\1/', function ($matches) use (&$quotedStrings) {
            $placeholder = '<<<' . count($quotedStrings) . '>>>';
            $quotedStrings[$placeholder] = $matches[0];
            return $placeholder;
        }, $condition);

        // Replace variables in condition (but not reserved words)
        $condition = preg_replace_callback('/([a-zA-Z][a-zA-Z0-9_\.]*)/', function ($matches) {
            $varName = $matches[1];

            // Check if it's a reserved word
            if (in_array($varName, ['true', 'false', 'null', 'and', 'or', 'not'])) {
                return $varName;
            }

            $value = $this->getVariableValue($varName);

            // Return the value as a string for comparison
            if ($value === null) {
                return 'null';
            } elseif (is_bool($value)) {
                return $value ? 'true' : 'false';
            } elseif (is_numeric($value)) {
                return $value;
            } else {
                return "'" . addslashes($value) . "'";
            }
        }, $condition);

        // Restore quoted strings
        foreach ($quotedStrings as $placeholder => $original) {
            $condition = str_replace($placeholder, $original, $condition);
        }

        // Replace logical operators
        $condition = str_replace(['&&', '||', '!'], ['and', 'or', 'not'], $condition);

        // Safely evaluate the condition
        try {
            // Use a safer evaluation method
            $result = $this->safeEval($condition);
            return (bool)$result;
        } catch (Exception $e) {
            $this->logger->warning("Failed to evaluate condition: $condition - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Safely evaluate a boolean expression
     * This is a simplified version - consider using a proper expression parser for production
     */
    private function safeEval(string $expression): bool
    {
        // Very basic and limited evaluation
        // For production, use a proper expression parser library

        // Handle simple comparisons
        if (preg_match('/^(.+?)\s*(==|!=|>|<|>=|<=)\s*(.+?)$/', $expression, $matches)) {
            $left = trim($matches[1]);
            $operator = $matches[2];
            $right = trim($matches[3]);

            // Remove quotes if present
            $left = trim($left, "'\"");
            $right = trim($right, "'\"");

            switch ($operator) {
                case '==': return $left == $right;
                case '!=': return $left != $right;
                case '>': return $left > $right;
                case '<': return $left < $right;
                case '>=': return $left >= $right;
                case '<=': return $left <= $right;
            }
        }

        // Handle boolean values
        $expression = trim($expression);
        if ($expression === 'true') return true;
        if ($expression === 'false') return false;
        if ($expression === 'null') return false;

        // Handle "not" operator
        if (strpos($expression, 'not ') === 0) {
            $innerExpr = trim(substr($expression, 4));
            return !$this->safeEval($innerExpr);
        }

        // Handle simple truthiness evaluation for quoted strings and numbers
        // Remove quotes if present
        $value = trim($expression, "'\"");

        // Empty string is falsy
        if ($value === '') return false;

        // Numeric zero is falsy
        if (is_numeric($value) && (float)$value == 0) return false;

        // Everything else is truthy (non-empty strings, non-zero numbers)
        return true;
    }
}
