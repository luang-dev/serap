<?php

namespace LuangDev\Serap\Watchers;

use Illuminate\Foundation\Http\Events\RequestHandled;
use LuangDev\Serap\Facades\Serap;
use LuangDev\Serap\SerapUtils;

final class ResponseWatcher
{
    public function handle(RequestHandled $event): void
    {
        $response = $event->response;

        // mark timing (adds marks + optional mark_timestamps)
        Serap::mark('request_handled');

        $raw = $response->getContent();
        $type = SerapUtils::detectResponseType($response);

        $responseSize = SerapUtils::getPayloadSizeBytes(
            is_string($raw) ? $raw : '',
            $response->headers->get('Content-Length')
        );

        // capture policy
        $mode = (string) config('serap.capture.response_body', 'off'); // off|errors|transactions
        $shouldCaptureBody = $mode === 'transactions'
            || ($mode === 'errors' && $response->getStatusCode() >= 500);

        $safe = ['data' => [], 'is_truncated' => true];
        if ($shouldCaptureBody) {
            $safe = SerapUtils::safeContent(is_string($raw) ? $raw : '', $type);
        }

        // merge into unified event: transaction.context.response
        Serap::mergeTransaction([
            'context' => [
                'response' => [
                    'headers' => Serap::sanitizeResponseHeaders($response->headers->all()),
                    'status' => $response->getStatusCode(),
                    'memory' => SerapUtils::getMemoryUsage(),
                    'response_type' => $type,
                    'response_size' => $responseSize,
                    'body_captured' => $shouldCaptureBody,
                    'content' => $shouldCaptureBody ? ($safe['data'] ?? []) : [],
                    'is_truncated' => $shouldCaptureBody ? (bool) ($safe['is_truncated'] ?? false) : true,
                ],
            ],
        ]);

        if ($response->getStatusCode() >= 500) {
            Serap::mergeTransaction(['sampled' => true, 'outcome' => 'failure', 'level' => 'error']);
        }

        // finalize duration + attach marks into transaction.context
        // QueryWatcher::flush();
        // Serap::finalizeTransaction();
    }
}
