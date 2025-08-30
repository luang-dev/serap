<?php

namespace LuangDev\Serap;

use Illuminate\Foundation\Console\AboutCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use LuangDev\Serap\Commands\SerapCommand;
use LuangDev\Serap\Middlewares\SerapMiddleware;
use LuangDev\Serap\Watchers\WatcherManager;
use Illuminate\Support\Facades\Route;

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
            ->name(name: 'gol')
            ->hasConfigFile()
            ->hasCommand(commandClassName: SerapCommand::class);

        WatcherManager::register();

        Route::pushMiddlewareToGroup('web', SerapMiddleware::class);
        Route::pushMiddlewareToGroup('api', SerapMiddleware::class);

        AboutCommand::add(section: 'Gol', data: fn(): array => [
            'Version' => '0.0.1',
        ]);
    }
}
