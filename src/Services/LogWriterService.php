<?php

namespace LuangDev\Serap\Services;

use LuangDev\Serap\SerapUtils;
use SplFileObject;

class LogWriterService
{
    public static function write(string $eventName, array $data, string $level = 'info'): void
    {
        $path = storage_path(path: 'logs/trace.jsonl');

        $file = new SplFileObject(filename: $path, mode: 'a');

        $file->fwrite(data: json_encode(value: [
            'trace_id' => SerapUtils::getTraceId(),
            'event' => $eventName,
            'level' => $level,
            'time' => now()->toISOString(),
            'context' => $data,
        ], flags: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL
        );
    }
}
