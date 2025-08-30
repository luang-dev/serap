<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Application;

class WatcherManager
{
    public static function register()
    {
        QueryWatcher::handle();
        ExceptionWatcher::handle();
    }
}
