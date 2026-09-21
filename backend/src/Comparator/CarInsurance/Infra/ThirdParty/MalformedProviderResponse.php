<?php

declare(strict_types=1);

namespace App\Comparator\CarInsurance\Infra\ThirdParty;

/** Respuesta de un partner con campos ausentes, tipos inesperados o valores fuera de catálogo. */
final class MalformedProviderResponse extends \RuntimeException
{
}
