<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProviderSimulation;

use App\ProviderSimulation\RandomProviderOutcomePicker;
use App\ProviderSimulation\SimulatedOutcomeKind;
use App\ProviderSimulation\SimulationProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('TC-6')]
final class RandomProviderOutcomePickerTest extends TestCase
{
    /**
     * Las fronteras salen del reparto de C: 10 % pico (0–999), 3 % HTTP 500 (1000–1299),
     * 2 % payload malformado (1300–1499), resto normal (1500–9999).
     */
    #[DataProvider('providerCBoundaries')]
    public function testProviderCOutcomeAtEachBoundary(int $roll, SimulatedOutcomeKind $expected): void
    {
        self::assertSame($expected, $this->pick('provider-c', $roll, 200)->kind);
    }

    /** @return iterable<string, array{int, SimulatedOutcomeKind}> */
    public static function providerCBoundaries(): iterable
    {
        yield 'first value is a spike' => [0, SimulatedOutcomeKind::SPIKE];
        yield 'last spike' => [999, SimulatedOutcomeKind::SPIKE];
        yield 'first http error' => [1000, SimulatedOutcomeKind::HTTP_ERROR];
        yield 'last http error' => [1299, SimulatedOutcomeKind::HTTP_ERROR];
        yield 'first malformed' => [1300, SimulatedOutcomeKind::MALFORMED];
        yield 'last malformed' => [1499, SimulatedOutcomeKind::MALFORMED];
        yield 'first normal' => [1500, SimulatedOutcomeKind::NORMAL];
        yield 'last value is normal' => [9999, SimulatedOutcomeKind::NORMAL];
    }

    #[DataProvider('everyRoll')]
    public function testProvidersAAndBAlwaysAnswerNormally(int $roll): void
    {
        self::assertSame(SimulatedOutcomeKind::NORMAL, $this->pick('provider-a', $roll, 100)->kind);
        self::assertSame(SimulatedOutcomeKind::NORMAL, $this->pick('provider-b', $roll, 1500)->kind);
    }

    /** @return iterable<string, array{int}> */
    public static function everyRoll(): iterable
    {
        yield 'lowest' => [0];
        yield 'middle' => [5000];
        yield 'highest' => [9999];
    }

    public function testSpikeLatencyComesFromTheSpikeRange(): void
    {
        self::assertSame(3000, $this->pick('provider-c', 0, 3000)->latencyMs);
    }

    public function testLatencyIsDrawnFromTheProfileRangeOfTheOutcome(): void
    {
        $asked = [];
        $picker = new RandomProviderOutcomePicker($this->profiles(), static function (int $min, int $max) use (&$asked): int {
            $asked[] = [$min, $max];

            return $min;
        });

        $picker->pick('provider-b');
        $picker->pick('provider-c');

        self::assertSame([0, 9999], $asked[0]);
        self::assertSame([1200, 1800], $asked[1], 'provider-b normal latency range');
        self::assertSame([0, 9999], $asked[2]);
        self::assertSame([2500, 4000], $asked[3], 'provider-c spike range: roll 0 is a spike');
    }

    public function testWorksWithTheRealRandomGenerator(): void
    {
        $picker = new RandomProviderOutcomePicker($this->profiles());

        for ($i = 0; $i < 50; ++$i) {
            $outcome = $picker->pick('provider-c');
            self::assertGreaterThanOrEqual(150, $outcome->latencyMs);
            self::assertLessThanOrEqual(4000, $outcome->latencyMs);
        }
    }

    public function testUnknownProviderIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pick('provider-z', 0, 0);
    }

    private function pick(string $providerId, int $roll, int $latency): \App\ProviderSimulation\SimulatedOutcome
    {
        $values = [$roll, $latency];
        $picker = new RandomProviderOutcomePicker($this->profiles(), static function (int $min, int $max) use (&$values): int {
            return array_shift($values) ?? $min;
        });

        return $picker->pick($providerId);
    }

    private function profiles(): SimulationProfiles
    {
        return new SimulationProfiles(self::config());
    }

    /** @return array<string, array<string, mixed>> */
    public static function config(): array
    {
        return [
            'provider-a' => ['normal_latency_ms' => [80, 200]],
            'provider-b' => ['normal_latency_ms' => [1200, 1800]],
            'provider-c' => [
                'normal_latency_ms' => [150, 400],
                'spike_latency_ms' => [2500, 4000],
                'spike_percent' => 10,
                'http_error_percent' => 3,
                'malformed_percent' => 2,
            ],
        ];
    }
}
