<?php

declare(strict_types=1);

namespace App\ProviderSimulation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class SimulationProfiles
{
    /** @var array<string, SimulationProfile> */
    private array $profiles;

    /** @param array<string, array<string, mixed>> $config */
    public function __construct(
        #[Autowire('%app.providers.simulation%')]
        array $config,
    ) {
        $this->profiles = array_map(SimulationProfile::fromConfig(...), $config);
    }

    public function forProvider(string $providerId): SimulationProfile
    {
        return $this->profiles[$providerId] ?? throw new \InvalidArgumentException(sprintf('No simulation profile for "%s".', $providerId));
    }
}
