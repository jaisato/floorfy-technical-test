<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/migrations',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Auto-generated array-shape reference for config/*.php, not code.
        __DIR__.'/config/reference.php',
        // The suite calls assertions statically throughout; a test method is
        // not an object's behaviour and does not need $this to say so.
        PreferPHPUnitThisCallRector::class,
    ])
    ->withRootFiles()
    ->withCache(__DIR__.'/var/cache/rector')
    // PHP level comes from composer.json ("php": ">=8.4.3"); the version sets
    // for Symfony, Doctrine and PHPUnit come from the installed versions.
    ->withPhpSets()
    ->withComposerBased(doctrine: true, phpunit: true, symfony: true)
    ->withSets([
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
    ])
    ->withSymfonyContainerXml(__DIR__.'/var/cache/test/App_KernelTestDebugContainer.xml')
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
;
