<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use LuangDev\Serap\Facades\Serap;

final class QueryWatcher
{
    /**
     * Per-request aggregates (static; reset via flush()).
     *
     * @var array{
     *   total_queries:int,
     *   total_duration_ms:float,
     *   groups: array<string, array{
     *     key:string,
     *     count:int,
     *     total_duration_ms:float,
     *     max_duration_ms:float,
     *     min_duration_ms:float,
     *     avg_duration_ms:float,
     *     statement:string,
     *     sample_sql:string,
     *     operation:string,
     *     driver:string,
     *     connection:string,
     *     database:?string,
     *     callsite: array{file:?string,line:?int}|null,
     *     any_slow: bool,
     *     distinct_bindings: array<string,bool>,
     *     distinct_bindings_count:int,
     *     nplus_one_label:?string
     *   }>,
     *   span_map: list<array{span_id:string,key:string}>
     * }
     */
    private static array $agg = [
        'total_queries' => 0,
        'total_duration_ms' => 0.0,
        'groups' => [],
        'span_map' => [],
    ];

    private static bool $flushed = false;

    public function handle(QueryExecuted $event): void
    {
        if ($this->shouldSkipQuery($event->sql)) {
            return;
        }

        $slowQueryThresholdMs = (float) config('serap.capture.slow_query_ms', 1000);
        $durationMs = (float) $event->time;
        $isSlow = $durationMs >= $slowQueryThresholdMs;

        // $bindingsMode = (string) config('serap.capture.sql_bindings', 'off'); // off|local_only|on
        $bindings = $event->bindings;

        $maskedBindings = [];
        // if ($bindingsMode === 'on' || ($bindingsMode === 'local_only' && app()->environment('local'))) {
        $maskedBindings = $this->mapBindingsWithColumns($event->sql, $bindings);

        // hard cap bindings payload
        $maskedBindings = self::capArray($maskedBindings, (int) config('serap.payload.max_bindings_fields', 50));
        // }

        // --- callsite untuk N+1 detector ---
        $callsite = $this->detectCallsite();

        // --- group key (fingerprint + callsite) ---
        $driver = (string) $event->connection->getDriverName();
        $connection = (string) $event->connectionName;
        $dbName = $this->getDbName($event);
        $operation = $this->getQueryType($event->sql);

        $fingerprint = $this->fingerprintSql($event->sql);

        // normalize callsite file for key stability
        $csFile = (string) ($callsite['file'] ?? '');
        $csLine = (int) ($callsite['line'] ?? 0);

        $key = $driver.'|'.$connection.'|'.$operation.'|'.$fingerprint.'|'
            .$csFile.':'.$csLine;

        // === hard caps for SQL payload ===
        $rawSql = (string) $event->sql;
        $sqlMaxLen = (int) config('serap.payload.max_sql_len', 2000);
        $sqlForContext = self::capString($rawSql, $sqlMaxLen);

        $normalizedForUi = $this->normalizeSqlForUi($rawSql);
        $normalizedMaxLen = (int) config('serap.payload.max_sql_normalized_len', 1000);
        $normalizedForUi = self::capString($normalizedForUi, $normalizedMaxLen);

        // === bindings signature for N+1 signal (distinct bindings count) ===
        $bindingsSig = self::bindingsSignature($bindings);
        $distinctCap = (int) config('serap.nplus1.max_distinct_bindings', 50);

        /**
         * ===== 1) Emit span per query (waterfall) =====
         * Pakai startSpan/endSpan supaya dapat span_id dan bisa di-update saat flush.
         *
         * NOTE:
         * - duration query asli dari QueryExecuted ($event->time).
         * - endSpan() menghitung durasi watcher, jadi kita patch duration_ms di endSpan.
         */
        $spanId = Serap::startSpan([
            'type' => 'db',
            'name' => $this->shortName($rawSql),
            'level' => $isSlow ? 'warning' : 'info',
            'outcome' => 'unknown',
            'sampled' => (bool) $isSlow, // slow DB query -> sampled true langsung
            'context' => [
                'driver' => $driver,
                'connection' => $connection,
                'database' => $dbName,
                'operation' => $operation,

                // hard capped sql
                'statement' => $sqlForContext,
                'statement_truncated' => (strlen($rawSql) > strlen($sqlForContext)),

                'bindings' => $maskedBindings,

                'callsite' => $callsite,

                'slow' => $isSlow,
                'threshold_ms' => $slowQueryThresholdMs,

                // N+1 fields (will be set true/label at flush)
                'suspected_nplus_one' => false,
                'nplus_one_label' => null,
                'distinct_bindings_count' => null,
            ],
        ]);

        Serap::endSpan($spanId, [
            'duration_ms' => $durationMs,
        ]);

        // map span -> group key (untuk marking N+1 di flush)
        self::$agg['span_map'][] = [
            'span_id' => $spanId,
            'key' => $key,
        ];

        /**
         * ===== 2) Aggregate for summarization / N+1 =====
         */
        self::$agg['total_queries'] += 1;
        self::$agg['total_duration_ms'] += $durationMs;

        if (! isset(self::$agg['groups'][$key])) {
            self::$agg['groups'][$key] = [
                'key' => $key,
                'count' => 0,
                'total_duration_ms' => 0.0,
                'max_duration_ms' => 0.0,
                'min_duration_ms' => $durationMs,
                'avg_duration_ms' => 0.0,

                // normalized statement for grouping/UI
                'statement' => $normalizedForUi,
                'sample_sql' => self::capString($rawSql, (int) config('serap.payload.max_sample_sql_len', 1000)),

                'operation' => $operation,
                'driver' => $driver,
                'connection' => $connection,
                'database' => $dbName,
                'callsite' => $callsite,

                'any_slow' => false,

                // N+1 enrichment
                'distinct_bindings' => [],
                'distinct_bindings_count' => 0,
                'nplus_one_label' => null,
            ];
        }

        $g = &self::$agg['groups'][$key];

        $g['count'] += 1;
        $g['total_duration_ms'] += $durationMs;
        $g['max_duration_ms'] = max((float) $g['max_duration_ms'], $durationMs);
        $g['min_duration_ms'] = min((float) $g['min_duration_ms'], $durationMs);
        $g['avg_duration_ms'] = $g['total_duration_ms'] / max(1, $g['count']);

        if ($isSlow) {
            $g['any_slow'] = true;
        }

        // distinct bindings count (hard cap)
        if ($bindingsSig !== null && count($g['distinct_bindings']) < $distinctCap) {
            $g['distinct_bindings'][$bindingsSig] = true;
            $g['distinct_bindings_count'] = count($g['distinct_bindings']);
        } else {
            $g['distinct_bindings_count'] = count($g['distinct_bindings']);
        }

        unset($g);
    }

