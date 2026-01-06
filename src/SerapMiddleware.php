<?php

namespace LuangDev\Serap;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Serap;
use Symfony\Component\HttpFoundation\Response;

/**
 * SerapMiddleware (clean version)
 *
 * Requirements:
 * - Serap class punya method: mergeTransaction(array $patch): void
 *   (agar update extra/request/response tidak me-reset anchor timing)
 *
 * Flow:
 * 1) generate trace id + propagate
 * 2) start transaction (setTransaction) sekali
 * 3) mark middleware start/end/terminate (Serap::mark)
 * 4) update extra.request payload + payload_size via mergeTransaction
 * 5) update extra.response via mergeTransaction pada terminate
 * 6) finalizeTransaction -> menghitung duration_ms + attach marks
 */
class SerapMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1) Trace ID
        $traceId = Str::ulid()->toString();
        Context::add('serap_trace_id', $traceId);

        // 2) Propagate trace id ke request object (internal) & downstream (optional)
        $request->headers->set('X-Serap-Trace-Id', $traceId);

        // 3) Hitung payload size (minimal overhead)
        $rawRequestPayload = $request->all();
        $payloadSize = SerapUtils::getPayloadSizeBytes(
            is_string($rawRequestPayload) ? $rawRequestPayload : null,
            $request->headers->get('content-length')
        );

        // 4) Start transaction ONCE (anchor timing dibuat di sini)
        Serap::setTransaction([
            'trace_id' => $traceId,
            'parent_id' => null,
            'type' => 'request',
            'level' => 'info',
            'name' => $request->route()?->getName() ?? ($request->method() . ' ' . $request->path()),
            'user' => SerapUtils::getAuthUser(),
            'extra' => [
                'request' => [
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'url' => $request->url(),
                    'full_url' => $request->fullUrl(),
                    'scheme_and_http_host' => $request->schemeAndHttpHost(),
                    'is_secure' => $request->isSecure(),
                    'method' => $request->method(),
                    'controller_action' => $request->route()?->getActionName(),
                    'middleware' => array_values($request->route()?->gatherMiddleware() ?? []),
                    'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
                    'memory' => SerapUtils::getMemoryUsage(),
                    'params' => SerapUtils::mask($request->query->all()),
                    'headers' => SerapUtils::mask($request->headers->all()),
                    'payload_type' => $request->isJson() ? 'json' : 'form',
                    'payload_size' => $payloadSize,
                    // payload bisa dimatikan via config di production
                    'payload' => $this->shouldCaptureRequestBody($request)
                        ? SerapUtils::mask($request->all())
                        : [],
                    'payload_captured' => $this->shouldCaptureRequestBody($request),
                ],
                'response' => [],
            ],
        ]);

        // 5) Marks
        Serap::mark('middleware_handled');

        // 6) Run request
        try {
            $response = $next($request);
        } finally {
            Serap::mark('middleware_ended');
        }

        // 7) Propagate trace id ke response
        $response->headers->set('X-Serap-Trace-Id', $traceId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Serap::mark('middleware_terminated');

        // Update response extra (tanpa reset anchor)
        Serap::mergeTransaction([
            'extra' => [
                'response' => [
                    'status' => $response->getStatusCode(),
                    'headers' => SerapUtils::mask($response->headers->all()),
                    'memory' => SerapUtils::getMemoryUsage(),
                    'response_type' => $response->headers->get('content-type'),
                    'response_size' => SerapUtils::getPayloadSizeBytes(
                        null,
                        $response->headers->get('content-length')
                    ),
                    'body_captured' => false,
                    'truncated' => false,
                ],
            ],
        ]);

        // Finalize transaction: duration_ms + marks (+ optional mark_timestamps)
        Serap::finalizeTransaction();

        // Debug output sementara
        info('serap', [
            'serap' => [
                'metadata' => Serap::getMetadata(),
                'transaction' => Serap::getTransaction(),
                'spans' => Serap::getSpans(),
                'exceptions' => Serap::getExceptions(),
            ],
        ]);

        Serap::reset();
    }

    private function shouldCaptureRequestBody(Request $request): bool
    {
        // Default aman untuk production: OFF
        // Kamu bisa buat config serap.capture.request_body = off|errors|transactions
        $mode = (string) config('serap.capture.request_body', 'off');

        if ($mode === 'off') {
            return false;
        }

        // contoh sederhana: jangan capture file upload / payload besar
        if ($request->isJson() && strlen((string) $request->getContent()) > 64_000) {
            return false;
        }

        // mode 'transactions' => capture selalu (hanya untuk debug/dev)
        if ($mode === 'transactions') {
            return true;
        }

        // mode 'errors' => capture nanti kalau error (butuh mekanisme di exception handler)
        return false;
    }
}
