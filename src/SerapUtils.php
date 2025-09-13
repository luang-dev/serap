<?php

namespace LuangDev\Serap;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use SplFileObject;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class SerapUtils
{
    public const MAX_RESPONSE_LENGTH = 10_000;

    public static function mask(array $data, array $sensitiveKeys = [], string $mask = '******'): array
    {
        if (count($sensitiveKeys) === 0) {
            $sensitiveKeys = config('serap.sensitive_keys', []);
        }

        // normalisasi daftar key sensitif
        $normalizedKeys = array_map(fn ($k) => self::normalizeKey($k), $sensitiveKeys);

        foreach ($data as $key => $value) {
            $normalizedKey = self::normalizeKey($key);

            // khusus untuk cookie/set-cookie
            if (in_array($normalizedKey, ['cookie', 'set_cookie'], true)) {
                if (is_array($value)) {
                    $data[$key] = array_map(function ($cookieString) use ($normalizedKeys, $mask) {
                        return self::maskCookieString($cookieString, $normalizedKeys, $mask);
                    }, $value);
                } elseif (is_string($value)) {
                    $data[$key] = self::maskCookieString($value, $normalizedKeys, $mask);
                }
            } elseif (in_array($normalizedKey, $normalizedKeys, true)) {
                // kalau key biasa sensitif
                $data[$key] = $mask;
            } elseif (is_array($value)) {
                $data[$key] = self::mask($value, $sensitiveKeys, $mask);
            }
        }

        return $data;
    }

    /**
     * Masking khusus cookie/set-cookie
     */
    protected static function maskCookieString(string $cookieString, array $normalizedKeys, string $mask): string
    {
        $parts = explode(';', $cookieString);

        foreach ($parts as &$part) {
            $kv = explode('=', $part, 2);

            if (count($kv) === 2) {
                $cookieKey = self::normalizeKey($kv[0]);

                if (in_array($cookieKey, $normalizedKeys, true)) {
                    $part = trim($kv[0]).'='.$mask;
                }
            }
        }

        return implode(';', $parts);
    }

    /**
     * Normalisasi key jadi snake_case lowercase
     */
    protected static function normalizeKey(string $key): string
    {
        $key = trim($key);
        $key = strtolower($key);

        return str_replace('-', '_', $key);
    }

    public static function generateTraceId()
    {
        $traceId = (string) Str::ulid()?->toString();

        Context::add('serap_trace_id', $traceId);

        request()?->attributes->set('serap_trace_id', $traceId);

        return $traceId;
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

    public static function safeContent(string $content, string $type = 'text')
    {
        $isTruncated = false;
        $data = $content;

        if ($type !== 'json') {
            if (mb_strlen($content) > self::MAX_RESPONSE_LENGTH) {
                $data = mb_substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [TRUNCATED]';
                $isTruncated = true;
            }

            // return json_encode([
            //     'is_truncated' => $isTruncated,
            //     'data' => $data,
            // ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return [
                'is_truncated' => $isTruncated,
                'data' => $data,
            ];
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // fallback: treat as text
            if (mb_strlen($content) > self::MAX_RESPONSE_LENGTH) {
                $data = mb_substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [TRUNCATED]';
                $isTruncated = true;
            }

            // return json_encode([
            //     'is_truncated' => $isTruncated,
            //     'data' => $data,
            // ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return [
                'is_truncated' => $isTruncated,
                'data' => $data,
            ];
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

        // return json_encode([
        //     'is_truncated' => $isTruncated,
        //     'data' => $data,
        // ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'is_truncated' => $isTruncated,
            'data' => $data,
        ];
    }

    public static function getMemoryUsage(): float
    {
        return memory_get_peak_usage(real_usage: true) / 1024 / 1024;
    }

    public static function getTraceId()
    {
        return Context::get('serap_trace_id')
                    ?? request()?->attributes->get('serap_trace_id')
                    ?? self::generateTraceId();
    }

    public static function writeJsonl(string $eventName, array $context, string $level = 'info'): void
    {
        $traceId = SerapUtils::getTraceId();
        $path = storage_path('logs/serap.jsonl');

        $file = new SplFileObject($path, 'a');

        // lock exclusive
        if ($file->flock(LOCK_EX)) {
            $log = [
                'trace_id' => $traceId,
                'event' => $eventName,
                'level' => $level,
                'time' => now()->toISOString(),
                'user' => self::getAuthUser(),
                'context' => $context,
            ];

            $file->fwrite(
                json_encode(
                    $log,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ).PHP_EOL
            );

            // release lock
            $file->flock(LOCK_UN);
        }
    }

    public static function getAuthUser(): ?array
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user?->username,
            'created_at' => $user?->created_at,
        ];
    }
}
