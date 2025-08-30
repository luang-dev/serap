<?php

namespace LuangDev\Serap;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SerapUtils
{
    public const MAX_RESPONSE_LENGTH = 10_000;

    public static function mask(array $data, array $sensitiveKeys = [], string $mask = '******'): array
    {
        if (count(value: $sensitiveKeys) == 0) {
            $sensitiveKeys = config(key: 'gol.sensitive_keys', default: []);
        }

        $lowerKeys = array_map('strtolower', $sensitiveKeys);

        foreach ($data as $key => $value) {
            if (in_array(needle: strtolower(string: $key), haystack: $lowerKeys)) {
                $data[$key] = $mask;
            } elseif (is_array(value: $value)) {
                $data[$key] = self::mask(data: $value);
            }
        }

        return $data;
    }

    public static function generateTraceId(): string
    {
        return (string) Str::ulid();
    }

    public static function getPath(): string
    {
        return storage_path(path: 'logs/trace.jsonl');
    }

    public static function detectResponseType(Response $response): string
    {
        $contentType = $response->headers->get('Content-Type');

        if (! $contentType) {
            return 'unknown';
        }
        if (str_contains($contentType, 'application/json')) {
            return 'json';
        }
        if (str_contains($contentType, 'text/html')) {
            return 'html';
        }
        if (str_contains($contentType, 'text/plain')) {
            return 'text';
        }
        if (str_contains($contentType, 'application/octet-stream')) {
            return 'stream';
        }
        if (str_contains($contentType, 'application/pdf') || str_contains($contentType, 'application/zip')) {
            return 'download';
        }

        if ($response instanceof RedirectResponse) {
            return 'redirect';
        }

        return $contentType;
    }

    public static function safeContent(string $content, string $type = 'text'): string
    {
        $isTruncated = false;
        $data = $content;

        if ($type !== 'json') {
            if (mb_strlen($content) > self::MAX_RESPONSE_LENGTH) {
                $data = mb_substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [TRUNCATED]';
                $isTruncated = true;
            }

            return json_encode([
                'is_truncated' => $isTruncated,
                'notice' => $isTruncated ? 'TRUNCATED' : 'NOT TRUNCATED',
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // fallback: treat as text
            if (mb_strlen($content) > self::MAX_RESPONSE_LENGTH) {
                $data = mb_substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [TRUNCATED]';
                $isTruncated = true;
            }

            return json_encode([
                'is_truncated' => $isTruncated,
                'notice' => $isTruncated ? 'TRUNCATED' : 'NOT TRUNCATED',
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (mb_strlen($json) > self::MAX_RESPONSE_LENGTH) {
            $isTruncated = true;

            if (is_array($decoded)) {
                while (mb_strlen(json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_RESPONSE_LENGTH) {
                    array_pop($decoded);
                    if (empty($decoded)) {
                        break;
                    }
                }
            }
            $data = $decoded;
        } else {
            $data = $decoded;
        }

        return json_encode([
            'is_truncated' => $isTruncated,
            'notice' => $isTruncated ? 'TRUNCATED' : 'NOT TRUNCATED',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function getMemoryUsage(): float
    {
        return memory_get_peak_usage(real_usage: true) / 1024 / 1024;
    }

    public static function getTraceId()
    {
        return Context::get(key: 'serao_trace_id');
    }
}
