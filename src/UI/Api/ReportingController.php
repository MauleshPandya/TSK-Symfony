<?php

declare(strict_types=1);

namespace App\UI\Api;

use App\Infrastructure\Persistence\ReportingRepository;
use App\Infrastructure\Security\ApiKeyAuthenticator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/v1/reports')]
final class ReportingController
{
    public function __construct(
        private readonly ReportingRepository $reportingRepository,
        private readonly ApiKeyAuthenticator $authenticator,
    ) {
    }

    /**
     * GET /api/v1/reports/summary
     *
     * System-wide transfer stats: totals, volumes, success rate.
     */
    #[Route('/summary', name: 'api_report_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        return new JsonResponse(['data' => $this->reportingRepository->getSystemSummary()]);
    }

    /**
     * GET /api/v1/reports/daily
     *
     * Daily transfer volumes for the last N days.
     * Query param: ?days=30 (default 30, max 90)
     */
    #[Route('/daily', name: 'api_report_daily', methods: ['GET'])]
    public function daily(Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $days = min(90, max(1, (int) $request->query->get('days', 30)));

        return new JsonResponse([
            'data' => $this->reportingRepository->getDailyVolume($days),
            'meta' => ['days' => $days],
        ]);
    }

    /**
     * GET /api/v1/reports/top-accounts
     *
     * Top accounts by transfer volume sent.
     * Query param: ?limit=10 (default 10, max 50)
     */
    #[Route('/top-accounts', name: 'api_report_top_accounts', methods: ['GET'])]
    public function topAccounts(Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));

        return new JsonResponse([
            'data' => $this->reportingRepository->getTopAccountsByVolume($limit),
            'meta' => ['limit' => $limit],
        ]);
    }

    /**
     * GET /api/v1/reports/accounts/{id}
     *
     * Per-account financial summary: total sent, received, net.
     */
    #[Route('/accounts/{id}', name: 'api_report_account', methods: ['GET'])]
    public function account(string $id, Request $request): JsonResponse
    {
        if (!$this->authenticator->authenticate($request)) {
            return $this->unauthorized();
        }

        if (!Uuid::isValid($id)) {
            return new JsonResponse(
                ['error' => ['code' => 'INVALID_ID', 'message' => 'Account ID must be a valid UUID.']],
                Response::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse(['data' => $this->reportingRepository->getAccountSummary($id)]);
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(
            ['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Unauthorized.']],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
