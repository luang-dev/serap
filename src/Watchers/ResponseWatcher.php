<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\Jobs\LogWriterJob;
use LuangDev\Serap\SerapUtils;

class ResponseWatcher
{
    /**
     * Handle the request handled event.
     *
     * This event is triggered after a request has been handled by the application.
     * It will write the request and response logs to a file and dispatch a job to write the logs to Serap.
     */
    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        $traceId = $request->attributes->get('serap_trace_id');
        $start = $request->attributes->get('serap_start_time');

        $rawRequestPayload = $request->all();
        $requestPayloadSize = SerapUtils::getPayloadSizeBytes(is_string($rawRequestPayload) ? $rawRequestPayload : null, $request->headers->get('content-length'));

        $rawResponseContent = $response->getContent();
        $responseContent = is_string($rawResponseContent) ? $rawResponseContent : '';
        $responseSizeBytes = SerapUtils::getPayloadSizeBytes($responseContent, $response->headers->get('Content-Length'));
        $type = SerapUtils::detectResponseType($response);
        $getResponseContent = SerapUtils::safeContent($responseContent, $type);

        if ($traceId) {
            $response->headers->set('Serap-Trace-Id', (string) $traceId);
        }

        $duration = $start ? round((microtime(true) - $start) * 1000, 2) : null;

        $logs = [];

        // request
        $requestCtx = Context::get('serap_request_context');
        if (! empty($requestCtx)) {
            $logs[] = [
                'event' => 'request',
                'level' => self::setLevelStatusCode($response->getStatusCode()),
                'auth' => SerapUtils::getAuthUser(),
                'sequence' => SerapUtils::nextSequence(),
                'context' => [
                    ...$requestCtx,
                    'response_size_bytes' => $responseSizeBytes,
                    'is_truncated' => $getResponseContent['is_truncated'],
                    'duration_ms' => $duration,
                    'response_type' => $type,
                    'status' => $response->getStatusCode(),
                ],
            ];
        } else {
            // RouteMatched event not triggered because something went wrong in routing/bootstrap
            $logs[] = [
                'event' => 'request',
                'level' => self::setLevelStatusCode($response->getStatusCode()),
                'auth' => SerapUtils::getAuthUser(),
                'sequence' => SerapUtils::nextSequence(),
                'context' => [
                    'uri' => str_replace($request->root(), '', $request->fullUrl()) ?: '/',
                    'method' => $request->method(),
                    'controller_action' => $request->route?->getActionName(),
                    'middleware' => array_values($request?->route?->gatherMiddleware() ?? []),
                    'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
                    'memory' => SerapUtils::getMemoryUsage(),
                    'headers' => SerapUtils::mask($request->headers->all()),
                    'payload' => SerapUtils::mask($request->all()),
                    'status' => $response->getStatusCode(),
                    'payload_size_bytes' => $requestPayloadSize,
                    'response_size_bytes' => $responseSizeBytes,
                    'is_truncated' => $getResponseContent['is_truncated'],
                    'duration_ms' => $duration,
                    'type' => $type,
                ],
            ];
        }

        $exceptionsCtx = Context::get('serap_exceptions', []);
        if (! empty($exceptionsCtx)) {
            file_put_contents(storage_path('logs/serap-exceptions.log'), json_encode($exceptionsCtx), FILE_APPEND);
            if (count($exceptionsCtx) > 1) {
                // slice and only keep the last exception
                $exceptionsCtx = array_values(array_slice($exceptionsCtx, -1));
            }

            $logs[] = [
                'event' => 'exception',
                'level' => self::setLevelStatusCode($response->getStatusCode()),
                'auth' => SerapUtils::getAuthUser(),
                'sequence' => SerapUtils::nextSequence(),

                'context' => array_values($exceptionsCtx),
            ];
        }

        $queriesCtx = Context::get('serap_queries', []);
        if (! empty($queriesCtx)) {
            $logs[] = [
                'event' => 'query',
                'level' => self::setLevelStatusCode($response->getStatusCode()),
                'auth' => SerapUtils::getAuthUser(),
                'sequence' => SerapUtils::nextSequence(),
                'context' => $queriesCtx,
            ];
        }

        // response
        $logs[] = [
            'event' => 'response',
            'level' => self::setLevelStatusCode($response->getStatusCode()),
            'auth' => SerapUtils::getAuthUser(),
            'sequence' => SerapUtils::nextSequence(),
            'context' => [
                'level' => self::setLevelStatusCode($response->getStatusCode()),
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'type' => $type,
                'time' => now()->toISOString(),
                'memory' => SerapUtils::getMemoryUsage(),
                'headers' => SerapUtils::mask($response->headers->all()),
                'response_size_bytes' => $responseSizeBytes,
                'is_truncated' => $getResponseContent['is_truncated'],
                'response' => $getResponseContent['data'],
            ],
        ];

        if (! empty($logs)) {
            dispatch(new LogWriterJob($logs));
        }
    }

    /**
     * Return the log level given the status code.
     */
    public static function setLevelStatusCode(int $statusCode): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return 'info';
    }
}
