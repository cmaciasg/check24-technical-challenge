<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([__DIR__.'/tests/bootstrap.php'])
    ->withPreparedSets(psr12: true)
    ->withConfiguredRule(\PhpCsFixer\Fixer\Operator\ConcatSpaceFixer::class, ['spacing' => 'none'])
    ->withRules([
        \PhpCsFixer\Fixer\Import\OrderedImportsFixer::class,
        \PhpCsFixer\Fixer\Import\NoUnusedImportsFixer::class,
        \PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer::class,
    ]);
