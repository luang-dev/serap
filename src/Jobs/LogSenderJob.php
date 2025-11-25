<?php

namespace LuangDev\Serap\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use LuangDev\Serap\Ingest\IngestHttpClient;
use LuangDev\Serap\Ingest\IngestQueue;

class LogSenderJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $token = config('serap.api_key');

        if (empty($token)) {
            Log::info('No Serap API key found.');

            return;
        }

        $config = config('serap.ingest');
        $batchSize = (int) ($config['batch_size'] ?? 100);
        $minBatch = (int) ($config['min_batch'] ?? 20);
        $maxBatch = (int) ($config['max_batch'] ?? 500);
        $targetLatency = (int) ($config['latency_target_ms'] ?? 750);
        $latencyCeiling = (int) ($config['latency_ceiling_ms'] ?? 2000);
        $compressThreshold = (int) ($config['compress_threshold'] ?? 65536);
        $fallbackPath = $config['fallback_path'] ?? storage_path('logs/serap-fallback.jsonl');
        $retentionDays = (int) ($config['retention_days'] ?? 7);
        $payloadFile = storage_path('logs/serap-payload.jsonl');
        $hasLogs = false;
        $sentCount = 0;
        $failedCount = 0;
        $latencySum = 0.0;
        $successfulBatches = 0;

        $client = new IngestHttpClient(
            endpoint: config('serap.endpoint'),
            token: $token,
            compressThreshold: $compressThreshold,
        );

        while (true) {
            $logs = IngestQueue::pullBatch($batchSize);

            if (empty($logs)) {
                break;
            }

            $hasLogs = true;
            $result = $client->send($logs);
            $this->snapshotPayload($payloadFile, $logs, $result);

            if ($result['success']) {
                $sentCount += count($logs);
                $latencySum += $result['latency_ms'];
                $successfulBatches++;
                $batchSize = $this->tuneBatchSize($batchSize, $result['latency_ms'], $targetLatency, $latencyCeiling, $minBatch, $maxBatch);
            } else {
                $failedCount += count($logs);
                $this->storeFallback($fallbackPath, $logs, $result);
                IngestQueue::pushBatch($logs);
                $batchSize = max($minBatch, (int) ceil($batchSize / 2));
                break;
            }
        }

        if ($hasLogs) {
            $latencyAvg = $successfulBatches > 0 ? $latencySum / $successfulBatches : 0.0;
            Log::info('Serap ingest metrics', [
                'sent' => $sentCount,
                'failed' => $failedCount,
                'avg_latency_ms' => round($latencyAvg, 2),
                'last_batch_size' => $batchSize,
            ]);
        } else {
            Log::info('No Serap logs to send.');
        }

        $this->pruneLocalArtifacts($payloadFile, $fallbackPath, $retentionDays);
        IngestQueue::prune(CarbonImmutable::now()->subDays($retentionDays));
    }

    protected function tuneBatchSize(int $current, float $latencyMs, int $target, int $ceiling, int $min, int $max): int
    {
        if ($latencyMs > $ceiling) {
            return max($min, (int) ceil($current * 0.5));
        }

        if ($latencyMs > $target) {
            return max($min, (int) ceil($current * 0.9));
        }

        if ($latencyMs > 0) {
            return min($max, (int) ceil($current * 1.2));
        }

        return $current;
    }

    protected function snapshotPayload(string $path, array $logs, array $result): void
    {
        $record = [
            'time' => now()->toISOString(),
            'count' => count($logs),
            'checksum' => $result['checksum'] ?? null,
            'compressed' => $result['compressed'] ?? false,
            'latency_ms' => $result['latency_ms'] ?? null,
            'success' => $result['success'] ?? false,
        ];

        file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    protected function storeFallback(string $path, array $logs, array $result): void
    {
        $record = [
            'time' => now()->toISOString(),
            'count' => count($logs),
            'checksum' => $result['checksum'] ?? null,
            'status' => $result['status'] ?? null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'logs' => $logs,
        ];

        file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    protected function pruneLocalArtifacts(string $payloadPath, string $fallbackPath, int $retentionDays): void
    {
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);

        $this->pruneFile($payloadPath, $cutoff);
        $this->pruneFile($fallbackPath, $cutoff);
    }

    protected function pruneFile(string $path, CarbonImmutable $cutoff): void
    {
        if (! file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        $fresh = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            $time = $decoded['time'] ?? null;

            $parsed = $time ? CarbonImmutable::make($time) : null;

            if ($parsed && $parsed->lessThan($cutoff)) {
                continue;
            }

            $fresh[] = $line;
        }

        file_put_contents($path, implode(PHP_EOL, $fresh).(empty($fresh) ? '' : PHP_EOL));
    }
}
