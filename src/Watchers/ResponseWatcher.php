<?php

namespace LuangDev\Serap\Watchers;


use Illuminate\Foundation\Http\Events\RequestHandled;
use LuangDev\Serap\Facades\Serap;
use LuangDev\Serap\SerapUtils;
use LuangDev\Serap\Facades\Clock;

class ResponseWatcher
{
    public function handle(RequestHandled $event)
    {
        $response = $event->response;

        Serap::setTimestamp('request_handled', Clock::nowIso8601());

        $rawResponseContent = $response->getContent();
        $responseSize = SerapUtils::getPayloadSizeBytes(is_string($rawResponseContent) ? $rawResponseContent : "", $response->headers->get("Content-Length"));
        $type = SerapUtils::detectResponseType($response);
        $responseContent = SerapUtils::safeContent(is_string($rawResponseContent) ? $rawResponseContent : "", $type);

        $transaction = Serap::getTransaction();
        $transaction["extra"]["response"] = [
            "user_agent" => $response->headers->get("user-agent"),
            "headers" => $response->headers->all(),
            "status" => $response->getStatusCode(),
            "memory" => SerapUtils::getMemoryUsage(),
            "response_type" => $type,
            "response_size" => $responseSize,
            "content" => $responseContent["data"],
            "is_truncated" => $responseContent["is_truncated"],
        ];

        Serap::setTransaction($transaction);
    }
}
