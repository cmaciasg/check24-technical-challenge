<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\Uid\UuidV4;

/** Identificador de la petición en curso: une todas sus líneas de log. */
final class RequestIdHolder
{
    private const ACCEPTED_FORMAT = '/^[A-Za-z0-9-]{8,64}$/';

    private ?string $requestId = null;

    /** Usa la cabecera solo si cumple el formato; si no, genera un UUID v4 (no se admite texto arbitrario en los logs). */
    public function startRequest(?string $incomingHeader): string
    {
        $this->requestId = null !== $incomingHeader && 1 === preg_match(self::ACCEPTED_FORMAT, $incomingHeader)
            ? $incomingHeader
            : (string) new UuidV4();

        return $this->requestId;
    }

    public function current(): string
    {
        return $this->requestId ??= (string) new UuidV4();
    }
}
