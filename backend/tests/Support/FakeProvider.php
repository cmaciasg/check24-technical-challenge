<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Comparator\CarInsurance\Application\Compare\PendingQuote;
use App\Comparator\CarInsurance\Application\Compare\ProviderFailureReason;
use App\Comparator\CarInsurance\Application\Compare\ProviderPort;
use App\Comparator\CarInsurance\Application\Compare\ProviderResult;
use App\Comparator\CarInsurance\Application\Compare\ResolvedPendingQuote;
use App\Comparator\CarInsurance\Domain\Model\ComparisonOffer;
use App\Comparator\CarInsurance\Domain\Model\QuoteRequest;
use App\Comparator\CarInsurance\Domain\ValueObjects\CoverageLevel;
use App\Comparator\CarInsurance\Domain\ValueObjects\Price;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderId;
use App\Comparator\CarInsurance\Domain\ValueObjects\ProviderName;

/** ProviderPort falso: devuelve de inmediato un éxito o un fallo concreto y cuenta las llamadas. */
final class FakeProvider implements ProviderPort
{
    public int $calls = 0;

    private function __construct(
        private readonly string $id,
        private readonly ?ProviderFailureReason $failure,
        private readonly int $priceCents,
        private readonly int $elapsedMs,
    ) {
    }

    public static function succeeding(string $id, int $priceCents, int $elapsedMs = 100): self
    {
        return new self($id, null, $priceCents, $elapsedMs);
    }

    public static function failing(string $id, ProviderFailureReason $reason = ProviderFailureReason::TIMEOUT, int $elapsedMs = 2000): self
    {
        return new self($id, $reason, 0, $elapsedMs);
    }

    public function id(): ProviderId
    {
        return ProviderId::fromString($this->id);
    }

    public function name(): ProviderName
    {
        return ProviderName::fromString(strtoupper($this->id));
    }

    public function start(QuoteRequest $request): PendingQuote
    {
        ++$this->calls;

        return new ResolvedPendingQuote($this->result());
    }

    /** Atajo para los tests que solo necesitan el resultado: lanza y recoge en un paso. */
    public function quote(QuoteRequest $request): ProviderResult
    {
        return $this->start($request)->collect();
    }

    private function result(): ProviderResult
    {
        if (null !== $this->failure) {
            return ProviderResult::failure($this->id(), $this->name(), $this->failure, $this->elapsedMs);
        }

        return ProviderResult::success($this->id(), $this->name(), new ComparisonOffer(
            $this->id(),
            $this->name(),
            'REF-'.$this->id,
            Price::ofCents($this->priceCents),
            CoverageLevel::STANDARD,
        ), $this->elapsedMs);
    }
}
