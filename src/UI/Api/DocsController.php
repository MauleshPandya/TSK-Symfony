<?php

declare(strict_types=1);

namespace App\UI\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/docs')]
final class DocsController
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'api_docs_index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Fund Transfer API documentation.',
            'readme'  => '/README.md',
        ]);
    }
}

