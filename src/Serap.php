<?php

namespace LuangDev\Serap;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Clock;

/**
 * Serap core collector
 *
 * - Durasi/marks pakai monotonic clock (hrtime) => akurat
 * - Timestamp absolut (ISO) tetap tersedia lewat kalibrasi ke laravel_start (epoch)
 * - Error selalu bisa dicapture terlepas dari sampling (kalau kamu implement nanti)
 *
 * Catatan:
 * - Pastikan setTransaction() dipanggil PALING AWAL (mis. middleware paling luar)
 * - finalizeTransaction() dipanggil saat request selesai
 */
class Serap
{
    /**
     * Spans bisa berupa:
     * - list span final: addSpan()
     * - map span active: startSpan()/endSpan()
     */
    public array $spans = [];

    public array $exceptions = [];

    public array $transaction = [];

    /**
     * (legacy) properties lama masih dipertahankan supaya tidak breaking.
     * Isinya akan diisi dengan ISO timestamp (bukan ns mentah).
     */
    public string $appBoot = '';
    public string $appRegistered = '';
    public string $routeMatched = '';
    public string $requestHandled = '';
    public string $middlewareHandled = '';
    public string $middlewareEnded = '';
    public string $middlewareTerminated = '';
    public string $appTerminated = '';

    /**
     * Anchor untuk konversi:
     * - anchorMonoNs: monotonic start ns (hrtime)
     * - laravelStartWall: epoch seconds (microtime) dari Laravel
     *
     * Semua marks/duration dihitung relatif ke anchorMonoNs.
     * Semua timestamp event dihasilkan dari laravelStartWall + offsetMonotonic.
     */
    public int $anchorMonoNs = 0;
    public float $laravelStartWall = 0.0;

    /**
     * marks: offset ms dari start transaction (APM-friendly)
     * markTimestamps: ISO timestamp per mark (opsional, bisa dimatikan via config)
     */
    public array $marks = [];
    public array $markTimestamps = [];

    /**
     * Untuk startSpan()/endSpan(): simpan span aktif di map
     */
    private array $activeSpans = [];

    public function __construct()
    {
        // inisialisasi laravelStartWall bila dipakai sebelum setTransaction()
        $this->laravelStartWall = $this->resolveLaravelStartWall();
    }

