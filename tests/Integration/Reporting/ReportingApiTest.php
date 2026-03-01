<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reporting;

use App\Domain\Account\Account;
use App\Domain\Transfer\Transfer;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ReportingApiTest extends IntegrationTestCase
{
    private KernelBrowser $client;
    private const API_KEY = 'dev-api-key-change-in-production';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->seedData();
    }

    public function testSummaryReturnsCorrectTotals(): void
    {
        $this->client->request('GET', '/api/v1/reports/summary',
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $data     = $response['data'];

        $this->assertArrayHasKey('transfers', $data);
        $this->assertArrayHasKey('accounts', $data);
        $this->assertArrayHasKey('volume_by_currency', $data);
        $this->assertSame(3, $data['transfers']['total']);
        $this->assertSame(2, $data['transfers']['completed']);
        $this->assertSame(1, $data['transfers']['failed']);
        $this->assertArrayHasKey('USD', $data['volume_by_currency']);
    }

    public function testDailyVolumeReturnsDailyBreakdown(): void
    {
        $this->client->request('GET', '/api/v1/reports/daily?days=7',
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(7, $response['meta']['days']);
        $this->assertIsArray($response['data']);
    }

    public function testTopAccountsReturnsRankedList(): void
    {
        $this->client->request('GET', '/api/v1/reports/top-accounts?limit=5',
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(5, $response['meta']['limit']);
        $this->assertIsArray($response['data']);
    }

    public function testAccountSummaryReturnsNetVolume(): void
    {
        $this->client->request(
            'GET',
            '/api/v1/reports/accounts/550e8400-e29b-41d4-a716-000000000001',
            server: ['HTTP_X-API-Key' => self::API_KEY],
        );

        $this->assertResponseStatusCodeSame(200);

        $response = json_decode($this->client->getResponse()->getContent(), true);
        $data     = $response['data'];

        $this->assertArrayHasKey('sent', $data);
        $this->assertArrayHasKey('received', $data);
        $this->assertArrayHasKey('net_volume', $data);
        // Alice sent 2 transfers totalling 300, received 0
        $this->assertSame('300.00', $data['sent']['volume']);
        $this->assertSame(2, $data['sent']['count']);
    }

    public function testReportingRequiresAuth(): void
    {
        $this->client->request('GET', '/api/v1/reports/summary');
        $this->assertResponseStatusCodeSame(401);
    }

    private function seedData(): void
    {
        $alice = new Account('550e8400-e29b-41d4-a716-000000000001', 'user-alice', 'USD', '5000.00');
        $bob   = new Account('550e8400-e29b-41d4-a716-000000000002', 'user-bob',   'USD', '1000.00');

        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        // 2 completed transfers (Alice → Bob: 200 + 100)
        foreach ([['200.00', '001'], ['100.00', '002']] as [$amount, $suffix]) {
            $t = new Transfer(
                id:             "660e8400-e29b-41d4-a716-0000000000{$suffix}",
                fromAccountId:  $alice->getId(),
                toAccountId:    $bob->getId(),
                amount:         $amount,
                currency:       'USD',
                idempotencyKey: "report-test-idem-{$suffix}",
            );
            $t->markCompleted();
            $this->entityManager->persist($t);
        }

        // 1 failed transfer
        $failed = new Transfer(
            id:             '660e8400-e29b-41d4-a716-000000000099',
            fromAccountId:  $alice->getId(),
            toAccountId:    $bob->getId(),
            amount:         '9999.00',
            currency:       'USD',
            idempotencyKey: 'report-test-failed-001',
        );
        $failed->markFailed('Insufficient funds.');
        $this->entityManager->persist($failed);
        $this->entityManager->flush();
    }
}