    /**
     * Flush summary into transaction context (call ONCE at end of request).
     *
     * Policy OR:
     * - N+1 saja -> force all sampled
     * - Slow DB saja -> force all sampled
     * - Slow transaction saja -> force all sampled
     * - Kombinasi apapun -> force all sampled
     */
    public static function flush(): void
    {
        if (self::$flushed) {
            return;
        }
        self::$flushed = true;

        $nplus1Threshold = (int) config('serap.nplus1.threshold', 2);
        $minTotalMs = (float) config('serap.nplus1.min_total_ms', 0.0);
        $maxGroups = (int) config('serap.db_summary.max_groups', 50);

        $slowTxnThresholdMs = (float) config('serap.capture.slow_transaction_ms', 1000);

        $groups = array_values(self::$agg['groups']);

        // sort by total_duration desc
        usort($groups, static fn ($a, $b) => ($b['total_duration_ms'] <=> $a['total_duration_ms']));

        // compute N+1 candidates
        $nPlusOne = array_values(array_filter(
            $groups,
            static function ($g) use ($nplus1Threshold, $minTotalMs): bool {
                $count = (int) ($g['count'] ?? 0);
                $total = (float) ($g['total_duration_ms'] ?? 0.0);

                if ($count < $nplus1Threshold) {
                    return false;
                }
                if ($minTotalMs > 0 && $total < $minTotalMs) {
                    return false;
                }

                return true;
            }
        ));

        $topGroups = array_slice($groups, 0, max(0, $maxGroups));
        $topNPlusOne = array_slice($nPlusOne, 0, 20);

        // key set for N+1
        $nPlusOneKeys = [];
        foreach ($topNPlusOne as $g) {
            if (! empty($g['key'])) {
                $nPlusOneKeys[$g['key']] = true;
            }
        }
        $hasNPlusOne = ! empty($nPlusOneKeys);

        // slow db query?
        $hasSlowDbQuery = false;
        foreach (self::$agg['groups'] as $g) {
            if (! empty($g['any_slow'])) {
                $hasSlowDbQuery = true;
                break;
            }
        }

        // slow transaction? (use transaction duration_ms if available)
        $tx = Serap::getTransaction();
        $txDurationMs = (float) ($tx['duration_ms'] ?? 0.0);
        $hasSlowTransaction = $txDurationMs >= $slowTxnThresholdMs;

        // policy OR
        $forceSampleAll = $hasNPlusOne || $hasSlowDbQuery || $hasSlowTransaction;

        // reasons
        $reasons = [];
        if ($hasNPlusOne) {
            $reasons[] = 'n_plus_one';
        }
        if ($hasSlowDbQuery) {
            $reasons[] = 'slow_db_query';
        }
        if ($hasSlowTransaction) {
            $reasons[] = 'slow_transaction';
        }
        $forceSampledReason = ! empty($reasons) ? implode('|', $reasons) : null;

        // get spans once, build index
        $spans = Serap::getSpans();
        $idx = [];
        foreach ($spans as $i => $s) {
            if (isset($s['id']) && is_string($s['id'])) {
                $idx[$s['id']] = $i;
            }
        }

        // enrich N+1 spans: set suspected_nplus_one + label + distinct_bindings_count
        if ($hasNPlusOne) {
            foreach (self::$agg['span_map'] as $m) {
                $sid = $m['span_id'] ?? null;
                $key = $m['key'] ?? null;

                if (! is_string($sid) || ! isset($idx[$sid])) {
                    continue;
                }
                if (! is_string($key) || ! isset($nPlusOneKeys[$key])) {
                    continue;
                }

                $i = $idx[$sid];
                $g = self::$agg['groups'][$key] ?? null;

                $distinct = (int) ($g['distinct_bindings_count'] ?? 0);
                $label = self::labelNPlusOne($g);

                $spans[$i]['context']['suspected_nplus_one'] = true;
                $spans[$i]['context']['nplus_one_label'] = $label;
                $spans[$i]['context']['distinct_bindings_count'] = $distinct;

                // N+1 biasanya perlu terlihat
                $spans[$i]['sampled'] = true;
                if (($spans[$i]['level'] ?? 'info') === 'info') {
                    $spans[$i]['level'] = 'warning';
                }
            }
        }

        // force sampled true for ALL spans + transaction (policy OR)
        if ($forceSampleAll) {
            Serap::mergeTransaction([
                'sampled' => true,
                'level' => 'warning',
            ]);

            foreach ($spans as $i => $s) {
                $spans[$i]['sampled'] = true;
            }
        }

        Serap::setSpans($spans);

        // summary payload with caps (top & n_plus_one)
        $summary = [
            'total_queries' => (int) self::$agg['total_queries'],
            'unique_queries' => count(self::$agg['groups']),
            'total_duration_ms' => round((float) self::$agg['total_duration_ms'], 3),

            // split slow flags
            'has_slow_db_query' => $hasSlowDbQuery,
            'slow_db_threshold_ms' => (float) config('serap.capture.slow_query_ms', 1000),

            'has_slow_transaction' => $hasSlowTransaction,
            'slow_transaction_threshold_ms' => $slowTxnThresholdMs,
            'transaction_duration_ms' => round($txDurationMs, 3),

            // sampling
            'force_sampled' => $forceSampleAll,
            'force_sampled_reason' => $forceSampledReason,
            'force_sampled_reasons' => $reasons,

            'n_plus_one' => array_map(static function ($g) {
                $label = self::labelNPlusOne($g);
                $distinct = (int) ($g['distinct_bindings_count'] ?? 0);

                return [
                    'key' => $g['key'],
                    'count' => (int) $g['count'],
                    'total_duration_ms' => round((float) $g['total_duration_ms'], 3),
                    'avg_duration_ms' => round((float) $g['avg_duration_ms'], 3),
                    'max_duration_ms' => round((float) $g['max_duration_ms'], 3),
                    'operation' => $g['operation'],
                    'driver' => $g['driver'],
                    'connection' => $g['connection'],
                    'database' => $g['database'],
                    'statement' => self::capString((string) $g['statement'], (int) config('serap.payload.max_summary_sql_len', 500)),
                    'callsite' => $g['callsite'] ?? null,
                    'sample_sql' => self::capString((string) $g['sample_sql'], (int) config('serap.payload.max_summary_sample_sql_len', 500)),
                    'distinct_bindings_count' => $distinct,
                    'nplus_one_label' => $label,
                ];
            }, $topNPlusOne),

            'top' => array_map(static function ($g) {
                return [
                    'key' => $g['key'],
                    'count' => (int) $g['count'],
                    'total_duration_ms' => round((float) $g['total_duration_ms'], 3),
                    'avg_duration_ms' => round((float) $g['avg_duration_ms'], 3),
                    'max_duration_ms' => round((float) $g['max_duration_ms'], 3),
                    'operation' => $g['operation'],
                    'driver' => $g['driver'],
                    'connection' => $g['connection'],
                    'database' => $g['database'],
                    'statement' => self::capString((string) $g['statement'], (int) config('serap.payload.max_summary_sql_len', 500)),
                    'callsite' => $g['callsite'] ?? null,
                    'distinct_bindings_count' => (int) ($g['distinct_bindings_count'] ?? 0),
                ];
            }, $topGroups),
        ];

        // merge summary into transaction context (without blowing up other keys)
        $existingTx = Serap::getTransaction();
        $existingDbSummary = (array) ($existingTx['context']['db_summary'] ?? []);

        Serap::mergeTransaction([
            'context' => [
                'db_summary' => array_replace($existingDbSummary, $summary),
            ],
        ]);

        // Optional: emit “summary span”
        $shouldSpan = (bool) config('serap.db_summary.as_span', true);
        if ($shouldSpan) {
            Serap::addSpan([
                'type' => 'db',
                'name' => 'DB summary',
                'level' => ($hasNPlusOne || $hasSlowDbQuery || $hasSlowTransaction) ? 'warning' : 'info',
                'duration_ms' => 0.0,
                'sampled' => $forceSampleAll ? true : (! empty($topNPlusOne) ? true : false),
                'context' => [
                    'summary' => [
                        'total_queries' => $summary['total_queries'],
                        'unique_queries' => $summary['unique_queries'],
                        'total_duration_ms' => $summary['total_duration_ms'],
                        'n_plus_one_count' => count($topNPlusOne),

                        'has_slow_db_query' => $hasSlowDbQuery,
                        'has_slow_transaction' => $hasSlowTransaction,
                        'force_sampled' => $forceSampleAll,
                        'force_sampled_reason' => $forceSampledReason,
                    ],
                ],
            ]);
        }

        self::reset();
    }

