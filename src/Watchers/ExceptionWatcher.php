<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use LuangDev\Serap\Facades\Serap;
use Throwable;

final class ExceptionWatcher
{
    /**
     * Prevent duplicate capture within same request.
     * @var array<string, bool>
     */
    private static array $seen = [];

    /**
     * Register reportable hook.
     */
    public static function handle(): void
    {
       app()->afterResolving(ExceptionHandler::class, function ($handler) { 
            $handler->reportable(function (Throwable $e) {
                // Guard duplicate
                $hash = self::fingerprint($e);
                if (isset(self::$seen[$hash])) {
                    return;
                }
                self::$seen[$hash] = true;

                // Ensure transaction exists (exception bisa terjadi sebelum middleware)
                $tx = Serap::getTransaction();
                if (empty($tx)) {
                    Serap::setTransaction([
                        'type' => 'request',
                        'name' => 'unknown',
                        'level' => 'error',
                        'sampled' => true,
                        'outcome' => 'failure',
                        'context' => [
                            'request' => [
                                'note' => 'transaction_started_by_exception_watcher',
                            ],
                        ],
                    ]);
                }

                // Mark (optional) supaya kelihatan timing-nya
                Serap::mark('exception_reported');

                // Force transaction outcome + level
                Serap::setOutcome('failure');
                Serap::mergeTransaction([
                    'level' => 'error',
                    'context' => [
                        'error' => [
                            'hash' => $hash,
                            'type' => get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    ],
                ]);

                // Capture exception as a span (unified envelope)
                // Use startSpan/endSpan to get proper start/end ns + end unix us so no 0ms.
                $spanId = Serap::startSpan([
                    'type' => 'error',
                    'name' => self::spanName($e),
                    'level' => 'error',
                    'outcome' => 'failure',
                    'context' => [
                        'error' => self::formatExceptionData($e, $hash),
                    ],
                ]);

                // End immediately (duration will be small but precise in unix_us)
                Serap::endSpan($spanId);

                // Also store a compact record in Serap::exceptions (optional)
                // Keep this SMALL to avoid memory blowups.
                $existing = Serap::getExceptions();
                $existing[] = [
                    'hash' => $hash,
                    'type' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ];
                Serap::setExceptions($existing);

                // Do NOT force finalizeTransaction() here:
                // - ResponseWatcher / middleware terminate / shutdown trap should finalize once.
            });
        });
    }

    private static function spanName(Throwable $e): string
    {
        $msg = trim((string) $e->getMessage());
        if ($msg === '') {
            return get_class($e);
        }

        // avoid super long span names
        if (mb_strlen($msg) > 160) {
            $msg = mb_substr($msg, 0, 160) . '…';
        }

        return $msg;
    }

    /**
     * Unique fingerprint, stable, not exposing full details.
     */
    private static function fingerprint(Throwable $e): string
    {
        return md5(implode('|', [
            get_class($e),
            $e->getFile(),
            $e->getLine(),
            $e->getMessage(),
        ]));
    }

    /**
     * Unified, safe error payload under context.error
     */
    private static function formatExceptionData(Throwable $e, string $hash, int $traceLimit = 15): array
    {
        $file = $e->getFile();
        $line = (int) $e->getLine();

        $trace = array_slice($e->getTrace(), 0, $traceLimit);

        $codePreview = [];
        $capturePreview = (bool) config('serap.capture.error_code_preview', true);
        if ($capturePreview && is_string($file) && $file !== '' && is_file($file) && is_readable($file)) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES);
            if (is_array($lines)) {
                $start = max($line - 10, 1);
                $end = min($line + 10, count($lines));

                for ($i = $start; $i <= $end; $i++) {
                    $content = $lines[$i - 1] ?? '';
                    if ($i === $line) {
                        $content .= '    // <--- error line';
                    }
                    $codePreview[(string) $i] = $content;
                }
            }
        }

        // sanitize / reduce payload
        $captureTrace = (bool) config('serap.capture.error_trace', true);

        return [
            'hash' => $hash,
            'kind' => 'exception',
            'class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $file,
            'line' => $line,
            'code_preview' => $codePreview,
            'trace' => $captureTrace ? array_map(
                static fn ($t) => [
                    'file' => $t['file'] ?? null,
                    'line' => $t['line'] ?? null,
                    'function' => $t['function'] ?? null,
                    'class' => $t['class'] ?? null,
                ],
                $trace
            ) : [],
        ];
    }
}
