<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Solo detección de código muerto: análisis estático sin opiniones de estilo (testing.md §4.1).
// ECS ya cubre el formato; ampliar los sets de Rector reescribiría el código existente sin
// necesidad, fuera del alcance de esta iteración.
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withSkip([__DIR__.'/tests/bootstrap.php'])
    ->withPreparedSets(deadCode: true);
