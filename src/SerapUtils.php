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

    /**
     * Mask sensitive data in an array.
     *
     * This function takes an array of data, an array of sensitive keys, and a mask.
     * It then iterates over each key-value pair in the data array. If the key is in the
     * list of sensitive keys, the value is replaced with the mask. If the value is
     * an array, the function is called recursively on the value. If the value is a string
     * and the key is 'cookie' or 'set-cookie', the string is passed to maskCookieString.
     * If the value is a string and the key is in the list of sensitive keys, the value is
     * replaced with the mask.
     */
    public static function mask(array $data, array $sensitiveKeys = [], string $mask = '******'): array
    {
        if (count($sensitiveKeys) === 0) {
            $sensitiveKeys = config('serap.sensitive_keys', []);
        }

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
     * Mask a cookie string, given a list of normalized sensitive keys and a mask.
     *
     * This function takes a cookie string, explodes it into parts, and then iterates
     * over each part. If the part is a key-value pair and the key is in the
     * list of sensitive keys, the value is replaced with the mask.
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
     * Normalize a key by trimming and lowercasing it, then replacing all hyphens with underscores.
     */
    protected static function normalizeKey(string $key): string
    {
        $key = trim($key);
        $key = strtolower($key);

        return str_replace('-', '_', $key);
    }

    /**
     * Generate a unique trace ID and store it in the request and context.
     */
    public static function generateTraceId()
    {
        $traceId = (string) Str::ulid()?->toString();

        Context::add('serap_trace_id', $traceId);

        request()?->attributes->set('serap_trace_id', $traceId);

        return $traceId;
    }

    /**
     * Return the path to the trace log file.
     */
    public static function getPath(): string
    {
        return storage_path(path: 'logs/trace.jsonl');
    }

    /**
     * Detect the response type of the given response.
     *
     * Will return one of the following: json, html, text, stream, download, redirect, or unknown.
     */
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

    /**
     * Safely truncate content to fit within the max response length.
     *
     * This function takes a content string and a type, and returns an array
     * containing the truncated content and a boolean indicating whether the
     * content was truncated.
     *
     * If the type is 'json', this function will attempt to parse the content as
     * JSON and truncate the JSON data itself if it exceeds the maximum response
     * length. If the content is not valid JSON, it will be treated as text and
     * truncated as such.
     *
     * If the type is not 'json', the content will be treated as plain text and
     * truncated if it exceeds the maximum response length.
     */
    public static function safeContent(string $content, string $type = 'text')
    {
        $isTruncated = false;
        $data = $content;

        if ($type !== 'json') {
            if (mb_strlen($content) > self::MAX_RESPONSE_LENGTH) {
                $data = mb_substr($content, 0, self::MAX_RESPONSE_LENGTH).'... [TRUNCATED]';
                $isTruncated = true;
            }

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

    /**
     * Returns the peak memory usage in megabytes.
     */
    public static function getMemoryUsage(): float
    {
        return memory_get_peak_usage(real_usage: true) / 1024 / 1024;
    }

    /**
     * Returns the trace ID of the current request.
     * The trace ID is stored in the context, request attributes, and generated if not found.
     *
     * @return string The trace ID of the current request.
     */
    public static function getTraceId()
    {
        return Context::get('serap_trace_id')
            ?? request()?->attributes->get('serap_trace_id')
            ?? self::generateTraceId();
    }

    /**
     * Writes a log entry to a JSON log file.
     *
     * This function takes an event name, a context array, and an optional log level.
     * The log level defaults to 'info'. If the event name is 'exception', the log level is automatically set to 'error'.
     *
     * The function generates a log entry with the following format:
     * {
     *     "time": string,
     *     "trace_id": string,
     *     "event": string,
     *     "level": string,
     *     "user": mixed,
     *     "context": array
     * }
     *
     * The log entry is written to the file at storage_path('logs/serap.jsonl'), with each entry separated by a newline.
     *
     * The function uses file locking to ensure that only one process can write to the log file at a time.
     */
    public static function writeJsonl(string $event, array $context, ?array $auth, string $level = 'info'): void
    {
        if ($event == 'exception') {
            $level = 'error';
        }

        $log = [
            'time' => now()->toISOString(),
            'trace_id' => self::getTraceId(),
            'event' => $event,
            'level' => $level,
            // 'user' => self::getAuthUser(),
            'auth' => $auth ?? $context['auth'] ?? $context['user'] ?? self::getAuthUser() ?? null,
            'context' => $context,
        ];

        $path = storage_path('logs/serap.jsonl');
        $file = new SplFileObject($path, 'a');

        if ($file->flock(LOCK_EX)) {
            $file->fwrite(
                json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                .PHP_EOL
            );
            $file->flock(LOCK_UN);
        }
    }

    /**
     * Returns the authenticated user in an array format.
     * The array contains the following keys: id, name, email, username, created_at, first_name, last_name, and avatar.
     * If the user is not authenticated, the function returns null.
     */
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
            'created_at' => $user?->created_at?->toDateTimeString(),
            'first_name' => $user?->first_name,
            'last_name' => $user?->last_name,
            'avatar' => $user?->avatar ?? $user?->photo ?? $user?->profile_photo_url ?? $user?->profile_picture_url ?? $user?->profile_picture ?? $user?->profileImage ?? $user?->avatar_url ?? $user?->foto,
        ];
    }

    /**
     * Reads the log entries from the file at storage_path('logs/serap.jsonl') and returns them as an array.
     *
     * Each log entry is an associative array containing the following keys:
     *     "time": string, the timestamp of the log entry in ISO 8601 format
     *     "trace_id": string, the trace id of the log entry
     *     "event": string, the event name of the log entry
     *     "level": string, the log level of the log entry
     *     "user": mixed, the authenticated user of the log entry, or null if the user is not authenticated
     *     "context": array, the context of the log entry
     *
     * If the file does not exist, the function returns an empty array.
     */
    public static function readJsonl()
    {
        $path = storage_path('logs/serap.jsonl');

        if (! file_exists($path)) {
            return [];
        }

        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        return iterator_to_array($file);
    }
}
