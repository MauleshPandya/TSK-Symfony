<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use Psr\Log\LoggerInterface;

/**
 * Minimal in-memory metrics collector.
 *
 * For MVP we just track counters/histograms in memory and log them;
 * production can replace this with a Prometheus client.
 */
final class MetricsCollector
{
    private int $completedTransfers = 0;
    private int $failedTransfers    = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function incrementTransfer(string $status, string $currency): void
    {
        if ($status === 'completed') {
            ++$this->completedTransfers;
        } else {
            ++$this->failedTransfers;
        }

        $this->logger->info('transfer_metric', [
            'status'   => $status,
            'currency' => $currency,
        ]);
    }

    public function recordTransferAmount(string $currency, string $amount): void
    {
        $this->logger->info('transfer_amount', [
            'currency' => $currency,
            'amount'   => $amount,
        ]);
    }

    public function recordTransferDuration(float $durationMs): void
    {
        $this->logger->info('transfer_duration', [
            'duration_ms' => $durationMs,
        ]);
    }

    public function incrementAccountCreated(string $currency): void
    {
        $this->logger->info('account_created', [
            'currency' => $currency,
        ]);
    }

    public function render(): string
    {
        // Simple Prometheus-style exposition for /metrics
        return implode("\n", [
            '# HELP transfers_completed Total number of completed transfers.',
            '# TYPE transfers_completed counter',
            'transfers_completed ' . $this->completedTransfers,
            '# HELP transfers_failed Total number of failed transfers.',
            '# TYPE transfers_failed counter',
            'transfers_failed ' . $this->failedTransfers,
            '',
        ]);
    }
}

