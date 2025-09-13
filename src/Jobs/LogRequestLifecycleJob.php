<?php

namespace LuangDev\Serap\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LuangDev\Serap\LogBuffer;

class LogRequestLifecycleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public array $logs
    ) {}

    public function handle(): void
    {
        foreach ($this->logs as $log) {
            LogBuffer::add($log);
        }

        LogBuffer::flush();
    }
}
