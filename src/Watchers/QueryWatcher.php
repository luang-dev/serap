<?php

namespace Zzzul\Gol\Watchers;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Zzzil\Gol\Utils;
use Zzzul\Gol\Services\LogWriterService;

class QueryWatcher
{
    public static function handle(): void
    {
        DB::listen(callback: function (QueryExecuted $query): void {
            $bindings = $query->bindings ?? [];
            $maskedBindings = self::mapBindingsWithColumns(sql: $query->sql, bindings: $bindings);

            LogWriterService::write(eventName: 'query', data: [
                'sql' => $query->sql,
                'bindings' => $maskedBindings,
                'duration' => $query->time,
                'memory' => Utils::getMemoryUsage(),
                'connection' => $query->connection,
                'connection_name' => $query->connectionName,
            ]);
        });
    }

    public static function mapBindingsWithColumns(string $sql, array $bindings): array
    {
        $maskFields = array_map('strtolower', config('gol.sensitive_keys', default: []));
        $maskChar = config(key: 'gol.mask', default: '********');

        $columns = [];

        // Pattern INSERT: INSERT INTO table (col1, col2) values (?, ?)
        if (preg_match('/\(([^)]+)\)\s*values\s*\(/i', $sql, $m)) {
            $columns = array_map(function ($c) {
                return str_replace(['`', '"'], '', trim($c));
            }, explode(',', $m[1]));
        }

        // Pattern UPDATE: UPDATE table SET col1 = ?, col2 = ?, ...
        if (preg_match_all('/["`]?(\\w+)["`]?\s*=\s*\?/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                $columns[] = str_replace(['`', '"'], '', $col);
            }
        }

        // Pattern WHERE ... col = ?
        if (preg_match_all('/where\s+["`]?(\\w+)["`]?\s*=\s*\?/i', $sql, $m)) {
            foreach ($m[1] as $col) {
                $columns[] = str_replace(['`', '"'], '', $col);
            }
        }

        $mapped = [];
        foreach (array_values($bindings) as $i => $value) {
            $col = $columns[$i] ?? "unknown_{$i}";
            $colLower = strtolower($col);

            if (in_array($colLower, $maskFields, true)) {
                $mapped[$col] = $maskChar;
            } else {
                $mapped[$col] = $value;
            }
        }

        return $mapped;
    }
}
