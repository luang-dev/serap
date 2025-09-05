<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\Jobs\LogWriterJob;
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

        $duration = $start ? round((microtime(as_float: true) - $start) * 1000, 2) : null;
        $type = SerapUtils::detectResponseType($response);

        $context = [
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'type' => $type,
            'memory' => SerapUtils::getMemoryUsage(),
            'headers' => SerapUtils::mask($response->headers->all()),
            'response' => SerapUtils::safeContent($response->getContent(), $type),
        ];

        dispatch(job: new LogWriterJob(eventName: 'response', context: $context));

        $queries = Context::get('serap_queries', []);

        if (! empty($queries)) {
            // foreach ($queries as $query) {
            //     dispatch(job: new LogWriterJob(eventName: 'query', context: $query));
            // }
            dispatch(job: new LogWriterJob(eventName: 'query', context: $queries));
        }
    }
}
