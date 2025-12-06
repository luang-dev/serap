<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\SerapUtils;

class JobWatcher
{
    public static function handle(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            self::log(status: 'processing', event: $event);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            self::log(status: 'processed', event: $event);
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            self::log(status: 'failed', event: $event);
        });
    }

    protected static function log(string $status, JobProcessing|JobProcessed|JobFailed $event): void
    {
        $job = $event->job;

        $context = [
            'status' => $status,
            'name' => method_exists($job, 'resolveName') ? $job->resolveName() : null,
            'display_name' => method_exists($job, 'displayName') ? $job?->displayName() : null,
            'queue' => method_exists($job, 'getQueue') ? $job->getQueue() : null,
            'connection' => $event->connectionName ?? null,
            'attempts' => method_exists($job, 'attempts') ? $job->attempts() : null,
            'uuid' => method_exists($job, 'uuid') ? $job->uuid() : null,
            'time' => now()->toISOString(),
        ];

        if ($event instanceof JobFailed && $event->exception) {
            $context['exception'] = [
                'message' => $event->exception->getMessage(),
                'class' => get_class($event->exception),
            ];
        }

        $level = $event instanceof JobFailed ? 'error' : 'info';

        SerapUtils::writeJsonl(
            event: 'job',
            context: $context,
            auth: null,
            level: $level,
        );
    }
}
