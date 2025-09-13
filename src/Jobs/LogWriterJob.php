<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LuangDev\Serap\SerapUtils;

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
        foreach ($this->logs as $log) {
            SerapUtils::writeJsonl($log['event'], $log['context'], $log['level']);
        }
    }
}
