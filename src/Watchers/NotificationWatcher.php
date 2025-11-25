<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\SerapUtils;

class NotificationWatcher
{
    public static function handle(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $event): void {
            self::log(status: 'sending', event: $event);
        });

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            self::log(status: 'sent', event: $event);
        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
            self::log(status: 'failed', event: $event);
        });
    }

    protected static function log(
        string $status,
        NotificationSending|NotificationSent|NotificationFailed $event
    ): void {
        $context = [
            'status' => $status,
            'channel' => $event->channel ?? null,
            'notification' => $event->notification ? get_class($event->notification) : null,
            'notifiable' => self::formatNotifiable($event->notifiable ?? null),
            'time' => now()->toISOString(),
        ];

        if (property_exists($event, 'response')) {
            $context['response'] = $event->response;
        }

        if ($event instanceof NotificationFailed && $event->exception) {
            $context['exception'] = [
                'message' => $event->exception->getMessage(),
                'class' => get_class($event->exception),
            ];
        }

        $level = $event instanceof NotificationFailed ? 'error' : 'info';

        SerapUtils::writeJsonl(
            event: 'notification',
            context: $context,
            auth: null,
            level: $level,
        );
    }

    protected static function formatNotifiable(mixed $notifiable): array
    {
        if (! $notifiable) {
            return [];
        }

        $data = [
            'class' => get_class($notifiable),
        ];

        if (method_exists($notifiable, 'getKey')) {
            $data['id'] = $notifiable->getKey();
        }

        if (isset($notifiable->email)) {
            $data['email'] = $notifiable->email;
        }

        if (isset($notifiable->phone)) {
            $data['phone'] = $notifiable->phone;
        }

        return $data;
    }
}
