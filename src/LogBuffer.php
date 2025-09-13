<?php

namespace LuangDev\Serap;

use SplFileObject;

class LogBuffer
{
    protected static array $buffer = [];

    protected static int $maxBuffer = 50; // kumpulin 50 log dulu sebelum flush

    public static function add(array $log): void
    {
        self::$buffer[] = $log;

        if (count(self::$buffer) >= self::$maxBuffer) {
            self::flush();
        }
    }

    public static function flush(): void
    {
        if (empty(self::$buffer)) {
            return;
        }

        $path = storage_path('logs/serap.jsonl');
        $file = new SplFileObject($path, 'a');

        if ($file->flock(LOCK_EX)) {
            foreach (self::$buffer as $log) {
                $file->fwrite(
                    json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    .PHP_EOL
                );
            }
            $file->flock(LOCK_UN);
        }

        self::$buffer = [];
    }

    public static function hasPending(): bool
    {
        return ! empty(self::$buffer);
    }
}
