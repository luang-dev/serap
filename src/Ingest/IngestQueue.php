<?php

namespace LuangDev\Serap\Ingest;

use Illuminate\Support\Facades\Log;

class IngestQueue
{
    protected static ?IngestQueueDriver $driverInstance = null;

    public static function push(array $log): void
    {
        self::driver()->push($log);
    }

    public static function pushBatch(array $logs): void
    {
        if (empty($logs)) {
            return;
        }

        self::driver()->pushBatch($logs);
    }

    public static function pullBatch(int $batchSize): array
    {
        try {
            return self::driver()->pullBatch($batchSize);
        } catch (\Throwable $e) {
            Log::error('Failed to pull Serap ingest batch: '.$e->getMessage());

            return [];
        }
    }

    protected static function driver(): IngestQueueDriver
    {
        if (self::$driverInstance) {
            return self::$driverInstance;
        }

        $driver = config('serap.ingest.driver', 'file');

        if ($driver === 'redis') {
            return self::$driverInstance = new RedisIngestQueueDriver(
                key: config('serap.ingest.redis.key', 'serap:ingest')
            );
        }

        return self::$driverInstance = new FileIngestQueueDriver(
            path: config('serap.ingest.file.path', storage_path('logs/serap.jsonl'))
        );
    }
}
