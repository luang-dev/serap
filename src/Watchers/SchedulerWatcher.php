<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\SerapUtils;

class SchedulerWatcher
{
    public static function handle(): void
    {
        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            self::log(status: 'starting', event: $event);
        });

        Event::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event): void {
            self::log(status: 'finished', event: $event);
        });

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            self::log(status: 'failed', event: $event);
        });

        Event::listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event): void {
            self::log(status: 'finished_background', event: $event);
        });
    }

    protected static function log(
        string $status,
        ScheduledTaskStarting|ScheduledTaskFinished|ScheduledTaskFailed|ScheduledBackgroundTaskFinished $event
    ): void {
        $task = $event->task;

        $context = [
            'status' => $status,
            'description' => $task?->description ?? null,
            'expression' => $task?->expression ?? null,
            'command' => $task?->command ?? null,
            'time' => now()->toISOString(),
        ];

        if (property_exists($event, 'runtime')) {
            $context['runtime'] = $event->runtime;
        }

        if (property_exists($event, 'exitCode')) {
            $context['exit_code'] = $event->exitCode;
        }

        if ($event instanceof ScheduledTaskFailed && $event->exception) {
            $context['exception'] = [
                'message' => $event->exception->getMessage(),
                'class' => get_class($event->exception),
            ];
        }

        $level = $event instanceof ScheduledTaskFailed ? 'error' : 'info';

        SerapUtils::writeJsonl(
            event: 'schedule',
            context: $context,
            auth: null,
            level: $level,
        );
    }
}