    /**
     * ===== Metadata =====
     */
    public function getMetadata()
    {
        return [
            'env' => app()->environment(),
            'app_name' => config('app.name'),
            'hostname' => gethostname(),
            'os' => php_uname(),
            'ip' => request()->server('SERVER_ADDR') ?? request()->server('REMOTE_ADDR'),
            'port' => request()->server('SERVER_PORT') ?? request()->server('REMOTE_PORT'),
            'server_name' => request()->server('SERVER_NAME'),
            'http_host' => request()->server('HTTP_HOST'),
            'memory_usage' => SerapUtils::getMemoryUsage(),

            /**
             * timestamps:
             * - timestamp: waktu sekarang (ISO)
             * - laravel_start: waktu mulai request (ISO) dari LARAVEL_START/request_time_float
             * - marks: offset ms (paling penting untuk APM)
             * - mark_timestamps: ISO per event (opsional, untuk debugging)
             */
            'timestamps' => [
                'timestamp' => Clock::nowIso8601(),
                'laravel_start' => $this->formatIsoFromEpoch($this->laravelStartWall),
                ...$this->markTimestamps,
            ],

            /**
             * durations: ringkas
             */
            'durations' => [
                'transaction_ms' => $this->transaction['duration_ms'] ?? null,
                ...$this->marks,
            ],

            'versions' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'serap' => '0.1',
                'schema' => '0.1',
            ],

            'user' => SerapUtils::getAuthUser(),
        ];
    }

    /**
     * ===== Transaction =====
     *
     * Harus dipanggil PALING AWAL (mis. middleware paling luar)
     */
    public function setTransaction(array $transaction)
    {
        // anchor monotonic untuk semua durasi & marks (hrtime)
        $this->anchorMonoNs = Clock::monotonicNs();

        // laravel start (epoch seconds) sebagai anchor wall-clock untuk timestamp absolut
        $this->laravelStartWall = $this->resolveLaravelStartWall();

        $this->transaction = [
            'time_ns' => $this->anchorMonoNs, // internal (boleh kamu drop di payload final)
            'timestamp' => $this->formatIsoFromEpoch($this->laravelStartWall), // anchor timestamp
            'transaction_id' => Str::ulid()->toString(),
            'trace_id' => SerapUtils::getTraceId(),
            ...$transaction
        ];
    }

    public function getTransaction()
    {
        return $this->transaction;
    }

    /**
     * Panggil saat request/command selesai.
     * Menghitung duration_ms, menyertakan marks.
     */
    public function finalizeTransaction(): void
    {
        if ($this->anchorMonoNs === 0) {
            // kalau belum ada transaction, tidak bisa finalize
            return;
        }

        $endNs = Clock::monotonicNs();
        $durationMs = $this->nsToMs($endNs - $this->anchorMonoNs);

        $this->transaction['duration_ms'] = round($durationMs, 3);
        // $this->transaction['marks'] = $this->marks;

        // opsional: timestamp ISO per mark untuk debugging
        // $captureMarkTimestamps = (bool) config('serap.capture.mark_timestamps', true);
        // if ($captureMarkTimestamps) {
        //     $this->transaction['mark_timestamps'] = $this->markTimestamps;
        // }
    }

    /**
     * ===== Marks (Laravel lifecycle events) =====
     *
     * Gunakan ini di event listeners:
     * serap()->mark('route_matched');
     */
    public function mark(string $type): void
    {
        if ($this->anchorMonoNs === 0) {
            // kalau transaction belum dimulai, paksa mulai (optional).
            // Lebih baik: pastikan middleware memanggil setTransaction lebih awal.
            $this->setTransaction([]);
        }

        $eventNs = Clock::monotonicNs();

        // offset ms untuk APM
        $this->marks[$type . '_ms'] = round($this->eventOffsetMs($eventNs), 3);

        // optional ISO timestamp per event (debug-friendly)
        $captureMarkTimestamps = (bool) config('serap.capture.mark_timestamps', true);
        if ($captureMarkTimestamps) {
            $this->markTimestamps[$type] = $this->eventIsoFromNs($eventNs);
        }

        // isi legacy properties sebagai ISO timestamp (agar tetap "timestamps event")
        $this->setTimestamp($type, $this->eventIsoFromNs($eventNs));
    }

    /**
     * Legacy setter: sekarang diisi ISO timestamp (bukan ns mentah).
     */
    public function setTimestamp(string $type, string $timestamp)
    {
        match ($type) {
            'app_booted' => $this->appBoot = $timestamp,
            'app_registered' => $this->appRegistered = $timestamp,
            'route_matched' => $this->routeMatched = $timestamp,
            'request_handled' => $this->requestHandled = $timestamp,
            'middleware_handled' => $this->middlewareHandled = $timestamp,
            'middleware_ended' => $this->middlewareEnded = $timestamp,
            'middleware_terminated' => $this->middlewareTerminated = $timestamp,
            'app_terminated' => $this->appTerminated = $timestamp,
            default => null,
        };
    }

    public function mergeTransaction(array $patch): void
    {
        $this->transaction = array_replace_recursive($this->transaction, $patch);
    }

    /**
     * ===== Spans =====
     *
     * 1) addSpan() -> untuk span final (mis. DB QueryExecuted punya durasi)
     * 2) startSpan()/endSpan() -> untuk span manual (http outbound, cache, custom)
     */

    /**
     * Untuk span final (duration sudah diketahui dari event, mis QueryExecuted).
     * Pastikan span yang kamu kirim sudah punya duration_ms bila diperlukan.
     */
    public function addSpan(array $span)
    {
        $transaction = $this->getTransaction();

        $this->spans[] = [
            'time_ns' => Clock::monotonicNs(), // internal
            'timestamp' => Clock::nowIso8601(),
            'span_id' => Str::ulid()->toString(),
            'parent_id' => $transaction['transaction_id'] ?? $transaction['span_id'] ?? null,
            ...$span
        ];
    }

    /**
     * Mulai span manual (akan dihitung duration saat endSpan).
     */
    public function startSpan(array $span): string
    {
        $transaction = $this->getTransaction();

        $spanId = Str::ulid()->toString();
        $this->activeSpans[$spanId] = [
            'time_ns' => Clock::monotonicNs(), // start ns
            'timestamp' => Clock::nowIso8601(), // span timestamp anchor
            'span_id' => $spanId,
            'parent_id' => $transaction['transaction_id'] ?? null,
            ...$span,
        ];

        return $spanId;
    }

    public function endSpan(string $spanId, array $extra = []): void
    {
        if (!isset($this->activeSpans[$spanId])) {
            return;
        }

        $endNs = Clock::monotonicNs();
        $startNs = (int) $this->activeSpans[$spanId]['time_ns'];

        $span = $this->activeSpans[$spanId];
        unset($this->activeSpans[$spanId]);

        $span['duration_ms'] = round($this->nsToMs($endNs - $startNs), 3);

        if (!empty($extra)) {
            $span = array_merge($span, $extra);
        }

        $this->spans[] = $span;
    }

    public function setSpans(array $spans)
    {
        $this->spans = $spans;
    }

    public function getSpans()
    {
        return $this->spans;
    }

    /**
     * ===== Exceptions =====
     */
    public function setExceptions(array $exceptions)
    {
        $this->exceptions = $exceptions;
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    /**
     * ===== Helpers =====
     */
    private function nsToMs(int $ns): float
    {
        return $ns / 1_000_000;
    }

    private function resolveLaravelStartWall(): float
    {
        if (defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        $rtf = request()->server('REQUEST_TIME_FLOAT');
        if ($rtf !== null) {
            return (float) $rtf;
        }

        // fallback
        return microtime(true);
    }

    private function eventOffsetMs(int $eventNs): float
    {
        return $this->nsToMs($eventNs - $this->anchorMonoNs);
    }

    /**
     * Mengubah monotonic ns event menjadi ISO timestamp absolut,
     * dengan mengikat offset monotonic ke laravelStartWall (epoch).
     */
    private function eventIsoFromNs(int $eventNs): string
    {
        $eventWall = $this->laravelStartWall + (($eventNs - $this->anchorMonoNs) / 1_000_000_000);
        return $this->formatIsoFromEpoch($eventWall);
    }

    private function formatIsoFromEpoch(float $epochSeconds): string
    {
        $sec = (int) floor($epochSeconds);
        $usec = (int) round(($epochSeconds - $sec) * 1_000_000);

        // handle rounding overflow
        if ($usec >= 1_000_000) {
            $sec += 1;
            $usec -= 1_000_000;
        } elseif ($usec < 0) {
            $usec = 0;
        }

        return (new DateTimeImmutable('@' . $sec))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s') . sprintf('.%06dZ', $usec);
    }

    public function reset(): void
    {
        $this->spans = [];
        $this->exceptions = [];
        $this->transaction = [];

        $this->marks = [];
        $this->markTimestamps = [];

        $this->activeSpans = [];

        $this->anchorMonoNs = 0;
        $this->laravelStartWall = $this->resolveLaravelStartWall();

        // legacy timestamps
        $this->appBoot = '';
        $this->appRegistered = '';
        $this->routeMatched = '';
        $this->requestHandled = '';
        $this->middlewareHandled = '';
        $this->middlewareEnded = '';
        $this->middlewareTerminated = '';
        $this->appTerminated = '';
    }
}
