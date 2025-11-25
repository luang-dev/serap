<?php

namespace LuangDev\Serap\Ingest;

interface IngestQueueDriver
{
    /**
     * Push a single log payload into the queue.
     */
    public function push(array $log): void;

    /**
     * Push multiple log payloads into the queue.
     */
    public function pushBatch(array $logs): void;

    /**
     * Retrieve up to the given batch size from the queue.
     */
    public function pullBatch(int $batchSize): array;
}
