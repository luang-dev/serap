<?php

namespace LuangDev\Serap;

use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Clock;

/**
 * Serap core collector (unified event envelope + epoch ms/us + monotonic ns)
 *
 * Unified event fields (transaction & span):
 * - kind: "transaction" | "span"
 * - schema_version
 * - trace_id
 * - id
 * - parent_id
 * - start_time_ns, end_time_ns (monotonic ns)
 * - start_unix_ms, end_unix_ms (epoch ms)
 * - start_unix_us, end_unix_us (epoch us)  <-- avoid 0ms spans
 * - duration_ms
 * - type, name, level
 * - sampled, outcome
 * - context: event-specific payload
 *
 * Notes:
 * - setTransaction() must be called first
 * - finalizeTransaction() at the end
 * - reset() each request (singleton)
 */
class Serap
{
    /** @var array<int, array<string,mixed>> */
    public array $spans = [];

    public array $exceptions = [];

    /** @var array<string,mixed> */
    public array $transaction = [];

    // Legacy (optional): epoch ms string (keep if you still need)
    public string $appBoot = '';
    public string $appRegistered = '';
    public string $routeMatched = '';
    public string $requestHandled = '';
    public string $middlewareHandled = '';
    public string $middlewareEnded = '';
    public string $middlewareTerminated = '';
    public string $appTerminated = '';

    /**
     * Anchors:
     * - anchorMonoNs: monotonic ns saat transaction dimulai
     * - laravelStartWall: epoch seconds (float) untuk request start
     */
    public int $anchorMonoNs = 0;
    public float $laravelStartWall = 0.0;

    public array $marks = [];
    public array $markTimestamps = []; // absolute unix ms/us per mark (optional)

    /** @var array<string, array<string,mixed>> */
    private array $activeSpans = [];

    /** @var list<string> */
    private array $spanStack = [];

    /** Schema version for payload */
    private string $schemaVersion = '1.0';

    public function __construct()
    {
        $this->laravelStartWall = $this->resolveLaravelStartWall();
    }

