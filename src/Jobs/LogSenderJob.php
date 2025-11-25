<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        $logs = IngestQueue::pullBatch($batchSize);

        if (empty($logs)) {
            Log::info('No Serap logs to send.');

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
            $payloadFile = storage_path('logs/serap-payload.jsonl');
            file_put_contents($payloadFile, json_encode($logs).PHP_EOL, flags: LOCK_EX);

            $status = $response->status();

            if ($status === 200 || $status === 201) {
                Log::info("Batch sent [{$status}]: ".$response->body());
            } else {
                IngestQueue::pushBatch($logs);
                Log::error("Batch failed [{$status}]: ".$response->body());
            }
        } catch (\Throwable $e) {
            IngestQueue::pushBatch($logs);
            Log::error('Batch error: '.$e->getMessage());
        }
    }
}
