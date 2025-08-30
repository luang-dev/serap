<?php

namespace LuangDev\Serap\Watchers;

class WatcherManager
{
    public static function register()
    {
        QueryWatcher::handle();
        ExceptionWatcher::handle();
    }
}
