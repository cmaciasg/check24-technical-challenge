<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\ProviderSimulation\SimulatedOutcomeKind;
use App\Tests\Support\ForcedOutcomePicker;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    protected const VALID_BODY = ['birthDate' => '1990-05-14', 'vehicleType' => 'SEDAN', 'usageType' => 'PRIVATE'];

    protected KernelBrowser $client;
    protected ForcedOutcomePicker $picker;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Conserva el contenedor entre peticiones: el picker forzado y el almacén de métricas viven en él.
        $this->client->disableReboot();
        $this->picker = $this->service(ForcedOutcomePicker::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = static::getContainer()->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException(sprintf('Service %s is not available.', $class));
        }

        return $service;
    }

    /** @param array<string, mixed>|string $body */
    protected function postComparison(array|string $body = self::VALID_BODY): void
    {
        $this->client->request('POST', '/comparison', server: ['CONTENT_TYPE' => 'application/json'], content: is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $response
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function statusesById(array $response): array
    {
        $byId = [];
        foreach ($response['providerStatuses'] as $status) {
            $byId[$status['providerId']] = $status;
        }

        return $byId;
    }

    protected function forceAll(SimulatedOutcomeKind $kind): void
    {
        $this->picker->forceAll($kind);
    }
}
