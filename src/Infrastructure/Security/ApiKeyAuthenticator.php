<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Simple API key authentication for MVP scope.
 *
 * Keys are configured via the API_KEYS environment variable
 * as a comma-separated list.
 */
final class ApiKeyAuthenticator
{
    /** @var string[] */
    private array $validKeys;

    public function __construct(
        private readonly string $apiKeyHeader,
        private readonly LoggerInterface $logger,
    ) {
        $rawKeys = $_ENV['API_KEYS'] ?? '';
        $this->validKeys = array_filter(array_map('trim', explode(',', $rawKeys)));
    }

    public function authenticate(Request $request): bool
    {
        $providedKey = $request->headers->get($this->apiKeyHeader);

        if ($providedKey === null || $providedKey === '') {
            $this->logger->warning('API request missing authentication header', [
                'ip'   => $request->getClientIp(),
                'path' => $request->getPathInfo(),
            ]);

            return false;
        }

        foreach ($this->validKeys as $validKey) {
            if (hash_equals($validKey, $providedKey)) {
                return true;
            }
        }

        $this->logger->warning('API request with invalid key', [
            'ip'   => $request->getClientIp(),
            'path' => $request->getPathInfo(),
        ]);

        return false;
    }
}

