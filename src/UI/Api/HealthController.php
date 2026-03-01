<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Infrastructure\Metrics\MetricsCollector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/')]
final class HealthController
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {
    }

    #[Route('/health', name: 'health_check', methods: ['GET'])]
    public function health(): JsonResponse
    {
        // For MVP we simply report healthy; DB/Redis checks can be added later.
        return new JsonResponse([
            'status'    => 'healthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks'    => [],
        ], Response::HTTP_OK);
    }

    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], Response::HTTP_OK);
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function metrics(): Response
    {
        return new Response(
            $this->metrics->render(),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }
}

