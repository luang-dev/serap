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
    public function __construct(public string $eventName, public array $context, public string $level = 'info')
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        SerapUtils::writeJsonl(eventName: $this->eventName, context: $this->context, level: $this->level);
    }
}
