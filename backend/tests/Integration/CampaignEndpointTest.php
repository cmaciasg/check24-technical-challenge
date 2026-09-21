<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;

/** Campaña de punta a punta con la configuración de Symfony sobrescrita por variables de entorno. */
final class CampaignEndpointTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $previous = [];

    /** @param array<string, string> $env */
    private function bootWithCampaign(array $env): void
    {
        foreach ($env as $name => $value) {
            $this->previous[$name] = $_SERVER[$name] ?? null;
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
        parent::setUp();
    }

    protected function setUp(): void
    {
        // Cada test arranca el kernel después de fijar su campaña.
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv($name.'='.$value);
            }
        }
        $this->previous = [];
        parent::tearDown();
    }

    #[Group('TC-P5')]
    public function testPercentageCampaignReducesEveryOfferAndKeepsTheRealPrice(): void
    {
        $this->bootWithCampaign([
            'MARKETING_CAMPAIGN_ACTIVE' => 'true',
            'MARKETING_CAMPAIGN_DISCOUNT_TYPE' => 'PERCENTAGE',
            'MARKETING_CAMPAIGN_DISCOUNT_VALUE' => '10',
        ]);

        $this->postComparison();

        self::assertResponseStatusCodeSame(200);
        $offers = $this->json()['offers'];
        self::assertCount(3, $offers);
        foreach ($offers as $offer) {
            self::assertTrue($offer['campaignApplied']);
            self::assertSame((int) round($offer['priceAmountCents'] * 0.9), $offer['discountedPriceAmountCents']);
            self::assertLessThan($offer['priceAmountCents'], $offer['discountedPriceAmountCents']);
        }
    }

    #[Group('TC-P6')]
    #[Group('TC-P7')]
    public function testFixedAmountCampaignFloorsAtZeroForEveryProvider(): void
    {
        $this->bootWithCampaign([
            'MARKETING_CAMPAIGN_ACTIVE' => 'true',
            'MARKETING_CAMPAIGN_DISCOUNT_TYPE' => 'FIXED_AMOUNT',
            'MARKETING_CAMPAIGN_DISCOUNT_VALUE' => '99999',
        ]);

        $this->postComparison();

        foreach ($this->json()['offers'] as $offer) {
            self::assertSame(0, $offer['discountedPriceAmountCents']);
            self::assertGreaterThan(0, $offer['priceAmountCents']);
        }
    }

    #[Group('TC-P4')]
    public function testInactiveCampaignAddsNothing(): void
    {
        $this->bootWithCampaign(['MARKETING_CAMPAIGN_ACTIVE' => 'false']);

        $this->postComparison();

        foreach ($this->json()['offers'] as $offer) {
            self::assertArrayNotHasKey('discountedPriceAmountCents', $offer);
        }
    }
}
