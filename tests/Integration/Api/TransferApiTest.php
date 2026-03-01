<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Domain\Account\Account;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class TransferApiTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private const FROM_ACCOUNT_ID = '550e8400-e29b-41d4-a716-446655440001';
    private const TO_ACCOUNT_ID   = '550e8400-e29b-41d4-a716-446655440002';
    private const API_KEY         = 'dev-api-key-change-in-production';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->seedAccounts();
    }

    public function testSuccessfulTransfer(): void
    {
        $idempotencyKey = '550e8400-e29b-41d4-a716-' . rand(100000000000, 999999999999);

        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: $this->headers($idempotencyKey),
            content: json_encode([
                'from_account_id' => self::FROM_ACCOUNT_ID,
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '100.00',
                'currency'        => 'USD',
                'description'     => 'Test transfer',
            ]),
        );

        $this->assertResponseStatusCodeSame(201);

        $response = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $response);
        $this->assertSame('completed', $response['data']['status']);
        $this->assertSame('100.00', $response['data']['amount']);
        $this->assertSame('USD', $response['data']['currency']);
        $this->assertSame(self::FROM_ACCOUNT_ID, $response['data']['from_account_id']);
        $this->assertSame(self::TO_ACCOUNT_ID, $response['data']['to_account_id']);
        $this->assertNotNull($response['data']['completed_at']);
    }

    public function testIdempotencyReturnsCachedResponse(): void
    {
        $idempotencyKey = '550e8400-e29b-41d4-a716-' . rand(100000000000, 999999999999);

        $payload = json_encode([
            'from_account_id' => self::FROM_ACCOUNT_ID,
            'to_account_id'   => self::TO_ACCOUNT_ID,
            'amount'          => '50.00',
            'currency'        => 'USD',
        ]);

        // First request
        $this->client->request('POST', '/api/v1/transfers', server: $this->headers($idempotencyKey), content: $payload);
        $this->assertResponseStatusCodeSame(201);
        $firstResponse = json_decode($this->client->getResponse()->getContent(), true);

        // Second identical request — should return same transfer ID
        $this->client->request('POST', '/api/v1/transfers', server: $this->headers($idempotencyKey), content: $payload);
        $this->assertResponseStatusCodeSame(200);

        $secondResponse = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($firstResponse['data']['id'], $secondResponse['data']['id']);
        $this->assertSame('true', $this->client->getResponse()->headers->get('X-Idempotent-Replayed'));
    }

    public function testReturnsNotFoundForUnknownAccount(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: $this->headers(),
            content: json_encode([
                'from_account_id' => '550e8400-e29b-41d4-a716-000000000001',
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '100.00',
                'currency'        => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(404);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ACCOUNT_NOT_FOUND', $response['error']['code']);
    }

    public function testReturnsPaymentRequiredOnInsufficientFunds(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: $this->headers(),
            content: json_encode([
                'from_account_id' => self::FROM_ACCOUNT_ID,
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '9999.00', // More than available
                'currency'        => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(402);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('INSUFFICIENT_FUNDS', $response['error']['code']);
    }

    public function testValidationErrorsOnInvalidRequest(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: $this->headers(),
            content: json_encode([
                'from_account_id' => 'not-a-uuid',
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '-50.00',
                'currency'        => 'INVALID',
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('VALIDATION_ERROR', $response['error']['code']);
        $this->assertNotEmpty($response['error']['details']);
    }

    public function testUnauthorizedWithoutApiKey(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{}',
        );

        $this->assertResponseStatusCodeSame(401);
    }

    public function testMissingIdempotencyKey(): void
    {
        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-API-Key' => self::API_KEY,
            ],
            content: json_encode([
                'from_account_id' => self::FROM_ACCOUNT_ID,
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '100.00',
                'currency'        => 'USD',
            ]),
        );

        $this->assertResponseStatusCodeSame(422);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('MISSING_IDEMPOTENCY_KEY', $response['error']['code']);
    }

    public function testGetTransferById(): void
    {
        // First, create a transfer
        $idempotencyKey = '550e8400-e29b-41d4-a716-' . rand(100000000000, 999999999999);

        $this->client->request(
            method: 'POST',
            uri: '/api/v1/transfers',
            server: $this->headers($idempotencyKey),
            content: json_encode([
                'from_account_id' => self::FROM_ACCOUNT_ID,
                'to_account_id'   => self::TO_ACCOUNT_ID,
                'amount'          => '25.00',
                'currency'        => 'USD',
            ]),
        );

        $createResponse = json_decode($this->client->getResponse()->getContent(), true);
        $transferId = $createResponse['data']['id'];

        // Then retrieve it
        $this->client->request(
            method: 'GET',
            uri: "/api/v1/transfers/{$transferId}",
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(200);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame($transferId, $response['data']['id']);
        $this->assertSame('completed', $response['data']['status']);
    }

    public function testGetTransferReturns404ForUnknownId(): void
    {
        $this->client->request(
            method: 'GET',
            uri: '/api/v1/transfers/550e8400-e29b-41d4-a716-000000000000',
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(404);
    }

    private function seedAccounts(): void
    {
        $fromAccount = new Account(self::FROM_ACCOUNT_ID, 'user-1', 'USD', '1000.00');
        $toAccount = new Account(self::TO_ACCOUNT_ID, 'user-2', 'USD', '500.00');

        $this->entityManager->persist($fromAccount);
        $this->entityManager->persist($toAccount);
        $this->entityManager->flush();
    }

    private function headers(?string $idempotencyKey = null): array
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-API-Key' => self::API_KEY,
        ];

        if ($idempotencyKey !== null) {
            $headers['HTTP_Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }
}
