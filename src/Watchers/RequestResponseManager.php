<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

class RequestResponseManager
{
    /**
     * Register all watchers.
     *
     * This function will register all watchers to listen to the respective events.
     */
    public static function register()
    {
        Event::listen(RouteMatched::class, RequestWatcher::class);
        Event::listen(RequestHandled::class, ResponseWatcher::class);
    }
}
