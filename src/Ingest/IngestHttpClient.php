<?php

namespace LuangDev\Serap\Ingest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IngestHttpClient
{
    public function __construct(
        protected readonly string $endpoint,
        protected readonly string $token,
        protected readonly int $compressThreshold,
        protected readonly int $timeoutSeconds = 10,
        protected readonly int $retryTimes = 2,
        protected readonly int $retryDelayMs = 500,
    ) {
    }

    /**
     * @return array{success: bool, latency_ms: float, status: int|null, checksum: string|null, compressed: bool}
     */
    public function send(array $logs): array
    {
        $result = [
            'success' => false,
            'latency_ms' => 0.0,
            'status' => null,
            'checksum' => null,
            'compressed' => false,
        ];

        if (empty($logs)) {
            $result['success'] = true;

            return $result;
        }

        $payload = [
            'logs' => $logs,
            'count' => count($logs),
            'sent_at' => now()->toISOString(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $checksum = hash('sha256', $json);
        $result['checksum'] = $checksum;

        $body = $json;
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'X-Serap-Checksum' => $checksum,
        ];

        if (strlen($json) >= $this->compressThreshold) {
            $body = gzencode($json);
            $headers['Content-Encoding'] = 'gzip';
            $result['compressed'] = true;
        }

        $start = microtime(true);

        try {
            $response = Http::retry($this->retryTimes, $this->retryDelayMs)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($this->buildUrl('/api/ingest'));

            $result['status'] = $response->status();
            $result['latency_ms'] = (microtime(true) - $start) * 1000;

            if ($response->successful()) {
                Log::info("Serap ingest sent [{$result['status']}] ({$result['latency_ms']} ms)");
                $result['success'] = true;
            } else {
                Log::error("Serap ingest failed [{$result['status']}] ({$result['latency_ms']} ms): ".$response->body());
            }
        } catch (\Throwable $e) {
            $result['latency_ms'] = (microtime(true) - $start) * 1000;
            Log::error('Serap ingest error: '.$e->getMessage());
        }

        return $result;
    }

    protected function buildUrl(string $path): string
    {
        return rtrim($this->endpoint, '/').'/'.ltrim($path, '/');
    }
}
