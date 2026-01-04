<?php

namespace LuangDev\Serap;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Serap;
use LuangDev\Serap\Facades\Clock;
use Symfony\Component\HttpFoundation\Response;

class SerapMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = Str::ulid()->toString();

        Context::add("serap_trace_id", $traceId);

        $request->headers->set("X-Serap-Trace-Id", $traceId);

        Serap::setTimestamp("middleware_handled", Clock::nowIso8601());

        $rawRequestPayload = $request->all();
        $payloadSize = SerapUtils::getPayloadSizeBytes(is_string($rawRequestPayload) ? $rawRequestPayload : null, $request->headers->get("content-length"));

        Serap::setTransaction([
            "trace_id" => $traceId,
            "parent_id" => null,
            "type" => "request",
            "level" => "info",
            "name" => $request->route()?->getName() ?? $request->path(),
            'user' => SerapUtils::getAuthUser(),
            "extra" => [
                "request" => [
                    "user_agent" => $request->userAgent(),
                    "ip" => $request->ip(),
                    "path" => $request->path(),
                    "url" => $request->url(),
                    "full_url" => $request->fullUrl(),
                    "scheme_and_http_host" => $request->schemeAndHttpHost(),
                    "is_secure" => $request->isSecure(),
                    "method" => $request->method(),
                    "controller_action" => $request->route?->getActionName(),
                    "middleware" => array_values($request?->route?->gatherMiddleware() ?? []),
                    "session" => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
                    "memory" => SerapUtils::getMemoryUsage(),
                    "params" => SerapUtils::mask($request->query->all()),
                    "headers" => SerapUtils::mask($request->headers->all()),
                    "payload" => SerapUtils::mask($request->all()),
                    "payload_type" => $request->isJson() ? "json" : "form",
                    "payload_size" => $payloadSize,
                ],
                "response" => [],
            ],
        ]);

        $response = $next($request);

        Serap::setTimestamp("middleware_ended", Clock::nowIso8601());

        return $response;
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     */
    public function terminate(Request $request, Response $response): void
    {
        Serap::setTimestamp("middleware_terminated", Clock::nowIso8601());

        info('serap class', [
            'serap' => [
                'metadata' => Serap::getMetadata(),
                'transaction' => Serap::getTransaction(),
                'spans' => Serap::getSpans(),
                'exceptions' => Serap::getExceptions(),
            ]
        ]);
    }
}
