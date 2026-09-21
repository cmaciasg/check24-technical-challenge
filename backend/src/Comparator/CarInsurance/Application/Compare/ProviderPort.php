<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag(ProviderPort::TAG)]
interface ProviderPort
{
    public const TAG = 'app.comparator.provider';

    public function id(): ProviderId;

    /** Nombre comercial legible del provider, pensado para mostrarse al usuario. */
    public function name(): ProviderName;

    /**
     * Primer paso: construye el payload y lanza la consulta al provider sin esperar
     * su respuesta. Nunca lanza excepción por un fallo esperado del partner: si algo
     * falla al lanzar, devuelve un PendingQuote ya resuelto a ProviderResult::failure().
     * Solo debería propagar una excepción ante un error de programación (p. ej. un
     * QuoteRequest inválido que ya debería haber sido validado antes de llegar aquí).
     */
    public function start(QuoteRequest $request): PendingQuote;
}
