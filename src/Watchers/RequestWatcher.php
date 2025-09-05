<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\Jobs\LogWriterJob;
use LuangDev\Serap\SerapUtils;

class RequestWatcher
{
    public function handle(RouteMatched $event): void
    {
        $request = $event->request;

        $traceId = SerapUtils::generateTraceId();
        Context::add('serap_trace_id', $traceId);

        $request->attributes->set('serap_trace_id', $traceId);
        $request->attributes->set('serap_start_time', microtime(as_float: true));

        $context = [
            'uri' => str_replace($request->root(), '', $request->fullUrl()) ?: '/',
            'method' => $request->method(),
            'controller_action' => $event->route?->getActionName(),
            'middleware' => array_values($event->route?->gatherMiddleware() ?? []),
            'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
            'memory' => SerapUtils::getMemoryUsage(),
            'user' => Auth::user(),
            'params' => SerapUtils::mask($request->query->all()),
            'headers' => SerapUtils::mask($request->headers->all()),
            'payload' => SerapUtils::mask($request->all()),
        ];

        dispatch(job: new LogWriterJob(eventName: 'request', context: $context));
    }
}
