<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use LuangDev\Serap\SerapUtils;
use Throwable;

class ExceptionWatcher
{
    public static function handle(): void
    {
        app()->afterResolving(ExceptionHandler::class, function ($handler) {
            $handler->reportable(function (Throwable $e) {
                $context = self::formatExceptionData($e);

                SerapUtils::writeJsonl(eventName: 'exception', context: $context, level: 'error');
            });
        });
    }

    public static function formatExceptionData(Throwable $e, int $traceLimit = 5, int $stringLimit = 500): array
    {
        $file = $e->getFile();
        $line = $e->getLine();

        $trace = array_slice($e->getTrace(), 0, $traceLimit);

        $safeMessage = mb_strimwidth($e->getMessage(), 0, $stringLimit, '...');

        $linePreview = [];
        if (is_file($file) && is_readable($file)) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            $start = max($line - 5, 0);
            $end = min($line + 5, count($lines));

            for ($i = $start; $i < $end; $i++) {
                $content = $lines[$i];

                if (($i + 1) === $line) {
                    $content .= '    // <--- error line';
                }

                $linePreview[$i + 1] = $content;
            }
        }

        return [
            'message' => $safeMessage,
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
