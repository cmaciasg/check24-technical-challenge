<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\LogCapture;
use App\Tests\Support\SwitchableFailingMetrics;
use PHPUnit\Framework\Attributes\Group;

#[Group('TC-O2')]
final class LogPrivacyTest extends ApiTestCase
{
    private const RECOGNIZABLE_BIRTH_DATE = '1987-03-21';
    private const INVALID_BIRTH_DATE = '1987-13-45';

    private LogCapture $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = $this->service(LogCapture::class);
    }

    public function testASuccessfulComparisonLogsNoQuoteRequestData(): void
    {
        $this->postComparison(['birthDate' => self::RECOGNIZABLE_BIRTH_DATE, 'vehicleType' => 'HATCHBACK', 'usageType' => 'COMMERCIAL']);

        $this->assertNoPersonalData();
        self::assertNotEmpty($this->eventLines('comparison.completed'));
    }

    public function testARejectedRequestLogsTheFieldAndRuleButNotTheValue(): void
    {
        $this->postComparison(['birthDate' => self::INVALID_BIRTH_DATE, 'vehicleType' => 'HATCHBACK', 'usageType' => 'COMMERCIAL']);

        $this->assertNoPersonalData();
        $rejected = $this->eventLines('comparison.rejected');
        self::assertCount(1, $rejected);
        self::assertSame('birthDate', $rejected[0]['field']);
        self::assertSame('not_a_real_date', $rejected[0]['rule']);
        self::assertSame('INFO', $rejected[0]['level']);
    }

    public function testAnUnhandledFailureLogsNoPersonalDataNorTheExceptionMessage(): void
    {
        $this->service(SwitchableFailingMetrics::class)->failWithMessage = 'failed while processing '.self::RECOGNIZABLE_BIRTH_DATE;

        $this->postComparison(['birthDate' => self::RECOGNIZABLE_BIRTH_DATE, 'vehicleType' => 'HATCHBACK', 'usageType' => 'COMMERCIAL']);

        self::assertResponseStatusCodeSame(500);
        $this->assertNoPersonalData();
        $failed = $this->eventLines('comparison.failed');
        self::assertCount(1, $failed);
        self::assertSame('ERROR', $failed[0]['level']);
        self::assertSame(\RuntimeException::class, $failed[0]['exception_class']);
        self::assertArrayHasKey('file', $failed[0]);
        self::assertArrayHasKey('line', $failed[0]);
        self::assertArrayHasKey('trace', $failed[0]);
        self::assertArrayNotHasKey('message', $failed[0]);
    }

    public function testAnUnhandledFailureIsCountedAsAnErrorAndAnswersWith500(): void
    {
        $this->service(SwitchableFailingMetrics::class)->failWithMessage = 'boom';

        $this->postComparison();

        self::assertResponseStatusCodeSame(500);
        $this->client->request('GET', '/metrics');
        self::assertStringContainsString('comparison_requests_total{outcome="error"} 1', (string) $this->client->getResponse()->getContent());
    }

    public function testEveryLineIsValidJsonWithTheCommonFields(): void
    {
        $this->picker->force('provider-c', \App\ProviderSimulation\SimulatedOutcomeKind::SPIKE);
        $this->postComparison();
        $this->postComparison('nope');

        self::assertNotEmpty($this->logs->lines);
        foreach ($this->logs->lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            foreach (['timestamp', 'level', 'event', 'request_id'] as $field) {
                self::assertArrayHasKey($field, $decoded);
                self::assertNotEmpty($decoded[$field]);
            }
        }
    }

    public function testAProviderFailureIsLoggedAsAWarningWithoutTheProviderPayload(): void
    {
        $this->picker->force('provider-c', \App\ProviderSimulation\SimulatedOutcomeKind::HTTP_ERROR);

        $this->postComparison(['birthDate' => self::RECOGNIZABLE_BIRTH_DATE, 'vehicleType' => 'HATCHBACK', 'usageType' => 'COMMERCIAL']);

        $failed = $this->eventLines('provider.call.failed');
        self::assertCount(1, $failed);
        self::assertSame('WARNING', $failed[0]['level']);
        self::assertSame('provider-c', $failed[0]['provider_id']);
        self::assertSame('http_error', $failed[0]['reason']);
        $this->assertNoPersonalData();
    }

    private function assertNoPersonalData(): void
    {
        $text = $this->logs->allText();
        foreach ([self::RECOGNIZABLE_BIRTH_DATE, self::INVALID_BIRTH_DATE, 'HATCHBACK', 'COMMERCIAL'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text);
        }
    }

    /** @return list<array<string, mixed>> */
    private function eventLines(string $event): array
    {
        $decoded = array_map(static fn (string $line): array => json_decode($line, true), $this->logs->lines);

        return array_values(array_filter($decoded, static fn (array $line): bool => $line['event'] === $event));
    }
}
