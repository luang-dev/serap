<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\SerapUtils;

class RequestWatcher
{
    /**
     * Handle the route matched event.
     * This event is triggered after a route has been matched by the application.
     * It will store the request context in the context for later use.
     */
    public function handle(RouteMatched $event): void
    {
        $request = $event->request;

        $traceId = SerapUtils::getTraceId();

        $request->attributes->set('serap_trace_id', $traceId);
        $request->attributes->set('serap_start_time', microtime(true));

        $rawRequestPayload = $request->all();
        $requestPayloadSize = SerapUtils::getPayloadSizeBytes(
            is_string($rawRequestPayload) ? $rawRequestPayload : null,
            $request->headers->get('content-length')
        );

        $context = [
            'time' => now()->toISOString(),
            'uri' => str_replace($request->root(), '', $request->fullUrl()) ?: '/',
            'method' => $request->method(),
            'controller_action' => $event->route?->getActionName(),
            'middleware' => array_values($event->route?->gatherMiddleware() ?? []),
            'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
            'memory' => SerapUtils::getMemoryUsage(),
            'headers' => SerapUtils::mask($request->headers->all()),
            'payload' => SerapUtils::mask($request->all()),
            'payload_type' => $request->isJson() ? 'json' : 'form',
            'payload_size_bytes' => $requestPayloadSize,
        ];

        Context::add('serap_request_context', $context);
    }
}
