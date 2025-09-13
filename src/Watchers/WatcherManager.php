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
        ExceptionWatcher::handle();
        RequestResponseManager::register();
    }
}
