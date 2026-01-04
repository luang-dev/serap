<?php

namespace LuangDev\Serap;

use Illuminate\Support\Str;
use LuangDev\Serap\Facades\Clock;

class Serap
{
    public array $spans = [];

    public array $exceptions = [];

    public array $transaction = [];

    public string $appBoot;

    public string $appRegistered;

    public string $routeMatched;

    public string $requestHandled;

    public string $middlewareHandled;

    public string $middlewareEnded;

    public string $middlewareTerminated;

    public string $appTerminated;

    public function getMetadata()
    {
        return [
            'env' => app()->environment(),
            'app_name' => config('app.name'),
            'hostname' => gethostname(),
            'os' => php_uname(),
            'ip' => request()->server('SERVER_ADDR') ?? request()->server('REMOTE_ADDR'),
            'port' => request()->server('SERVER_PORT') ?? request()->server('REMOTE_PORT'),
            'server_name' => request()->server('SERVER_NAME'),
            'http_host' => request()->server('HTTP_HOST'),
            'memory_usage' => SerapUtils::getMemoryUsage(),
            'timestamps' => [
                'timestamp' => Clock::nowIso8601(),
                'laravel_start' => defined('LARAVEL_START') ? LARAVEL_START : request()->server('REQUEST_TIME_FLOAT'),
                'app_booted' => $this->appBoot,
                'app_registered' => $this->appRegistered,
                'route_matched' => $this->routeMatched,
                'request_handled' => $this->requestHandled,
                'middleware_handled' => $this->middlewareHandled,
                'middleware_ended' => $this->middlewareEnded,
                'middleware_terminated' => $this->middlewareTerminated,
                'app_terminated' => $this->appTerminated,
            ],
            'versions' => [
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'serap' => '0.1',
                'schema' => '0.1',
            ],
            'user' => SerapUtils::getAuthUser(),
        ];
    }

    public function setTimestamp(string $type, string $timestamp)
    {
        match ($type) {
            'app_booted' => $this->appBoot = $timestamp,
            'app_registered' => $this->appRegistered = $timestamp,
            'route_matched' => $this->routeMatched = $timestamp,
            'request_handled' => $this->requestHandled = $timestamp,
            'middleware_handled' => $this->middlewareHandled = $timestamp,
            'middleware_ended' => $this->middlewareEnded = $timestamp,
            'middleware_terminated' => $this->middlewareTerminated = $timestamp,
            'app_terminated' => $this->appTerminated = $timestamp,
        };
    }

    public function addSpan(array $span)
    {
        $transaction = $this->getTransaction();

        $this->spans[] = [
            'time_ns' => Clock::monotonicNs(),
            'timestamp' => Clock::nowIso8601(),
            'span_id' => Str::ulid()->toString(),
            'parent_id' => $transaction['transaction_id'] ?? $transaction['span_id'] ?? null,
            ...$span
        ];
    }

    public function setSpans(array $spans)
    {
        $this->spans = $spans;
    }

    public function setExceptions(array $exceptions)
    {
        $this->exceptions = $exceptions;
    }

    public function setTransaction(array $transaction)
    {
        $this->transaction = [
            'time_ns' => Clock::monotonicNs(),
            'timestamp' => Clock::nowIso8601(),
            'transaction_id' => Str::ulid()->toString(),
            'trace_id' => SerapUtils::getTraceId(),
            ...$transaction
        ];
    }

    public function getSpans()
    {
        return $this->spans;
    }

    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function getTransaction()
    {
        return $this->transaction;
    }
}
