<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProviderSimulation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * TC-4 (a): el margen del timeout único respecto a los perfiles de latencia, para todos los
 * providers configurados. Falla si alguien cambia un rango y lo rompe; cubre providers nuevos.
 */
#[Group('TC-4')]
final class TimeoutConfigurationInvariantTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $parameters;

    protected function setUp(): void
    {
        $this->parameters = Yaml::parseFile(__DIR__.'/../../../config/services.yaml')['parameters'];
    }

    public function testNoNormalLatencyReachesTheTimeout(): void
    {
        $timeout = $this->parameters['app.providers.timeout_ms'];

        foreach ($this->parameters['app.providers.simulation'] as $providerId => $profile) {
            self::assertLessThan($timeout, $profile['normal_latency_ms'][1], sprintf('%s: a normal call must never time out', $providerId));
        }
    }

    public function testEverySpikeLatencyIsAboveTheTimeout(): void
    {
        $timeout = $this->parameters['app.providers.timeout_ms'];

        foreach ($this->parameters['app.providers.simulation'] as $providerId => $profile) {
            if (($profile['spike_percent'] ?? 0) > 0) {
                self::assertGreaterThan($timeout, $profile['spike_latency_ms'][0], sprintf('%s: a spike must always time out', $providerId));
            }
        }
    }

    public function testDocumentedMarginsHold(): void
    {
        $simulation = $this->parameters['app.providers.simulation'];

        self::assertSame(2000, $this->parameters['app.providers.timeout_ms']);
        self::assertSame(1800, $simulation['provider-b']['normal_latency_ms'][1]);
        self::assertSame(2500, $simulation['provider-c']['spike_latency_ms'][0]);
    }

    public function testFailureProbabilitiesOfProviderCMatchTheSpec(): void
    {
        $profile = $this->parameters['app.providers.simulation']['provider-c'];

        self::assertSame(10, $profile['spike_percent']);
        self::assertSame(3, $profile['http_error_percent']);
        self::assertSame(2, $profile['malformed_percent']);
    }
}
