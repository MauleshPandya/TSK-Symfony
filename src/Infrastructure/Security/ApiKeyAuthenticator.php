<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

/**
 * Simple API key authentication for MVP scope.
 *
 * In production, this would integrate with a proper key store (DB/Redis)
 * supporting key rotation, scopes, and per-key rate limiting.
 *
 * For MVP: keys are configured via environment variable as comma-separated values.
 * Example: API_KEYS=key1,key2,key3
 */
final class ApiKeyAuthenticator
{
    /** @var string[] */
    private array $validKeys;

    public function __construct(
        private readonly string $apiKeyHeader,
        private readonly LoggerInterface $logger,
    ) {
        $rawKeys = $_ENV['API_KEYS'] ?? 'dev-api-key-change-in-production';
        $this->validKeys = array_filter(array_map('trim', explode(',', $rawKeys)));
    }

    public function authenticate(Request $request): bool
    {
        $providedKey = $request->headers->get($this->apiKeyHeader);

        if (empty($providedKey)) {
            $this->logger->warning('API request missing authentication header', [
                'ip' => $request->getClientIp(),
                'path' => $request->getPathInfo(),
            ]);

            return false;
        }

        // Constant-time comparison to prevent timing attacks
        foreach ($this->validKeys as $validKey) {
            if (hash_equals($validKey, $providedKey)) {
                return true;
            }
        }

        $this->logger->warning('API request with invalid key', [
            'ip' => $request->getClientIp(),
            'path' => $request->getPathInfo(),
        ]);

        return false;
    }
}
