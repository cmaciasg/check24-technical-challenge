<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Único punto que conoce a todos los providers a la vez. Lanza las consultas de
 * forma concurrente y devuelve un ProviderResult por provider, sin traducirlo.
 *
 * Cada provider corre en una Fiber: su adapter lanza la petición HTTP sin
 * bloquear y cede el control (ProviderHttpCall), de modo que las peticiones de
 * todos los providers están en vuelo a la vez antes de esperar la primera respuesta.
 */
final class ComparisonService
{
    /** @param iterable<ProviderPort> $providers */
    public function __construct(
        #[AutowireIterator(ProviderPort::TAG)]
        private readonly iterable $providers,
    ) {
    }

    /** @return list<ProviderResult> */
    public function compare(QuoteRequest $request): array
    {
        $fibers = [];
        foreach ($this->providers as $provider) {
            $fiber = new \Fiber(static fn (): ProviderResult => $provider->quote($request));
            $fiber->start();
            $fibers[] = $fiber;
        }

        $results = [];
        foreach ($fibers as $fiber) {
            while (!$fiber->isTerminated()) {
                $fiber->resume();
            }
            $results[] = $fiber->getReturn();
        }

        return $results;
    }
}
