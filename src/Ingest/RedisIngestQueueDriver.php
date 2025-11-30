<?php

namespace LuangDev\Serap\Ingest;

use Illuminate\Support\Facades\Redis;

class RedisIngestQueueDriver implements IngestQueueDriver
{
    public function __construct(protected string $key, protected array $priorities)
    {
    }

    public function push(array $log, ?string $priority = null): void
    {
        $this->pushBatch([$log], $priority);
    }

    public function pushBatch(array $logs, ?string $priority = null): void
    {
        if (empty($logs)) {
            return;
        }

        $grouped = [];

        foreach ($logs as $log) {
            $priorityKey = $priority ?? ($log['priority'] ?? 'normal');
            $grouped[$priorityKey][] = $log;
        }

        Redis::pipeline(function ($pipe) use ($grouped): void {
            foreach ($grouped as $priorityKey => $payloads) {
                $targetKey = $this->keyForPriority($priorityKey);
                foreach ($payloads as $log) {
                    $pipe->rpush($targetKey, json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        });
    }

    public function pullBatch(int $batchSize): array
    {
        if ($batchSize <= 0) {
            return [];
        }

        $batch = [];

        foreach ($this->priorities as $priority) {
            $remaining = $batchSize - count($batch);
            if ($remaining <= 0) {
                break;
            }

            $targetKey = $this->keyForPriority($priority);
            for ($i = 0; $i < $remaining; $i++) {
                $payload = Redis::lpop($targetKey);
                if ($payload === null) {
                    break;
                }

                $decoded = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $batch[] = $decoded;
                }
            }
        }

        return $batch;
    }

    protected function keyForPriority(string $priority): string
    {
        $normalized = trim($priority) ?: 'normal';

        if ($normalized === 'normal') {
            return $this->key;
        }

        return $this->key.':'.$normalized;
    }
}
