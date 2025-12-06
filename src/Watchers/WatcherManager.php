<?php

namespace LuangDev\Serap\Watchers;

class WatcherManager
{
    /**
     * Register all watchers.
     */
    public static function register(): void
    {
        QueryWatcher::handle();
        // ExceptionWatcher::handle();
        // RequestResponseManager::register();
        // JobWatcher::handle();
        CommandWatcher::handle();
        // SchedulerWatcher::handle();
        // MailWatcher::handle();
        // NotificationWatcher::handle();
    }
}
