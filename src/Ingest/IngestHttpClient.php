<?php

namespace LuangDev\Serap\Ingest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IngestHttpClient
{
    public function __construct(
        protected readonly string $endpoint,
        protected readonly string $token,
    ) {
    }

    public function send(array $logs): bool
    {
        if (empty($logs)) {
            return true;
        }

        try {
            $response = Http::retry(2, 500)
                ->timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->buildUrl('/api/ingest'), [
                    'logs' => $logs,
                ]);

            $status = $response->status();

            if ($status >= 200 && $status < 300) {
                Log::info("Serap ingest sent [{$status}]");

                return true;
            }

            Log::error("Serap ingest failed [{$status}]: ".$response->body());
        } catch (\Throwable $e) {
            Log::error('Serap ingest error: '.$e->getMessage());
        }

        return false;
    }

    protected function buildUrl(string $path): string
    {
        return rtrim($this->endpoint, '/').'/'.ltrim($path, '/');
    }
}
