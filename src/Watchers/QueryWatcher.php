<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class QueryWatcher
{
    public static function handle(): void
    {
        DB::listen(callback: function (QueryExecuted $query): void {
            if (self::shouldSkipQuery($query->sql)) {
                return;
            }

            $bindings = $query->bindings ?? [];
            $maskedBindings = self::mapBindingsWithColumns(sql: $query->sql, bindings: $bindings);

            $context = [
                'sql' => $query->sql,
                'bindings' => $maskedBindings,
                'duration' => $query->time,
                'connection' => $query?->connectionName,
            ];

            $queries = Context::get('serap_queries', []);
            $queries[] = $context;

            Context::add('serap_queries', $queries);
        });
    }

    protected static function shouldSkipQuery(string $sql): bool
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

    public static function mapBindingsWithColumns(string $sql, array $bindings): array
    {
        $mapped = [];
        $bindingIndex = 0;

        $sensitive = array_map(fn ($k) => self::normalizeKey($k), config('gol.sensitive_keys', ['password']));

        // WHERE col = ? / col > ? / etc
        if (preg_match_all('/([`\w\.\"]+)\s*(=|<|>|<=|>=|LIKE|BETWEEN|IN)\s*(\?|[\(])/i', $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $col = self::normalizeKey($match[1]);
                $op = strtoupper($match[2]);

                if ($op === 'BETWEEN') {
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col.'_from'] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                    if (isset($bindings[$bindingIndex])) {
                        $mapped[$col.'_to'] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                    }
                } elseif ($op === 'IN') {
                    // hitung jumlah ? dalam IN (...)
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

        // UPDATE SET col = ?
        if (preg_match_all('/SET\s+[`"]?(\w+)[`"]?\s*=\s*\?/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                $col = self::normalizeKey($col);
                if (isset($bindings[$bindingIndex])) {
                    $mapped[$col] = self::maskIfSensitive($col, $bindings[$bindingIndex++], $sensitive);
                }
            }
        }

        // INSERT INTO (col1, col2, ...) VALUES (?, ?, ...)
        if (preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql, $m)) {
            $cols = array_map(fn ($c) => self::normalizeKey(str_replace(['`', '"'], '', $c)), explode(',', $m[1]));
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

    public static function extractAllInValues(string $sql)
    {
        // TODO: handle IN query
        // $result = [];

        // preg_match_all('/([\w\.]+)\s+in\s*\(([^)]+)\)/i', $sql, $matches, PREG_SET_ORDER);

        // foreach ($matches as $match) {
        //     $field = $match[1];
        //     $rawValues = $match[2];

        //     $values = array_map(function ($v) {
        //         $v = trim($v, " \t\n\r\0\x0B'\""); // trim spasi dan kutip

        //         return is_numeric($v) ? (int) $v : $v;
        //     }, explode(',', $rawValues));

        //     if (str_contains($field, '.')) {
        //         $field = substr($field, strrpos($field, '.') + 1);
        //     }

        //     $result[$field] = $values;
        // }

        // return $result;
    }
}
