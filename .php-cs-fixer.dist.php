<?php

/**
 * The style of the package, as PHP CS Fixer reads it.
 *
 * PSR-12 with the two habits of this code kept: "else" and "catch" open a line of their own, and
 * the yoda conditions of PSR-12 are not asked for. Run it with the tool of the CI:
 *
 *     php-cs-fixer fix --dry-run --diff
 */

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        // } on one line, else { on the next - the way the sources are written
        'control_structure_continuation_position' => ['position' => 'next_line'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
