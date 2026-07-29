<?php

declare(strict_types=1);

// Briefly AI — PHP CS Fixer configuration
// Standard: PSR-12 + @Symfony
// Paths: src/ + tests/

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCSIgnored(true);

return (new PhpCsFixer\Config())
    ->setRules([
        // Standards
        '@PSR12' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        // PHP 8.x modernes
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        // Lisibilité
        'blank_line_after_namespace' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
        'concat_space' => ['spacing' => 'one'],
        'heredoc_to_nowdoc' => true,
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'no_extra_blank_lines' => [
            'tokens' => ['extra', 'throw', 'use', 'use_trait'],
        ],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_order' => true,
        'return_type_declaration' => ['space_before' => 'none'],
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        // Sécurité (constitution §4)
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        // Pest 3 incompatibility: static closures break Pest's $this binding in test callbacks
        'static_lambda' => false,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
