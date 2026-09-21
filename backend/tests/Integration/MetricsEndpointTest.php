<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ProviderSimulation\SimulatedOutcomeKind;
use PHPUnit\Framework\Attributes\Group;

#[Group('TC-O3')]
final class MetricsEndpointTest extends ApiTestCase
{
    private const METRICS = [
        'comparison_requests_total', 'comparison_duration_seconds', 'provider_calls_total', 'provider_call_duration_seconds',
        'comparison_quotes_total', 'comparison_overhead_seconds', 'campaign_discount_cents_total',
    ];

    public function testExposesEveryInitialSeriesAtZeroBeforeAnyRequest(): void
    {
        $text = $this->metrics();

        foreach (['complete', 'partial', 'empty', 'invalid', 'error'] as $outcome) {
            self::assertStringContainsString(sprintf('comparison_requests_total{outcome="%s"} 0', $outcome), $text);
        }
        foreach (['provider-a', 'provider-b', 'provider-c'] as $provider) {
            foreach (['ok', 'timeout', 'http_error', 'malformed_response', 'unknown_error'] as $outcome) {
                self::assertStringContainsString(sprintf('provider_calls_total{provider="%s",outcome="%s"} 0', $provider, $outcome), $text);
            }
        }
        self::assertSame(8, substr_count($text, 'comparison_quotes_total{'));
        self::assertStringContainsString('campaign_discount_cents_total 0', $text);
    }

    public function testTheContentTypeIsTheTextExpositionFormat(): void
    {
        $this->client->request('GET', '/metrics');

        self::assertStringStartsWith('text/plain; version=0.0.4', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testSeriesChangeAfterARequest(): void
    {
        $this->picker->force('provider-c', SimulatedOutcomeKind::SPIKE);
        $this->postComparison(['vehicleType' => 'SUV', 'usageType' => 'COMMERCIAL'] + self::VALID_BODY);

        $text = $this->metrics();
        self::assertStringContainsString('comparison_requests_total{outcome="partial"} 1', $text);
        self::assertStringContainsString('provider_calls_total{provider="provider-a",outcome="ok"} 1', $text);
        self::assertStringContainsString('provider_calls_total{provider="provider-c",outcome="timeout"} 1', $text);
        self::assertStringContainsString('comparison_quotes_total{vehicle_type="SUV",usage_type="COMMERCIAL"} 1', $text);
        self::assertStringContainsString('comparison_duration_seconds_count 1', $text);
        self::assertStringContainsString('comparison_overhead_seconds_count 1', $text);
        self::assertStringContainsString('provider_call_duration_seconds_count{provider="provider-c",result="failed"} 1', $text);
    }

    public function testRejectedRequestsAreCountedAsInvalid(): void
    {
        $this->postComparison('nope');

        self::assertStringContainsString('comparison_requests_total{outcome="invalid"} 1', $this->metrics());
        self::assertStringNotContainsString('comparison_quotes_total{vehicle_type="SEDAN",usage_type="PRIVATE"} 1', $this->metrics());
    }

    public function testEveryLabelValueBelongsToItsClosedSet(): void
    {
        $this->picker->force('provider-b', SimulatedOutcomeKind::MALFORMED);
        $this->postComparison();
        $this->postComparison(['birthDate' => 'not-a-date'] + self::VALID_BODY);

        $allowed = [
            'outcome' => ['complete', 'partial', 'empty', 'invalid', 'error', 'ok', 'timeout', 'http_error', 'malformed_response', 'unknown_error'],
            'provider' => ['provider-a', 'provider-b', 'provider-c'],
            'result' => ['ok', 'failed'],
            'vehicle_type' => ['SEDAN', 'HATCHBACK', 'SUV', 'VAN'],
            'usage_type' => ['PRIVATE', 'COMMERCIAL'],
            'le' => null,
        ];

        preg_match_all('/(\w+)="([^"]*)"/', $this->metrics(), $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches);
        foreach ($matches as [, $label, $value]) {
            self::assertArrayHasKey($label, $allowed, sprintf('Unexpected label "%s".', $label));
            if (null !== $allowed[$label]) {
                self::assertContains($value, $allowed[$label], sprintf('Label %s has a value outside its closed set.', $label));
            }
        }
    }

    public function testMetricNamesAreExactlyTheCatalog(): void
    {
        $this->postComparison();

        preg_match_all('/^# TYPE (\w+) /m', $this->metrics(), $matches);
        self::assertEqualsCanonicalizing(self::METRICS, $matches[1]);
    }

    private function metrics(): string
    {
        $this->client->request('GET', '/metrics');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }
}
