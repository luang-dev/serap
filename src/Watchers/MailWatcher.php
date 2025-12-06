<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\SerapUtils;

class MailWatcher
{
    public static function handle(): void
    {
        if (class_exists(MessageSending::class)) {
            Event::listen(MessageSending::class, function (MessageSending $event): void {
                self::log(status: 'sending', event: $event);
            });
        }

        if (class_exists(MessageSent::class)) {
            Event::listen(MessageSent::class, function (MessageSent $event): void {
                self::log(status: 'sent', event: $event);
            });
        }

        $messageFailedEvent = 'Illuminate\\Mail\\Events\\MessageFailed';

        if (class_exists($messageFailedEvent)) {
            Event::listen($messageFailedEvent, function (object $event): void {
                self::log(status: 'failed', event: $event);
            });
        }
    }

    protected static function log(string $status, object $event): void
    {
        $message = $event->message ?? null;

        $context = [
            'status' => $status,
            'subject' => method_exists($message, 'getSubject') ? $message->getSubject() : null,
            'to' => self::formatAddresses($message?->getTo()),
            'cc' => self::formatAddresses($message?->getCc()),
            'bcc' => self::formatAddresses($message?->getBcc()),
            'time' => now()->toISOString(),
        ];

        if ($status === 'failed' && isset($event->exception)) {
            $context['exception'] = [
                'message' => $event->exception->getMessage(),
                'class' => get_class($event->exception),
            ];
        }

        $level = $status === 'failed' ? 'error' : 'info';

        SerapUtils::writeJsonl(
            event: 'mail',
            context: $context,
            auth: null,
            level: $level,
        );
    }

    /**
     * Format Symfony or Swift mail addresses into strings.
     */
    protected static function formatAddresses(?array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        return array_values(array_map(function ($address) {
            if (is_string($address)) {
                return $address;
            }

            if (is_object($address) && method_exists($address, '__toString')) {
                return (string) $address;
            }

            if (is_object($address) && method_exists($address, 'getAddress')) {
                $name = method_exists($address, 'getName') ? $address->getName() : null;
                $email = $address->getAddress();

                return $name ? $name." <{$email}>" : $email;
            }

            return (string) json_encode($address);
        }, $addresses));
    }
}
