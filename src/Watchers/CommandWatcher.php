<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\SerapUtils;

class CommandWatcher
{
    public static function handle(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            self::log(status: 'starting', event: $event);
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            self::log(status: 'finished', event: $event);

            $queries = Context::get('serap_queries', []);

            if (! empty($queries)) {
                SerapUtils::writeJsonl(
                    event: 'query',
                    context: $queries,
                    auth: null,
                    level: 'info'
                );
            }

            Context::add('serap_queries', []);
        });
    }

    protected static function log(string $status, CommandStarting|CommandFinished $event): void
    {
        if ($event->command != 'list') {
            $arguments = method_exists($event->input, 'getArguments') ? $event->input->getArguments() : [];
            $options = method_exists($event->input, 'getOptions') ? $event->input->getOptions() : [];

            $exitCode = property_exists($event, 'exitCode') ? $event->exitCode : null;

            $context = [
                'status' => $status,
                'command' => $event->command,
                'arguments' => SerapUtils::mask($arguments),
                'options' => SerapUtils::mask($options),
                'exit_code' => $exitCode,
                'time' => now()->toISOString(),
            ];

            $level = $exitCode !== null && $exitCode !== 0 ? 'warning' : 'info';

            SerapUtils::writeJsonl(
                event: 'artisan_command',
                context: $context,
                auth: null,
                level: $level,
            );
        }
    }
}
