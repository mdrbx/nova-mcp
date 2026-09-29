<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/config', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets(php83: true)
    // Laravel 13's CSRF middleware must remain a string when analysed against Laravel 12.
    ->withSkip([StringClassNameToClassConstantRector::class => [
        __DIR__.'/src/NovaApi.php',
        __DIR__.'/src/Http/Middleware/AuthenticateMcp.php',
    ]])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
