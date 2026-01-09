<?php
/**
 * Script to convert static P-codes to conditional pattern
 *
 * Converts: <P####>value</P####>
 * To:       {{if P####}}<P####>{{P####}}</P####>{{else}}<P####>value</P####>{{endif}}
 *
 * Skips P-codes that:
 * - Are inside existing conditional blocks ({{if ...}} ... {{endif}})
 * - Already contain template variables ({{...}})
 */

if ($argc < 2) {
    echo "Usage: php convert_pcodes.php <template_file>\n";
    exit(1);
}

$inputFile = $argv[1];
$dryRun = in_array('--dry-run', $argv);

if (!file_exists($inputFile)) {
    echo "Error: File not found: $inputFile\n";
    exit(1);
}

$content = file_get_contents($inputFile);
$lines = explode("\n", $content);
$output = [];
$converted = 0;
$skippedVariable = 0;
$skippedNested = 0;

// Track nesting depth of conditionals
$conditionalDepth = 0;

for ($i = 0; $i < count($lines); $i++) {
    $line = $lines[$i];

    // Track conditional depth
    // Count opening conditionals (but not P-code conditionals we might create)
    if (preg_match('/\{\{if\s+(?!P\d+\}\})/', $line)) {
        $conditionalDepth++;
    }

    // Check for endif to decrease depth
    if (preg_match('/\{\{endif\}\}/', $line)) {
        $conditionalDepth = max(0, $conditionalDepth - 1);
        $output[] = $line;
        continue;
    }

    // Skip if we're inside a non-P-code conditional
    if ($conditionalDepth > 0) {
        $output[] = $line;

        // Still need to track P-code conditionals for proper depth counting
        if (preg_match('/\{\{if\s+P\d+\}\}/', $line)) {
            // This is a P-code conditional, track it for endif matching
            $conditionalDepth++;
        }

        continue;
    }

    // Check if this line starts a P-code conditional block (already converted)
    if (preg_match('/\{\{if P\d+\}\}/', $line)) {
        // This is an existing P-code conditional, skip the entire block
        $output[] = $line;
        $nestedDepth = 1;
        $i++;
        while ($i < count($lines) && $nestedDepth > 0) {
            if (preg_match('/\{\{if\s+/', $lines[$i])) {
                $nestedDepth++;
            }
            if (preg_match('/\{\{endif\}\}/', $lines[$i])) {
                $nestedDepth--;
            }
            $output[] = $lines[$i];
            $i++;
        }
        $i--; // Adjust for loop increment
        $skippedNested++;
        continue;
    }

    // Match P-code with static value: <P####>value</P####>
    // But NOT if it contains {{ (template variable)
    if (preg_match('/^(\s*)<P(\d+)>([^<]*)<\/P\2>(\s*)$/', $line, $matches)) {
        $indent = $matches[1];
        $pcode = $matches[2];
        $value = $matches[3];
        $trailing = $matches[4];

        // Skip if value contains template variable
        if (strpos($value, '{{') !== false) {
            $output[] = $line;
            $skippedVariable++;
            continue;
        }

        // Convert to conditional pattern
        $output[] = $indent . "{{if P{$pcode}}}";
        $output[] = $indent . "<P{$pcode}>{{P{$pcode}}}</P{$pcode}>";
        $output[] = $indent . "{{else}}";
        $output[] = $indent . "<P{$pcode}>{$value}</P{$pcode}>";
        $output[] = $indent . "{{endif}}";
        $converted++;
    } else {
        $output[] = $line;
    }
}

echo "Converted: $converted P-codes\n";
echo "Skipped (variables): $skippedVariable P-codes\n";
echo "Skipped (nested): $skippedNested P-codes\n";

if (!$dryRun) {
    file_put_contents($inputFile, implode("\n", $output));
    echo "File updated: $inputFile\n";
} else {
    echo "Dry run - no changes made\n";
}
