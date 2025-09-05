<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;

class RequestResponseManager
{
    public static function handle()
    {
        Event::listen(RouteMatched::class, RequestWatcher::class);
        Event::listen(RequestHandled::class, ResponseWatcher::class);
    }
}
