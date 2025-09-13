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

    public function __construct()
    {
        //
    }

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
                ->withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post(config('serap.endpoint').'/api/ingest', [
                    'logs' => $logs,
                ]);

            $status = $response->status();

            if ($status === 200 || $status === 201) {
                $remaining = array_slice($lines, 100);
                $file = new SplFileObject($logFile, 'w');

                if (! empty($remaining)) {
                    $file->fwrite(implode(PHP_EOL, $remaining).PHP_EOL);
                }
            } else {
                Log::error("Batch failed [{$status}]: ".$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Batch error: '.$e->getMessage());
        }
    }
}
