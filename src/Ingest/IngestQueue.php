<?php

namespace LuangDev\Serap\Ingest;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class IngestQueue
{
    protected static ?IngestQueueDriver $driverInstance = null;

    public static function push(array $log, ?string $priority = null): void
    {
        self::driver()->push($log, $priority ?? ($log['priority'] ?? 'normal'));
    }

    public static function pushBatch(array $logs, ?string $priority = null): void
    {
        if (empty($logs)) {
            return;
        }

        self::driver()->pushBatch($logs, $priority);
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

    public static function prune(CarbonImmutable $cutoff): void
    {
        $driver = self::driver();

        if (method_exists($driver, 'pruneOlderThan')) {
            $driver->pruneOlderThan($cutoff);
        }
    }

    protected static function driver(): IngestQueueDriver
    {
        if (self::$driverInstance) {
            return self::$driverInstance;
        }

        $driver = config('serap.ingest.driver', 'file');

        $priorities = array_values(array_filter(array_map('trim', config('serap.ingest.priorities', ['high', 'normal', 'low']))));

        if ($driver === 'redis') {
            return self::$driverInstance = new RedisIngestQueueDriver(
                key: config('serap.ingest.redis.key', 'serap:ingest'),
                priorities: $priorities,
            );
        }

        return self::$driverInstance = new FileIngestQueueDriver(
            path: config('serap.ingest.file.path', storage_path('logs/serap.jsonl')),
            priorities: $priorities,
        );
    }
}
