<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileObject;

class FlushLogJob implements ShouldQueue
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
            return;
        }

        $logFile = storage_path('logs/serap.jsonl');

        if (! file_exists($logFile)) {
            return;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return;
        }

        $batch = array_slice($lines, 0, 100);
        $logs = array_map(fn ($line) => json_decode($line, true), $batch);

        try {
            $response = Http::timeout(5)
                ->withHeaders(headers: [
                    'Authorization' => 'Bearer '.$token,
                ])
                ->post(config('serap.endpoint').'/api/ingest', [
                    'logs' => $logs,
                ]);

            if ($response->successful()) {
                $remaining = array_slice($lines, 100);

                $file = new SplFileObject(filename: $logFile, mode: 'w');
                $file->fwrite(implode(PHP_EOL, $remaining));
            } else {
                Log::error('Batch failed: '.$response->body());
            }
        } catch (\Exception $e) {
            Log::error('Batch error: '.$e->getMessage());
        }
    }
}
