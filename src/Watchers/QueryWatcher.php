<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use LuangDev\Serap\Facades\Serap;

final class QueryWatcher
{
    public function handle(QueryExecuted $event): void
    {
        if ($this->shouldSkipQuery($event->sql)) {
            return;
        }

        $durationMs = (float) $event->time;

        $isSlow = $durationMs >= 1000.0;

        $bindingsMode = (string) config('serap.capture.sql_bindings', 'off'); // off|local_only|on
        $bindings = $event->bindings ?? [];

        $maskedBindings = [];
        if ($bindingsMode === 'on' || ($bindingsMode === 'local_only' && app()->environment('local'))) {
            $maskedBindings = self::mapBindingsWithColumns($event->sql, $bindings);
        }

        Serap::addSpan([
            'type' => 'db',
            'name' => $this->shortName($event->sql),
            'level' => $isSlow ? 'warning' : 'info',
            'duration_ms' => $durationMs,
            'sampled' => $isSlow ? true : null,

            'outcome' => $isSlow ? 'failure' : 'unknown',

            'context' => [
                'driver' => $event->connection->getDriverName(),
                'connection' => $event->connectionName,
                'database' => $this->getDbName($event),
                'operation' => $this->getQueryType($event->sql),
                'statement' => $event->sql,
                'bindings' => $maskedBindings,

                // helpful UI flags
                'slow' => $isSlow,
                'threshold_ms' => 1000,
            ],
        ]);
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
        return $op !== 'UNKNOWN' ? $op . ' query' : 'DB query';
    }

    protected function shouldSkipQuery(string $sql): bool
    {
        $skipTables = ['jobs', 'failed_jobs', 'cache', 'sessions'];

        foreach ($skipTables as $table) {
            if (
                stripos($sql, '"' . $table . '"') !== false ||
                stripos($sql, '`' . $table . '`') !== false ||
                stripos($sql, ' ' . $table . ' ') !== false
            ) {
                return true;
            }
        }

        return false;
    }

    // ===== binding helpers (unchanged) =====

    public static function mapBindingsWithColumns(string $sql, array $bindings): array
    {
        $mapped = [];
        $bindingIndex = 0;

        $sensitive = array_map(
            fn ($k) => self::normalizeKey($k),
            config('serap.sanitize.field_deny', ['password', 'token', 'secret'])
        );

        if (preg_match_all('/([`\w\.\"]+)\s*(=|<|>|<=|>=|LIKE|BETWEEN|IN)\s*(\?|[\(])/i', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $col = self::normalizeKey($match[1]);
                $op = strtoupper($match[2]);

                if ($op === 'BETWEEN') {
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col . '_from'] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col . '_to'] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                } elseif ($op === 'IN') {
                    if (preg_match('/\bIN\s*\(([^)]+)\)/i', $match[0], $inMatch)) {
                        $placeholders = substr_count($inMatch[1], '?');
                        $values = [];
                        for ($i = 0; $i < $placeholders; $i++) {
                            if (isset($bindings[$bindingIndex])) {
                                $values[] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                            }
                        }
                        $mapped[$col] = $values;
                    }
                } else {
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                }
            }
        }

        if (preg_match_all('/SET\s+[`"]?(\w+)[`"]?\s*=\s*\?/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                $col = self::normalizeKey($col);
                if (isset($bindings[$bindingIndex])) {
                    $mapped[$col] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                }
            }
        }

        if (preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
            $cols = array_map(
                fn ($c) => self::normalizeKey(str_replace(['`', '"'], '', $c)),
                explode(',', $m[1])
            );

            foreach ($cols as $col) {
                if (isset($bindings[$bindingIndex])) {
                    $mapped[$col] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                }
            }
        }

        return $mapped;
    }

    protected static function normalizeKey(string $key): string
    {
        $key = trim($key, '`" ');
        $key = strtolower($key);
        return str_replace('-', '_', $key);
    }

    protected static function maskIfSensitive(string $col, $value, array $sensitive, string $mask = '******')
    {
        return in_array($col, $sensitive, true) ? $mask : $value;
    }
}
