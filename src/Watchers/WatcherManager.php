<?php

namespace Zzzul\Gol\Watchers;

use Illuminate\Foundation\Application;

class WatcherManager
{
    public static function register(Application $app)
    {
        QueryWatcher::handle();
        ExceptionWatcher::handle($app);
    }
}