<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;
use Rector\TypeDeclaration\Rector\FuncCall\AddArrowFunctionParamArrayWhereDimFetchRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // Rector's typeDeclarations infers `array` from ArrayAccess usage in closures,
        // breaking Laravel test APIs (Http::assertSent, singleton callbacks, etc.).
        // See CLAUDE.md learning: "Rector typeDeclarations misidentifies ArrayAccess objects".
        AddArrowFunctionReturnTypeRector::class => [__DIR__.'/tests'],
        AddArrowFunctionParamArrayWhereDimFetchRector::class => [__DIR__.'/tests'],
        ClosureReturnTypeRector::class => [__DIR__.'/tests'],
    ]);
