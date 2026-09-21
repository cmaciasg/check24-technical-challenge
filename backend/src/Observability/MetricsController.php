<?php

declare(strict_types=1);

namespace App\Observability;

use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MetricsController
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly ComparisonMetrics $metrics,
    ) {
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $this->metrics->initializeSeries();

        return new Response(
            (new RenderTextFormat())->render($this->registry->getMetricFamilySamples()),
            Response::HTTP_OK,
            ['Content-Type' => RenderTextFormat::MIME_TYPE],
        );
    }
}
