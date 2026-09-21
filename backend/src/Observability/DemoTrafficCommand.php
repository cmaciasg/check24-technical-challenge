<?php

declare(strict_types=1);

namespace App\Observability;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envía a POST /comparison peticiones sintéticas, de una en una, para que el dashboard tenga datos.
 * Sin concurrencia: con providers + 1 workers, dos comparaciones a la vez saturarían el pool y falsearían las latencias.
 */
#[AsCommand(name: 'app:observability:demo-traffic', description: 'Send synthetic traffic to POST /comparison')]
final class DemoTrafficCommand extends Command
{
    private const VEHICLE_TYPES = ['SEDAN', 'HATCHBACK', 'SUV', 'VAN'];
    private const USAGE_TYPES = ['PRIVATE', 'COMMERCIAL'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%app.providers.base_url%')]
        private readonly string $defaultBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('requests', null, InputOption::VALUE_REQUIRED, 'Number of requests to send', '40')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Backend base URL', $this->defaultBaseUrl);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $total = max(1, (int) $input->getOption('requests'));
        $baseUrl = rtrim((string) $input->getOption('base-url'), '/');
        $statuses = [];

        for ($i = 1; $i <= $total; ++$i) {
            $response = $this->httpClient->request('POST', $baseUrl.'/comparison', [
                'json' => $this->syntheticBody(),
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $output->writeln(sprintf('[%d/%d] HTTP %d', $i, $total, $status));
        }

        ksort($statuses);
        $output->writeln('Done: '.implode(', ', array_map(static fn (int $s, int $n): string => sprintf('%d x HTTP %d', $n, $s), $statuses, array_keys($statuses))));

        return Command::SUCCESS;
    }

    /** @return array<string, string> */
    private function syntheticBody(): array
    {
        // ~10 % deliberadamente inválidas para que aparezca `invalid` en el dashboard.
        if (random_int(1, 10) === 1) {
            return ['birthDate' => '1990-13-45', 'vehicleType' => 'SEDAN', 'usageType' => 'PRIVATE'];
        }

        $age = random_int(18, 99);
        $birthDate = (new \DateTimeImmutable('today'))->modify(sprintf('-%d years -%d days', $age, random_int(1, 300)));

        return [
            'birthDate' => $birthDate->format('Y-m-d'),
            'vehicleType' => self::VEHICLE_TYPES[array_rand(self::VEHICLE_TYPES)],
            'usageType' => self::USAGE_TYPES[array_rand(self::USAGE_TYPES)],
        ];
    }
}
