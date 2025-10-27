<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use SplFileObject;

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

        $driver = config('serap.ingest.driver', 'file');
        $batchSize = (int) config('serap.ingest.batch_size', 100);
        $cleanup = null;
        $logs = [];

        if ($driver === 'redis') {
            $config = config('serap.ingest.redis', []);
            $connection = $config['connection'] ?? 'default';
            $key = $config['key'] ?? 'serap:logs';
            $redis = Redis::connection($connection);

            $rawBatch = $redis->lrange($key, 0, max(0, $batchSize - 1));

            if (empty($rawBatch)) {
                Log::info('Serap Redis queue is empty.');

                return;
            }

            $logs = array_values(array_filter(
                array_map(fn ($line) => json_decode($line, true), $rawBatch)
            ));

            $cleanup = function () use ($redis, $key, $rawBatch) {
                $redis->ltrim($key, count($rawBatch), -1);
            };
        } else {
            $logFile = storage_path('logs/serap.jsonl');

            if (! file_exists($logFile)) {
                Log::info('Serap log file not found.');

                return;
            }

            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (empty($lines)) {
                Log::info('Serap log file is empty.');

                return;
            }

            $batch = array_slice($lines, 0, $batchSize);
            $logs = array_values(array_filter(
                array_map(fn ($line) => json_decode($line, true), $batch)
            ));
            $remaining = array_slice($lines, $batchSize);

            $cleanup = function () use ($logFile, $remaining) {
                $file = new SplFileObject($logFile, 'w');

                if (! empty($remaining)) {
                    $file->fwrite(implode(PHP_EOL, $remaining).PHP_EOL);
                }
            };
        }

        if (empty($logs)) {
            Log::info('Serap logs batch is empty.');

            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post(config('serap.endpoint').'/api/ingest', [
                    'logs' => $logs,
                ]);

            // put log into serap-payload.jsonl
            $file = new SplFileObject(storage_path('logs/serap-payload.jsonl'), 'w');
            $file->fwrite(json_encode($logs).PHP_EOL);

            $status = $response->status();

            if ($status === 200 || $status === 201) {
                if (is_callable($cleanup)) {
                    $cleanup();
                }
                Log::info("Batch sent [{$status}]: ".$response->body());
            } else {
                Log::error("Batch failed [{$status}]: ".$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Batch error: '.$e->getMessage());
        }
    }
}
