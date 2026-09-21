<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Único punto que conoce a todos los providers a la vez. Lanza las consultas de todos
 * (start()) antes de esperar la respuesta de ninguno y después las recoge en el orden
 * en que se lanzaron (collect()), devolviendo un ProviderResult por provider sin traducirlo.
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
        $pending = [];
        foreach ($this->providers as $provider) {
            $pending[] = $provider->start($request);
        }

        return array_map(static fn (PendingQuote $quote): ProviderResult => $quote->collect(), $pending);
    }
}
