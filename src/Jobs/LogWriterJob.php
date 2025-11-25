<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LuangDev\Serap\Ingest\IngestQueue;

class LogWriterJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $logs)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        IngestQueue::pushBatch($this->logs);
    }
}
