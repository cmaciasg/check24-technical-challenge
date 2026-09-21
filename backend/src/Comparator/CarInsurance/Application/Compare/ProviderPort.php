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
     * Nunca lanza excepción por un fallo esperado del partner (timeout, HTTP de
     * error, respuesta malformada): esos casos se modelan como ProviderResult::failure().
     */
    public function quote(QuoteRequest $request): ProviderResult;
}
