<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use LuangDev\Serap\SerapUtils;
use SplFileObject;
use Throwable;

class ExceptionWatcher
{
    /**
     * Simpan hash exception yang sudah ditulis,
     * agar tidak ditulis dua kali dalam satu request lifecycle.
     */
    protected static array $reported = [];

    public static function handle(): void
    {
        app()->afterResolving(ExceptionHandler::class, function ($handler) {
            $handler->reportable(function (Throwable $e) {
                $hash = self::fingerprint($e);

                // Skip jika sudah pernah dicatat
                if (isset(self::$reported[$hash])) {
                    return;
                }
                self::$reported[$hash] = true;

                $context = self::formatExceptionData($e);
                $traceId = SerapUtils::getTraceId();

                $path = storage_path('logs/serap.jsonl');
                $file = new SplFileObject($path, 'a');

                if ($file->flock(LOCK_EX)) {
                    $file->fwrite(
                        json_encode([
                            'time' => now()->toISOString(),
                            'trace_id' => $traceId,
                            'event' => 'exception',
                            'level' => 'error',
                            'user' => SerapUtils::getAuthUser(),
                            'context' => $context,
                            'from' => 'expWatcher',
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        .PHP_EOL
                    );
                    $file->flock(LOCK_UN);
                }
            });
        });
    }

    /**
     * Fingerprint unik untuk exception supaya bisa dideduplicate.
     */
    protected static function fingerprint(Throwable $e): string
    {
        return md5(implode('|', [
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage(),
        ]));
    }

    public static function formatExceptionData(Throwable $e, int $traceLimit = 10): array
    {
        $file = $e->getFile();
        $line = $e->getLine();

        $trace = array_slice($e->getTrace(), 0, $traceLimit);

        $linePreview = [];
        if (is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $start = max($line - 10, 0);
            $end = min($line + 10, count($lines));

            for ($i = $start; $i < $end; $i++) {
                $content = $lines[$i];

                if (($i + 1) === $line) {
                    $content .= '    // <--- error line';
                }

                $linePreview[$i + 1] = $content;
            }
        }

        return [
            'message' => $e->getMessage(),
            'class' => get_class($e),
            'file' => $file,
            'line' => $line,
            'line_preview' => $linePreview,
            'trace' => array_map(
                fn ($t) => [
                    'file' => $t['file'] ?? null,
                    'line' => $t['line'] ?? null,
                    'function' => $t['function'] ?? null,
                    'class' => $t['class'] ?? null,
                ],
                $trace
            ),
        ];
    }
}
