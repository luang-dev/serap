<?php

namespace LuangDev\Serap\Ingest;

use SplFileObject;

class FileIngestQueueDriver implements IngestQueueDriver
{
    public function __construct(protected string $path)
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    public function push(array $log): void
    {
        $this->pushBatch([$log]);
    }

    public function pushBatch(array $logs): void
    {
        $file = new SplFileObject($this->path, 'a+');

        if ($file->flock(LOCK_EX)) {
            foreach ($logs as $log) {
                $file->fwrite(json_encode($log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            }
            $file->flock(LOCK_UN);
        }
    }

    public function pullBatch(int $batchSize): array
    {
        if ($batchSize <= 0 || ! file_exists($this->path)) {
            return [];
        }

        $file = new SplFileObject($this->path, 'c+');
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
}