    /**
     * ===== Metadata =====
     * Global info, tidak perlu dicopy ke setiap event.
     * User disimpan di metadata (bukan di transaction/context) untuk menghindari duplikasi.
     */
    public function getMetadata(): array
    {
        return [
            'schema_version' => $this->schemaVersion,

            'env' => app()->environment(),
            'app_name' => config('app.name'),
            'hostname' => gethostname(),
            'os' => php_uname(),
            'ip' => request()->server('SERVER_ADDR') ?? request()->server('REMOTE_ADDR'),
            'port' => request()->server('SERVER_PORT') ?? request()->server('REMOTE_PORT'),
            'server_name' => request()->server('SERVER_NAME'),
            'http_host' => request()->server('HTTP_HOST'),
            'memory_usage' => SerapUtils::getMemoryUsage(),

            'timestamps' => [
                'generated_unix_ms' => $this->epochSecondsToUnixMs(microtime(true)),
                'generated_unix_us' => $this->epochSecondsToUnixUs(microtime(true)),
                'laravel_start_unix_ms' => $this->epochSecondsToUnixMs($this->laravelStartWall),
                'laravel_start_unix_us' => $this->epochSecondsToUnixUs($this->laravelStartWall),
                ...$this->markTimestamps,
            ],

            'durations' => [
                'transaction_ms' => $this->transaction['duration_ms'] ?? null,
                ...$this->marks,
            ],

            'versions' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'serap' => '0.1',
                'schema' => $this->schemaVersion,
            ],

            // user only once
            'user' => SerapUtils::getAuthUser(),
        ];
    }

    /**
     * ===== Unified Event Base =====
     */
    private function newEventBase(string $kind, array $fields = []): array
    {
        $id = Str::ulid()->toString();
        $startNs = Clock::monotonic();

        $startUs = $this->eventUnixUsFromNs($startNs);
        $startMs = (int) intdiv($startUs, 1000);

        return [
            'schema_version' => $this->schemaVersion,
            'kind' => $kind, // "transaction" | "span"
            'trace_id' => SerapUtils::getTraceId(),
            'id' => $id,
            'parent_id' => null,

            // monotonic timestamps (accurate duration)
            'start_time_ns' => $startNs,
            'end_time_ns' => null,

            // absolute epoch timestamps
            'start_unix_us' => $startUs,
            'end_unix_us' => null,
            'start_unix_ms' => $startMs,
            'end_unix_ms' => null,

            // computed duration
            'duration_ms' => null,

            // semantics
            'type' => null,
            'name' => null,
            'level' => 'info',

            // sampling/outcome
            'sampled' => true,
            'outcome' => 'unknown', // success|failure|unknown

            // payload
            'context' => [],

            ...$fields,
        ];
    }

    /**
     * ===== Transaction =====
     */
    public function setTransaction(array $transaction): void
    {
        // anchor monotonic for mark/duration math
        $this->anchorMonoNs = Clock::monotonic();

        // anchor wall clock
        $this->laravelStartWall = $this->resolveLaravelStartWall();

        // transaction start = laravel start
        $startUs = $this->epochSecondsToUnixUs($this->laravelStartWall);
        $startMs = (int) intdiv($startUs, 1000);

        $base = $this->newEventBase('transaction', [
            'start_time_ns' => $this->anchorMonoNs,
            'start_unix_us' => $startUs,
            'start_unix_ms' => $startMs,
            'parent_id' => null,
        ]);

        $tx = array_replace_recursive($base, $transaction);

        // recommended defaults
        $tx['sampled'] = $tx['sampled'] ?? (bool) config('serap.sampling.sampled', true);
        $tx['outcome'] = $tx['outcome'] ?? 'unknown';

        // drop user duplication: keep only user_id pointer in transaction
        $user = SerapUtils::getAuthUser();
        if (is_array($user) && array_key_exists('id', $user)) {
            $tx['context']['user_id'] = $user['id'];
        }

        $this->transaction = $tx;
    }

    public function getTransaction(): array
    {
        return $this->transaction;
    }

    public function mergeTransaction(array $patch): void
    {
        $this->transaction = array_replace_recursive($this->transaction, $patch);
    }

    /**
     * Set outcome based on HTTP status or exception.
     */
    public function setOutcome(string $outcome): void
    {
        if (empty($this->transaction)) {
            return;
        }
        $this->transaction['outcome'] = $outcome; // success|failure|unknown
    }

    /**
     * Finalize transaction:
     * - end_time_ns
     * - duration_ms from monotonic (end-start)
     * - end_unix_us/ms from start + duration
     */
    public function finalizeTransaction(): void
    {
        if ($this->anchorMonoNs === 0 || empty($this->transaction)) {
            return;
        }

        $endNs = Clock::monotonic();
        $startNs = (int) ($this->transaction['start_time_ns'] ?? $this->anchorMonoNs);

        $durMs = $this->nsToMs($endNs - $startNs);

        $this->transaction['end_time_ns'] = $endNs;
        $this->transaction['duration_ms'] = round($durMs, 3);

        // compute end epoch with microsecond precision
        $startUs = (int) ($this->transaction['start_unix_us'] ?? $this->epochSecondsToUnixUs($this->laravelStartWall));
        $durUs = (int) round(((float) $this->transaction['duration_ms']) * 1000);

        $endUs = $startUs + $durUs;

        $this->transaction['end_unix_us'] = $endUs;
        $this->transaction['end_unix_ms'] = (int) intdiv($endUs, 1000);

        // optional attach marks into context
        $this->transaction['context']['marks'] = $this->marks;

        if ((bool) config('serap.capture.mark_timestamps', true)) {
            $this->transaction['context']['mark_timestamps'] = $this->markTimestamps;
        }

        // If still unknown, infer from response status if present
        if (($this->transaction['outcome'] ?? 'unknown') === 'unknown') {
            $status = $this->transaction['context']['response']['status'] ?? null;
            if (is_int($status)) {
                $this->transaction['outcome'] = ($status >= 500) ? 'failure' : 'success';
            }
        }
    }

    /**
     * ===== Marks =====
     */
    public function mark(string $type): void
    {
        if ($this->anchorMonoNs === 0) {
            $this->setTransaction([]);
        }

        $eventNs = Clock::monotonic();

        // offset ms from tx anchor
        $this->marks[$type . '_ms'] = round($this->eventOffsetMs($eventNs), 3);

        // optional absolute unix timestamps for marks
        $capture = (bool) config('serap.capture.mark_timestamps', true);
        if ($capture) {
            $ms = $this->eventUnixMsFromNs($eventNs);
            $us = $this->eventUnixUsFromNs($eventNs);

            $this->markTimestamps[$type . '_unix_ms'] = $ms;
            $this->markTimestamps[$type . '_unix_us'] = $us;
        }

        // legacy (epoch ms string)
        $this->setTimestamp($type, (string) $this->eventUnixMsFromNs($eventNs));
    }

    public function setTimestamp(string $type, string $timestamp): void
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

    /**
     * ===== Span Parenting =====
     * - Default parent: current active span (top of stack)
     * - Else: transaction.id
     */
    private function currentParentId(): ?string
    {
        $top = end($this->spanStack);
        if (is_string($top) && $top !== '') {
            return $top;
        }
        return $this->transaction['id'] ?? null;
    }

    private function pushSpan(string $spanId): void
    {
        $this->spanStack[] = $spanId;
    }

    private function popSpan(string $spanId): void
    {
        // pop until matching (safe for misordered endSpan)
        for ($i = count($this->spanStack) - 1; $i >= 0; $i--) {
            if ($this->spanStack[$i] === $spanId) {
                array_splice($this->spanStack, $i, 1);
                break;
            }
        }
    }

    /**
     * ===== Spans =====
     */

    /**
     * addSpan(): span final (duration_ms already known, e.g. QueryExecuted)
     */
    public function addSpan(array $span): void
    {
        if (empty($this->transaction)) {
            // safety: if spans happen before tx
            $this->setTransaction([]);
        }

        $base = $this->newEventBase('span', [
            'parent_id' => $this->currentParentId(),
        ]);

        $ev = array_replace_recursive($base, $span);

        // compute end_time_ns + end_unix_us/ms if duration exists
        if (isset($ev['duration_ms']) && $ev['duration_ms'] !== null) {
            $startNs = (int) $ev['start_time_ns'];
            $durMs = (float) $ev['duration_ms'];

            $ev['end_time_ns'] = $startNs + (int) round($durMs * 1_000_000);

            $startUs = (int) ($ev['start_unix_us'] ?? $this->eventUnixUsFromNs($startNs));
            $durUs = (int) round($durMs * 1000);
            $endUs = $startUs + $durUs;

            $ev['end_unix_us'] = $endUs;
            $ev['end_unix_ms'] = (int) intdiv($endUs, 1000);
        }

        $this->spans[] = $ev;
    }

    /**
     * startSpan(): span manual (nested supported)
     */
    public function startSpan(array $span): string
    {
        if (empty($this->transaction)) {
            $this->setTransaction([]);
        }

        $base = $this->newEventBase('span', [
            'parent_id' => $this->currentParentId(),
        ]);

        $ev = array_replace_recursive($base, $span);
        $spanId = (string) $ev['id'];

        $this->activeSpans[$spanId] = $ev;
        $this->pushSpan($spanId);

        return $spanId;
    }

    /**
     * endSpan(): close manual span
     */
    public function endSpan(string $spanId, array $patch = []): void
    {
        if (!isset($this->activeSpans[$spanId])) {
            return;
        }

        $endNs = Clock::monotonic();

        $ev = $this->activeSpans[$spanId];
        unset($this->activeSpans[$spanId]);
        $this->popSpan($spanId);

        $startNs = (int) ($ev['start_time_ns'] ?? 0);
        $ev['end_time_ns'] = $endNs;

        if ($startNs > 0) {
            $durMs = $this->nsToMs($endNs - $startNs);
            $ev['duration_ms'] = round($durMs, 3);

            $startUs = (int) ($ev['start_unix_us'] ?? $this->eventUnixUsFromNs($startNs));
            $durUs = (int) round(((float) $ev['duration_ms']) * 1000);
            $endUs = $startUs + $durUs;

            $ev['end_unix_us'] = $endUs;
            $ev['end_unix_ms'] = (int) intdiv($endUs, 1000);
        } else {
            $ev['duration_ms'] = 0.0;
            $ev['end_unix_us'] = (int) ($ev['start_unix_us'] ?? $this->eventUnixUsFromNs($endNs));
            $ev['end_unix_ms'] = (int) intdiv((int) $ev['end_unix_us'], 1000);
        }

        if (!empty($patch)) {
            $ev = array_replace_recursive($ev, $patch);
        }

        $this->spans[] = $ev;
    }

    public function getSpans(): array
    {
        return $this->spans;
    }

    public function setSpans(array $spans): void
    {
        $this->spans = $spans;
    }

    /**
     * ===== Response header sanitization helpers =====
     * Use allowlist by default; drop set-cookie.
     */
    public function sanitizeResponseHeaders(array $headers): array
    {
        // Drop set-cookie entirely (recommended)
        unset($headers['set-cookie'], $headers['Set-Cookie'], $headers['set_cookie']);

        $mode = (string) config('serap.sanitize.response_headers', 'allowlist'); // allowlist|mask|off

        if ($mode === 'off') {
            return [];
        }

        if ($mode === 'mask') {
            // mask everything but keep keys
            return SerapUtils::mask($headers);
        }

        // allowlist
        $allow = config('serap.sanitize.response_headers_allow', [
            'cache-control',
            'content-type',
            'content-length',
            'date',
            'x-serap-trace-id',
        ]);

        $allow = array_map('strtolower', (array) $allow);

        $out = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if (in_array($lk, $allow, true)) {
                $out[$lk] = SerapUtils::mask($v);
            }
        }

        return $out;
    }

    /**
     * ===== Exceptions =====
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    public function setExceptions(array $exceptions): void
    {
        $this->exceptions = $exceptions;

        // If any exception exists => outcome failure (unless you want more nuance)
        if (!empty($exceptions)) {
            $this->setOutcome('failure');
        }
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

        return microtime(true);
    }

    private function eventOffsetMs(int $eventNs): float
    {
        return $this->nsToMs($eventNs - $this->anchorMonoNs);
    }

    private function epochSecondsToUnixMs(float $epochSeconds): int
    {
        return (int) floor($epochSeconds * 1000);
    }

    private function epochSecondsToUnixUs(float $epochSeconds): int
    {
        return (int) floor($epochSeconds * 1_000_000);
    }

    /**
     * Convert monotonic ns -> absolute unix us/ms
     */
    private function eventUnixUsFromNs(int $eventNs): int
    {
        $eventWallSeconds = $this->laravelStartWall + (($eventNs - $this->anchorMonoNs) / 1_000_000_000);
        return $this->epochSecondsToUnixUs($eventWallSeconds);
    }

    private function eventUnixMsFromNs(int $eventNs): int
    {
        return (int) intdiv($this->eventUnixUsFromNs($eventNs), 1000);
    }

    /**
     * Reset state per request (Serap singleton).
     */
    public function reset(): void
    {
        $this->spans = [];
        $this->exceptions = [];
        $this->transaction = [];

        $this->marks = [];
        $this->markTimestamps = [];

        $this->activeSpans = [];
        $this->spanStack = [];

        $this->anchorMonoNs = 0;
        $this->laravelStartWall = $this->resolveLaravelStartWall();

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
