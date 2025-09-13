<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\Jobs\LogRequestLifecycleJob;
use LuangDev\Serap\SerapUtils;

class ResponseWatcher
{
    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        $traceId = $request->attributes->get('serap_trace_id');
        $start = $request->attributes->get('serap_start_time');

        if ($traceId) {
            $response->headers->set('Serap-Trace-Id', (string) $traceId);
        }

        $duration = $start ? round((microtime(true) - $start) * 1000, 2) : null;
        $type = SerapUtils::detectResponseType($response);

        $logs = [];

        // request
        $requestCtx = Context::get('serap_request_context');
        if ($requestCtx) {
            $logs[] = [
                'trace_id' => $traceId,
                'event' => 'request',
                'level' => $response->getStatusCode() >= 500 ? 'error' : 'info',
                'time' => now()->toISOString(),
                'user' => SerapUtils::getAuthUser(),
                'context' => $requestCtx,
            ];
        }

        // response
        $logs[] = [
            'time' => now()->toISOString(),
            'trace_id' => $traceId,
            'event' => 'response',
            'level' => $response->getStatusCode() >= 500 ? 'error' : 'info',
            'user' => SerapUtils::getAuthUser(),
            'context' => [
                'level' => $response->getStatusCode() >= 500 ? 'error' : 'info',
                'duration_ms' => $duration,
                'type' => $type,
                'memory' => SerapUtils::getMemoryUsage(),
                'headers' => SerapUtils::mask($response->headers->all()),
                'response' => SerapUtils::safeContent($response->getContent(), $type),
            ]
        ];

        $queriesCtx = Context::get('serap_queries', []);
        if (!empty($queriesCtx)) {
            $logs[] = [
                'time' => now()->toISOString(),
                'trace_id' => $traceId,
                'event' => 'query',
                'level' => $response->getStatusCode() >= 500 ? 'error' : 'info',
                'user' => SerapUtils::getAuthUser(),
                'context' => $queriesCtx,
            ];
        }

        if (!empty($logs)) {
            dispatch(new LogRequestLifecycleJob($logs));
        }
    }
}
