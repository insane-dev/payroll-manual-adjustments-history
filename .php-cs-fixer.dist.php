<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

return (new Config())
    ->setRules([
        '@PSR12' => true,
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'multiline_promoted_properties' => ['minimum_number_of_parameters' => 1],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache')
    ->setFinder(Finder::create()
        ->in([__DIR__ . '/src', __DIR__ . '/bin', __DIR__ . '/tests'])
        ->append([__FILE__]));
