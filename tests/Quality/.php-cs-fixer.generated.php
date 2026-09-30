<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->files()
    ->in(__DIR__ . '/../../var/generated-quality')
    ->name('*.php');

$config = new PhpCsFixer\Config();
$config->setUsingCache(false);
$config->setRiskyAllowed(true);
$config->setRules([
    '@PER-CS2.0' => true,
    'blank_line_before_statement' => ['statements' => ['return', 'throw']],
    'declare_strict_types' => true,
    'no_extra_blank_lines' => true,
    'no_trailing_whitespace' => true,
    'no_unused_imports' => true,
    'nullable_type_declaration_for_default_null_value' => true,
    'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    'phpdoc_align' => true,
    'phpdoc_order' => true,
    'single_quote' => true,
    'strict_comparison' => true,
    'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
]);
$config->setFinder($finder);

return $config;
