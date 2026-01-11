<?php

namespace LuangDev\Serap;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Serap;
use LuangDev\Serap\Watchers\QueryWatcher;
use Symfony\Component\HttpFoundation\Response;

class SerapMiddleware
{
    public function __construct(
        private readonly Sampler $sampler,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 1) Trace ID
        $traceId = Str::ulid()->toString();
        Context::add('serap_trace_id', $traceId);

        // Head-based sampling decision (deterministic)
        // $sampled = $this->sampler->shouldSample($traceId);
        $sampled = true;
        Context::add('serap_sampled', $sampled);

        // propagate to downstream
        $request->headers->set('X-Serap-Trace-Id', $traceId);

        // 2) Request payload size (minimal overhead)
        $rawRequestPayload = $request->all();
        $payloadSize = SerapUtils::getPayloadSizeBytes(
            is_string($rawRequestPayload) ? $rawRequestPayload : null,
            $request->headers->get('content-length')
        );

        // 3) Start transaction ONCE
        Serap::setTransaction([
            'trace_id' => $traceId,
            'parent_id' => null,
            'type' => 'request',
            'level' => 'info',
            'name' => $request->route()?->getName() ?? ($request->method().' '.$request->path()),

            // sampling fields
            'sampled' => $sampled,
            'outcome' => 'unknown',

            'context' => [
                // IMPORTANT: user sekali saja idealnya di metadata,
                // tapi kalau kamu mau pointer: Serap class sudah set context.user_id otomatis.
                'request' => [
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'url' => $request->url(),
                    'full_url' => $request->fullUrl(),
                    'scheme_and_http_host' => $request->schemeAndHttpHost(),
                    'is_secure' => $request->isSecure(),

                    'controller_action' => $request->route()?->getActionName(),
                    'middleware' => array_values($request->route()?->gatherMiddleware() ?? []),

                    'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
                    'memory' => SerapUtils::getMemoryUsage(),
                    'params' => SerapUtils::mask($request->query->all()),

                    // request headers: mask saja (bukan sanitizeResponseHeaders)
                    'headers' => SerapUtils::mask($request->headers->all()),

                    'payload_type' => $request->isJson() ? 'json' : 'form',
                    'payload_size' => $payloadSize,
                    'payload_captured' => $this->shouldCaptureRequestBody($request, $sampled),
                    'payload' => $this->shouldCaptureRequestBody($request, $sampled)
                        ? SerapUtils::mask($request->all())
                        : [],
                ],

                'response' => [],
            ],
        ]);

        Serap::mark('middleware_handled');

        try {
            $response = $next($request);
        } finally {
            Serap::mark('middleware_ended');
        }

        $response->headers->set('X-Serap-Trace-Id', $traceId);

        // outcome hint (non-exception path)
        if ($response->getStatusCode() >= 500) {
            Serap::setOutcome('failure');
            // error 100%: override sampled jika 5xx
            Serap::mergeTransaction(['sampled' => true]);
            Context::add('serap_sampled', true);
        } else {
            Serap::setOutcome('success');
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        Serap::mark('middleware_terminated');

        QueryWatcher::flush();

        Serap::finalizeTransaction();

        app(JsonlExporter::class)->export();

        Serap::reset();
    }

    private function shouldCaptureRequestBody(Request $request, bool $sampled): bool
    {
        // off|errors|transactions
        $mode = (string) config('serap.capture.request_body', 'off');

        if ($mode === 'off') {
            return false;
        }

        // kalau tidak sampled, jangan capture body (hemat)
        if (! $sampled) {
            return false;
        }

        // jangan capture payload besar
        if ($request->isJson() && strlen((string) $request->getContent()) > 64_000) {
            return false;
        }

        if ($mode === 'transactions') {
            return true;
        }

        // mode 'errors': akan di-capture di exception handler (kalau kamu mau)
        return false;
    }
}
