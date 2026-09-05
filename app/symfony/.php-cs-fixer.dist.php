<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = new Finder()
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/public', __DIR__.'/bin', __DIR__.'/migrations'])
    ->exclude(['var', 'vendor'])
    ->notPath('reference.php')
    ->append([__FILE__, __DIR__.'/rector.php'])
;

return new Config()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'concat_space' => ['spacing' => 'none'],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true, 'remove_inheritdoc' => true],
        'phpdoc_to_comment' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
;
