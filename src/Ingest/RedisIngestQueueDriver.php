<?php

namespace LuangDev\Serap\Ingest;

use Illuminate\Support\Facades\Redis;

class RedisIngestQueueDriver implements IngestQueueDriver
{
    public function __construct(protected string $key)
    {
    }

    public function push(array $log): void
    {
        Redis::rpush($this->key, json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function pushBatch(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        Redis::pipeline(function ($pipe) use ($logs): void {
            foreach ($logs as $log) {
                $pipe->rpush($this->key, json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        });
    }

    public function pullBatch(int $batchSize): array
    {
        if ($batchSize <= 0) {
            return [];
        }

        $results = Redis::pipeline(function ($pipe) use ($batchSize): void {
            for ($i = 0; $i < $batchSize; $i++) {
                $pipe->lpop($this->key);
            }
        });

        $batch = [];
        foreach ($results as $payload) {
            if ($payload === null) {
                continue;
            }

            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $batch[] = $decoded;
            }
        }

        return $batch;
    }
}
