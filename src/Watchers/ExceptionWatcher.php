<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Application;
use Throwable;
use LuangDev\Serap\Services\LogWriterService;

class ExceptionWatcher
{
    public static function handle(): void
    {
        app()->afterResolving(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler) {
            $handler->reportable(function (Throwable $e) {
                LogWriterService::write('exception', self::formatExceptionData($e), 'error');
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
            // 'exception_context' => [
            //     'code' => $e->getCode(),
            //     'previous' => $e->getPrevious() ? get_class($e->getPrevious()) : null,
            // ],
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
