<?php

namespace LuangDev\Serap;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use LuangDev\Serap\Commands\SerapCommand;
use LuangDev\Serap\Facades\Clock;
use LuangDev\Serap\Watchers\QueryWatcher;
use LuangDev\Serap\Watchers\ResponseWatcher;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SerapServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('serap')
            ->hasConfigFile('serap')
            ->hasCommand(SerapCommand::class);

        // info("app registered: " . app(Clock::class)->monotonicNs());
    }

    /**
     * Method ini dipanggil setelah package selesai dikonfigurasi & di-register
     */
    public function packageBooted(): void
    {
        $this->registerListeners();
        $this->registerAboutCommand();

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', SerapMiddleware::class);
        $router->pushMiddlewareToGroup('api', SerapMiddleware::class);

        app(Serap::class)->setTimestamp('app_booted', Clock::nowIso8601());
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SerapMiddleware::class);
        $this->app->singleton(Serap::class, fn () => new Serap());
        $this->app->singleton(Clock::class, fn () => new Clock());

        app(Serap::class)->setTimestamp('app_registered', Clock::nowIso8601());
    }

    protected function registerListeners(): void
    {
        Event::listen(RouteMatched::class, function () {
            app(Serap::class)->setTimestamp('route_matched', Clock::nowIso8601());
        });

        Event::listen(RequestHandled::class, ResponseWatcher::class);

        Event::listen(Terminating::class, function () {
            app(Serap::class)->setTimestamp('app_terminated', Clock::nowIso8601());
        });

        Event::listen(QueryExecuted::class, QueryWatcher::class);
    }

    protected function registerAboutCommand(): void
    {
        AboutCommand::add(
            section: 'Serap',
            data: fn (): array => [
                'Version' => '0.0.1',
            ],
        );
    }
}
