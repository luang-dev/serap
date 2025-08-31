<?php

namespace LuangDev\Serap\Watchers;

class WatcherManager
{
    public static function register(): void
    {
        QueryWatcher::handle();
        ExceptionWatcher::handle();
    }
}
