<?php

declare(strict_types=1);

namespace App\Observability;

use App\Comparator\CarInsurance\Application\Compare\ComparisonMetrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Convierte cualquier fallo propio no controlado de POST /comparison en un 500, lo cuenta y lo registra.
 * No incluye el mensaje de la excepción ni los argumentos de la traza: podrían citar la fecha de nacimiento.
 */
final class UnhandledExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ComparisonMetrics $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        if ('/comparison' !== $request->getPathInfo() || !$request->isMethod('POST') || $exception instanceof HttpExceptionInterface) {
            return;
        }

        $this->metrics->requestFailed();
        $this->logger->error('comparison.failed', [
            'exception_class' => $exception::class,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => self::traceWithoutArguments($exception),
        ]);

        $event->setResponse(new JsonResponse(['error' => 'internal_error'], Response::HTTP_INTERNAL_SERVER_ERROR));
    }

    private static function traceWithoutArguments(\Throwable $exception): string
    {
        $frames = [];
        foreach ($exception->getTrace() as $frame) {
            $frames[] = sprintf(
                '%s:%s %s%s%s',
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '?',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            );
        }

        return implode("\n", $frames);
    }
}
