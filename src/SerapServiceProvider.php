<?php

namespace LuangDev\Serap;

use Illuminate\Support\Facades\Event;
use Spatie\LaravelPackageTools\Package;
use LuangDev\Serap\Commands\SerapCommand;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SerapServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        info('app register: ' . (defined('LARAVEL_START') ? LARAVEL_START : request()->server('REQUEST_TIME_FLOAT')));

        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name(name: 'serap')
            ->hasConfigFile('serap')
            ->hasCommand(commandClassName: SerapCommand::class);

        Event::listen(RouteMatched::class, function (RouteMatched $event) {
            info('RouteMatched: ', [
                'time' => microtime(true),
                'event' => $event,
            ]);
        });


        AboutCommand::add(section: 'Serap', data: fn(): array => [
            'Version' => '0.0.1',
        ]);


        $this->app->terminating(function () {
            info('app terminating: ' . microtime(true));
        });
    }

    public function boot()
    {
        parent::boot();

        info('app boot: ' . microtime(true));
    }
}
