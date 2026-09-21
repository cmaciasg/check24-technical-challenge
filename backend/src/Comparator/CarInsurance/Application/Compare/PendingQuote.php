<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Application\Compare;

interface PendingQuote
{
    /**
     * Segundo paso: espera el resultado (o el vencimiento del timeout) y lo traduce.
     * Nunca lanza excepción por un fallo esperado del partner (timeout, HTTP de error,
     * respuesta malformada): esos casos se modelan como ProviderResult::failure().
     * Se llama exactamente una vez por PendingQuote.
     */
    public function collect(): ProviderResult;
}
