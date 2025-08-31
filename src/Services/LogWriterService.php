<?php

namespace LuangDev\Serap\Services;

use SplFileObject;
use LuangDev\Serap\SerapUtils;
use Illuminate\Support\Facades\Auth;

class LogWriterService
{
    public static function write(string $eventName, array $data, string $level = 'info'): void
    {
        $path = storage_path(path: 'logs/serap.jsonl');

        $file = new SplFileObject(filename: $path, mode: 'a');

        $file->fwrite(
            data: json_encode(value: [
                'trace_id' => SerapUtils::getTraceId(),
                'event' => $eventName,
                'level' => $level,
                'time' => now()->toISOString(),
                'user' => Auth::user(),
                'context' => $data,
            ], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
        );
    }
}
