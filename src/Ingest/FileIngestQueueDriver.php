<?php

namespace LuangDev\Serap\Ingest;

use Carbon\CarbonImmutable;
use SplFileObject;

class FileIngestQueueDriver implements IngestQueueDriver
{
    public function __construct(protected string $path, protected array $priorities)
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function push(array $log, ?string $priority = null): void
    {
        $this->pushBatch([$log], $priority);
    }

    public function pushBatch(array $logs, ?string $priority = null): void
    {
        $grouped = [];

        foreach ($logs as $log) {
            $priorityKey = $priority ?? ($log['priority'] ?? 'normal');
            $grouped[$priorityKey][] = $log;
        }

        foreach ($grouped as $priorityKey => $payloads) {
            $targetPath = $this->pathForPriority($priorityKey);
            $file = new SplFileObject($targetPath, 'a+');

            if ($file->flock(LOCK_EX)) {
                foreach ($payloads as $log) {
                    $file->fwrite(json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                }
                $file->flock(LOCK_UN);
            }
        }
    }

    public function pullBatch(int $batchSize): array
    {
        if ($batchSize <= 0) {
            return [];
        }

        $batch = [];

        foreach ($this->priorities as $priority) {
            $remainingCount = $batchSize - count($batch);
            if ($remainingCount <= 0) {
                break;
            }

            $segment = $this->drainFile($this->pathForPriority($priority), $remainingCount);
            $batch = array_merge($batch, $segment);
        }

        return $batch;
    }

    public function pruneOlderThan(CarbonImmutable $cutoff): void
    {
        foreach ($this->priorities as $priority) {
            $this->pruneFile($this->pathForPriority($priority), $cutoff);
        }
    }

    protected function pathForPriority(string $priority): string
    {
        $normalized = trim($priority) ?: 'normal';

        if ($normalized === 'normal') {
            return $this->path;
        }

        $extension = pathinfo($this->path, PATHINFO_EXTENSION);
        $base = substr($this->path, 0, strlen($this->path) - strlen($extension) - 1);

        return $base.'.'.$normalized.'.'.$extension;
    }

    protected function drainFile(string $path, int $batchSize): array
    {
        if ($batchSize <= 0 || ! file_exists($path)) {
            return [];
        }

        $file = new SplFileObject($path, 'c+');
        $file->flock(LOCK_EX);
        $file->rewind();

        $batch = [];
        $count = 0;

        while (! $file->eof() && $count < $batchSize) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $batch[] = $decoded;
            }
            $count++;
        }

        $remaining = [];
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                $remaining[] = $line;
            }
        }

        $file->ftruncate(0);
        $file->rewind();

        if (! empty($remaining)) {
            $file->fwrite(implode(PHP_EOL, $remaining).PHP_EOL);
        }

        $file->flock(LOCK_UN);

        return $batch;
    }

    protected function pruneFile(string $path, CarbonImmutable $cutoff): void
    {
        if (! file_exists($path)) {
            return;
        }

        $file = new SplFileObject($path, 'c+');
        $file->flock(LOCK_EX);
        $file->rewind();

        $freshLines = [];

        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }

            $time = $decoded['time'] ?? $decoded['occurred_at'] ?? null;
            $parsedTime = $time ? CarbonImmutable::make($time) : null;
            if ($parsedTime && $parsedTime->lessThan($cutoff)) {
                continue;
            }

            $freshLines[] = $line;
        }

        $file->ftruncate(0);
        $file->rewind();

        if (! empty($freshLines)) {
            $file->fwrite(implode(PHP_EOL, $freshLines).PHP_EOL);
        }

        $file->flock(LOCK_UN);
    }
}
