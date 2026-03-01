<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Read-side analytics repository.
 *
 * All queries run against the replica-safe read connection.
 * Uses raw DBAL (not ORM) for aggregation performance.
 *
 * None of these queries are on hot transfer paths — they are
 * explicitly for reporting/dashboard use cases.
 */
final class ReportingRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Overall system summary.
     * Returns total transfers, total volume, and active account count.
     */
    public function getSystemSummary(): array
    {
        $transferStats = $this->connection->fetchAssociative('
            SELECT
                COUNT(*) AS total_transfers,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed_transfers,
                SUM(CASE WHEN status = "failed"    THEN 1 ELSE 0 END) AS failed_transfers,
                COUNT(DISTINCT currency) AS currencies_used
            FROM transfers
        ');

        $volumeRows = $this->connection->fetchAllAssociative('
            SELECT currency, SUM(CAST(amount AS DECIMAL(20,2))) AS total_volume
            FROM transfers
            WHERE status = "completed"
            GROUP BY currency
            ORDER BY total_volume DESC
        ');

        $accountStats = $this->connection->fetchAssociative('
            SELECT
                COUNT(*) AS total_accounts,
                SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_accounts,
                COUNT(DISTINCT currency) AS currencies
            FROM accounts
        ');

        $volume = [];
        foreach ($volumeRows as $row) {
            $volume[$row['currency']] = number_format((float) $row['total_volume'], 2, '.', '');
        }

        return [
            'transfers' => [
                'total'     => (int) $transferStats['total_transfers'],
                'completed' => (int) $transferStats['completed_transfers'],
                'failed'    => (int) $transferStats['failed_transfers'],
                'success_rate' => $transferStats['total_transfers'] > 0
                    ? round(($transferStats['completed_transfers'] / $transferStats['total_transfers']) * 100, 2)
                    : 0.0,
            ],
            'volume_by_currency' => $volume,
            'accounts' => [
                'total'  => (int) $accountStats['total_accounts'],
                'active' => (int) $accountStats['active_accounts'],
            ],
        ];
    }

    /**
     * Daily transfer volumes for the last N days.
     */
    public function getDailyVolume(int $days = 30): array
    {
        $rows = $this->connection->fetchAllAssociative('
            SELECT
                DATE(created_at) AS date,
                currency,
                COUNT(*) AS transfer_count,
                SUM(CASE WHEN status = "completed" THEN CAST(amount AS DECIMAL(20,2)) ELSE 0 END) AS volume,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN status = "failed"    THEN 1 ELSE 0 END) AS failed
            FROM transfers
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(created_at), currency
            ORDER BY date DESC, currency
        ', ['days' => $days]);

        $result = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            if (!isset($result[$date])) {
                $result[$date] = ['date' => $date, 'by_currency' => []];
            }

            $result[$date]['by_currency'][$row['currency']] = [
                'transfer_count' => (int) $row['transfer_count'],
                'volume'         => number_format((float) $row['volume'], 2, '.', ''),
                'completed'      => (int) $row['completed'],
                'failed'         => (int) $row['failed'],
            ];
        }

        return array_values($result);
    }

    /**
     * Top N accounts by transfer volume (sent + received).
     */
    public function getTopAccountsByVolume(int $limit = 10): array
    {
        $rows = $this->connection->fetchAllAssociative('
            SELECT
                t.currency,
                t.from_account_id AS account_id,
                a.owner_id,
                SUM(CAST(t.amount AS DECIMAL(20,2))) AS sent_volume,
                COUNT(*) AS sent_count
            FROM transfers t
            JOIN accounts a ON a.id = t.from_account_id
            WHERE t.status = "completed"
            GROUP BY t.from_account_id, t.currency, a.owner_id
            ORDER BY sent_volume DESC
            LIMIT :limit
        ', ['limit' => $limit]);

        return array_map(fn ($row) => [
            'account_id'  => $row['account_id'],
            'owner_id'    => $row['owner_id'],
            'currency'    => $row['currency'],
            'sent_volume' => number_format((float) $row['sent_volume'], 2, '.', ''),
            'sent_count'  => (int) $row['sent_count'],
        ], $rows);
    }

    /**
     * Per-account summary: total sent, received, net, transfer count.
     */
    public function getAccountSummary(string $accountId): array
    {
        $sent = $this->connection->fetchAssociative('
            SELECT
                COUNT(*) AS count,
                COALESCE(SUM(CAST(amount AS DECIMAL(20,2))), 0) AS volume,
                currency
            FROM transfers
            WHERE from_account_id = :id AND status = "completed"
            GROUP BY currency
        ', ['id' => $accountId]);

        $received = $this->connection->fetchAssociative('
            SELECT
                COUNT(*) AS count,
                COALESCE(SUM(CAST(amount AS DECIMAL(20,2))), 0) AS volume,
                currency
            FROM transfers
            WHERE to_account_id = :id AND status = "completed"
            GROUP BY currency
        ', ['id' => $accountId]);

        $sentVolume     = (float) ($sent['volume'] ?? 0);
        $receivedVolume = (float) ($received['volume'] ?? 0);

        return [
            'account_id'      => $accountId,
            'currency'        => $sent['currency'] ?? $received['currency'] ?? null,
            'sent'            => [
                'count'  => (int) ($sent['count'] ?? 0),
                'volume' => number_format($sentVolume, 2, '.', ''),
            ],
            'received' => [
                'count'  => (int) ($received['count'] ?? 0),
                'volume' => number_format($receivedVolume, 2, '.', ''),
            ],
            'net_volume' => number_format($receivedVolume - $sentVolume, 2, '.', ''),
        ];
    }
}
