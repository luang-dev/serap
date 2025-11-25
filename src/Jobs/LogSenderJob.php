<?php

namespace LuangDev\Serap\Jobs;

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

        $batchSize = (int) config('serap.ingest.batch_size', 100);
        $client = new IngestHttpClient(
            endpoint: config('serap.endpoint'),
            token: $token,
        );

        $payloadFile = storage_path('logs/serap-payload.jsonl');
        $hasLogs = false;

        while (true) {
            $logs = IngestQueue::pullBatch($batchSize);

            if (empty($logs)) {
                break;
            }

            $hasLogs = true;
            file_put_contents($payloadFile, json_encode($logs).PHP_EOL, FILE_APPEND | LOCK_EX);

            if (! $client->send($logs)) {
                IngestQueue::pushBatch($logs);

                break;
            }
        }

        if (! $hasLogs) {
            Log::info('No Serap logs to send.');
        }
    }
}
