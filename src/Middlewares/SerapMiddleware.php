<?php

namespace LuangDev\Serap\Middlewares;

use Closure;
use BackedEnum;
use Illuminate\View\View;
use LuangDev\Serap\SerapUtils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use LuangDev\Serap\Services\LogWriterService;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Response as IlluminateResponse;

class SerapMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(as_float: true);

        $traceId = SerapUtils::generateTraceId();
        Context::add(key: 'serao_trace_id', value: $traceId);

        LogWriterService::write(eventName: 'request', data: [
            'uri' => str_replace($request->root(), '', $request->fullUrl()) ?: '/',
            'method' => $request->method(),
            'controller_action' => $request->route()?->getActionName(),
            'middleware' => array_values($request->route()?->gatherMiddleware() ?? []),
            'session' => SerapUtils::mask($request->hasSession() ? $request->session()->all() : []),
            'memory' => SerapUtils::getMemoryUsage(),
            'user' => Auth::user(),
            'params' => SerapUtils::mask(data: $request->query->all()),
            'headers' => SerapUtils::mask(data: $request->headers->all()),
            'payload' => SerapUtils::mask(data: $request->all()),
        ]);

        $response = $next($request);

        $response->headers->set('X-Gol-Trace-Id', (string) $traceId);

        $duration = round(num: (microtime(as_float: true) - $start) * 1000, precision: 2);

        $type = SerapUtils::detectResponseType(response: $response);

        if ($response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View) {
            $views = [
                'view' => Str::after($response->getOriginalContent()->getPath(), 'views/'),
                'data' => $this->extractDataFromView($response->getOriginalContent()),
            ];
        } else {
            $views = [];
        }

        LogWriterService::write(eventName: 'response', data: [
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'type' => $type,
            'memory' => SerapUtils::getMemoryUsage(),
            'headers' => SerapUtils::mask(data: $response->headers->all()),
            'response' => SerapUtils::safeContent(content: $response->getContent(), type: $type),
            'views' => $views,
        ]);

        return $response;
    }

    /**
     * Extract the data from the given view in array form.
     *
     * @param  \Illuminate\View\View  $view
     * @return array
     */
    protected function extractDataFromView($view)
    {
        return collect($view->getData())->map(function ($value) {
            if ($value instanceof Model) {
                return self::given($value);
            } elseif (is_object($value)) {
                return [
                    'class' => get_class($value),
                    'properties' => SerapUtils::safeContent(json_encode($value), 'json'),
                ];
            } else {
                return SerapUtils::safeContent(json_encode($value), 'json');
            }
        })->toArray();
    }

    /**
     * Format the given model to a readable string.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    public static function given($model)
    {
        if ($model instanceof Pivot && ! $model->incrementing) {
            $keys = [
                $model->getAttribute($model->getForeignKey()),
                $model->getAttribute($model->getRelatedKey()),
            ];
        } else {
            $keys = $model->getKey();
        }

        return get_class($model).':'.implode('_', array_map(function ($value) {
            return $value instanceof BackedEnum ? $value->value : $value;
        }, Arr::wrap($keys)));
    }
}