    public static function reset(): void
    {
        self::$agg = [
            'total_queries' => 0,
            'total_duration_ms' => 0.0,
            'groups' => [],
            'span_map' => [],
        ];
        self::$flushed = false;
    }

    /**
     * Detect callsite yang menunjuk kode app (routes/ atau app/),
     * bukan vendor/laravel/framework dan bukan package serap.
     */
    private function detectCallsite(): array
    {
        $limit = (int) config('serap.capture.query_callsite_frames', 40);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, max(10, $limit));

        $bestFallback = ['file' => null, 'line' => null];

        $base = function_exists('base_path')
            ? rtrim((string) base_path(), '\\/')
            : null;

        foreach ($trace as $t) {
            $file = $t['file'] ?? null;
            $line = $t['line'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            $f = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $file);

            // skip vendor
            if (str_contains($f, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            // skip package serap
            if (str_contains($f, DIRECTORY_SEPARATOR.'packages'.DIRECTORY_SEPARATOR.'luang-dev'.DIRECTORY_SEPARATOR.'serap'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            // skip namespace LuangDev\Serap
            $cls = $t['class'] ?? '';
            if (is_string($cls) && str_starts_with($cls, 'LuangDev\\Serap\\')) {
                continue;
            }

            // normalize relatif terhadap base_path untuk UI
            $outFile = $f;
            if ($base && str_starts_with($outFile, $base)) {
                $outFile = ltrim(substr($outFile, strlen($base)), '\\/');
            }

            $candidate = [
                'file' => $outFile,
                'line' => is_int($line) ? $line : null,
            ];

            if ($bestFallback['file'] === null) {
                $bestFallback = $candidate;
            }

            // prefer routes/ atau app/
            if (
                str_contains($outFile, 'routes'.DIRECTORY_SEPARATOR) ||
                str_contains($outFile, 'app'.DIRECTORY_SEPARATOR)
            ) {
                return $candidate;
            }
        }

        return $bestFallback;
    }

    protected function getDbName(QueryExecuted $event): ?string
    {
        return method_exists($event->connection, 'getDatabaseName')
            ? $event->connection->getDatabaseName()
            : null;
    }

    protected function getQueryType(string $sql): string
    {
        $s = ltrim($sql);

        return strtoupper(strtok($s, " \t\n\r")) ?: 'UNKNOWN';
    }

    protected function shortName(string $sql): string
    {
        $op = $this->getQueryType($sql);

        return $op !== 'UNKNOWN' ? $op.' query' : 'DB query';
    }

    protected function shouldSkipQuery(string $sql): bool
    {
        $skipTables = ['jobs', 'failed_jobs', 'cache', 'sessions'];

        foreach ($skipTables as $table) {
            if (
                stripos($sql, '"'.$table.'"') !== false ||
                stripos($sql, '`'.$table.'`') !== false ||
                stripos($sql, ' '.$table.' ') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fingerprint query for grouping:
     * - strip comments
     * - collapse whitespace
     * - normalize case
     */
    private function fingerprintSql(string $sql): string
    {
        $s = $this->normalizeSqlForUi($sql);
        $cap = (int) config('serap.payload.max_fingerprint_sql_len', 500);
        $s = self::capString($s, $cap);

        return md5($s);
    }

    /**
     * Normalization for grouping/UI (stable representation).
     */
    private function normalizeSqlForUi(string $sql): string
    {
        $s = $sql;
        $s = preg_replace('~/\*.*?\*/~s', ' ', $s) ?? $s;
        $s = preg_replace('~--[^\r\n]*~', ' ', $s) ?? $s;
        $s = preg_replace('~\s+~', ' ', $s) ?? $s;
        $s = trim($s);
        $s = strtolower($s);

        return $s;
    }

    // ===== binding helpers (same logic) =====

    public function mapBindingsWithColumns(string $sql, array $bindings): array
    {
        $mapped = [];
        $bindingIndex = 0;

        $sensitive = array_map(
            fn ($k) => $this->normalizeKey($k),
            config('serap.sanitize.field_deny', ['password', 'token', 'secret'])
        );

        if (preg_match_all('/([`\w\.\"]+)\s*(=|<|>|<=|>=|LIKE|BETWEEN|IN)\s*(\?|[\(])/i', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $col = $this->normalizeKey($match[1]);
                $op = strtoupper($match[2]);

                if ($op === 'BETWEEN') {
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col.'_from'] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col.'_to'] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                } elseif ($op === 'IN') {
                    if (preg_match('/\bIN\s*\(([^)]+)\)/i', $match[0], $inMatch)) {
                        $placeholders = substr_count($inMatch[1], '?');
                        $values = [];
                        for ($i = 0; $i < $placeholders; $i++) {
                            if (isset($bindings[$bindingIndex])) {
                                $values[] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                            }
                        }
                        $mapped[$col] = $values;
                    }
                } else {
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                }
            }
        }

        if (preg_match_all('/SET\s+[`"]?(\w+)[`"]?\s*=\s*\?/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                $col = $this->normalizeKey($col);
                if (isset($bindings[$bindingIndex])) {
                    $mapped[$col] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                }
            }
        }

        if (preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
            $cols = array_map(
                fn ($c) => $this->normalizeKey(str_replace(['`', '"'], '', $c)),
                explode(',', $m[1])
            );

            foreach ($cols as $col) {
                if (isset($bindings[$bindingIndex])) {
                    $mapped[$col] = $this->maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                }
            }
        }

        return $mapped;
    }

    protected function normalizeKey(string $key): string
    {
        $key = trim($key, '`" ');
        $key = strtolower($key);

        return str_replace('-', '_', $key);
    }

    protected function maskIfSensitive(string $col, $value, array $sensitive, string $mask = '******')
    {
        return in_array($col, $sensitive, true) ? $mask : $value;
    }

    // ===== N+1 enrichment helpers =====

    private static function labelNPlusOne(?array $group): ?string
    {
        if (! is_array($group)) {
            return null;
        }

        $count = (int) ($group['count'] ?? 0);
        $distinct = (int) ($group['distinct_bindings_count'] ?? 0);

        // Heuristics:
        // - distinct bindings tinggi dibanding count => "paramized_n_plus_one" (true N+1 typical)
        // - distinct bindings rendah => "repeated_same_query" (loop same query / polling / caching miss)
        if ($count <= 0) {
            return null;
        }

        if ($distinct >= max(2, (int) floor($count * 0.6))) {
            return 'paramized_n_plus_one';
        }

        if ($distinct <= 1 && $count >= 2) {
            return 'repeated_same_query';
        }

        return 'repeated_query';
    }

    /**
     * Create a stable signature for bindings (do NOT store values in clear).
     * - json encode (best effort), then hash
     * - cap large binding payload before hashing
     */
    private static function bindingsSignature(array $bindings): ?string
    {
        if (empty($bindings)) {
            return 'empty';
        }

        // best-effort normalize scalars
        $norm = [];
        $max = 25; // cap to avoid huge memory when bindings contain large arrays
        $i = 0;

        foreach ($bindings as $b) {
            if ($i++ >= $max) {
                $norm[] = '__truncated__';
                break;
            }

            if (is_null($b)) {
                $norm[] = null;
            } elseif (is_bool($b) || is_int($b) || is_float($b) || is_string($b)) {
                // cap string length
                $norm[] = is_string($b) ? self::capString($b, 120) : $b;
            } else {
                // objects/resources -> type only
                $norm[] = is_object($b) ? ('obj:'.get_class($b)) : gettype($b);
            }
        }

        $json = json_encode($norm);
        if (! is_string($json)) {
            return null;
        }

        return md5($json);
    }

    // ===== hard caps helpers =====

    private static function capString(string $s, int $maxLen): string
    {
        if ($maxLen <= 0) {
            return '';
        }

        if (strlen($s) <= $maxLen) {
            return $s;
        }

        return substr($s, 0, max(0, $maxLen - 1)).'…';
    }

    /**
     * Cap associative array by number of keys; preserves first N keys.
     *
     * @param  array<mixed,mixed>  $arr
     * @return array<mixed,mixed>
     */
    private static function capArray(array $arr, int $maxKeys): array
    {
        if ($maxKeys <= 0) {
            return [];
        }
        if (count($arr) <= $maxKeys) {
            return $arr;
        }

        $out = [];
        $i = 0;
        foreach ($arr as $k => $v) {
            if ($i++ >= $maxKeys) {
                $out['__truncated__'] = true;
                break;
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
