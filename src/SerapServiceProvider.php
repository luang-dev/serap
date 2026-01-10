<?php

namespace LuangDev\Serap;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\Commands\SerapCommand;
use LuangDev\Serap\Watchers\ExceptionWatcher;
use LuangDev\Serap\Watchers\QueryWatcher;
use LuangDev\Serap\Watchers\ResponseWatcher;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SerapServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('serap')
            ->hasConfigFile('serap')
            ->hasCommand(SerapCommand::class);
    }

    /**
     * Dipanggil setelah package selesai di-boot
     */
    public function packageBooted(): void
    {
        $this->registerListeners();
        $this->registerAboutCommand();

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', SerapMiddleware::class);
        $router->pushMiddlewareToGroup('api', SerapMiddleware::class);

        // app(Serap::class)->mark('app_booted'); // ❌ sebaiknya dihapus
    }


    public function packageRegistered(): void
    {
        // Singleton instances
        $this->app->singleton(Serap::class, fn() => new Serap());
        $this->app->singleton(Clock::class, fn() => new Clock());

        $this->app->singleton(Sampler::class, function () {
            $rate = (float) config('serap.sampling.rate', 0.1);
            return new Sampler($rate);
        });

        $this->app->singleton(JsonlExporter::class, fn() => new JsonlExporter());

        $this->app->singleton(SerapMiddleware::class, fn() => new SerapMiddleware(app(Sampler::class)));
    }

    protected function registerListeners(): void
    {
        ExceptionWatcher::handle();

        // Route matched -> mark offset ms + optional ISO per mark
        Event::listen(RouteMatched::class, function (RouteMatched $event) {
            $serap = app(Serap::class);

            // Mark timing
            $serap->mark('route_matched');

            // Sekalian update nama transaction dengan route name/controller (tanpa reset anchor)
            // Ini contoh pemakaian mergeTransaction() yang benar
            $routeName = $event->route?->getName();
            $action = $event->route?->getActionName();

            $serap->mergeTransaction([
                'name' => $routeName ?: ($event->request?->method() . ' ' . $event->request?->path()),
                'extra' => [
                    'request' => [
                        'route' => [
                            'name' => $routeName,
                            'action' => $action,
                        ],
                    ],
                ],
            ]);
        });

        // ResponseWatcher sebaiknya:
        // - set mark request_handled
        // - merge response info
        // - finalizeTransaction
        Event::listen(RequestHandled::class, ResponseWatcher::class);

        // App terminating -> mark
        Event::listen(Terminating::class, function () {
            app(Serap::class)->mark('app_terminated');
        });

        // Query watcher -> tambah spans
        Event::listen(QueryExecuted::class, QueryWatcher::class);

        // Event::listen(MessageLogged::class, function (MessageLogged $event) {
        //     // write 
        // });
    }

    protected function registerAboutCommand(): void
    {
        AboutCommand::add(
            section: 'Serap',
            data: fn(): array => [
                'Version' => '0.0.1',
            ],
        );
    }
}
