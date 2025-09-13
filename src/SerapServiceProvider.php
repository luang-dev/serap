<?php

namespace LuangDev\Serap;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use LuangDev\Serap\Commands\SerapCommand;
use LuangDev\Serap\Jobs\LogSenderJob;
use LuangDev\Serap\Watchers\WatcherManager;
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
            ->name(name: 'serap')
            ->hasConfigFile('serap')
            ->hasCommand(commandClassName: SerapCommand::class);

        WatcherManager::register();

        AboutCommand::add(section: 'Serap', data: fn (): array => [
            'Version' => '0.0.1',
        ]);

        $this->app->afterResolving(abstract: Schedule::class, callback: function (Schedule $schedule): void {
            $schedule->job(job: new LogSenderJob)->everyMinute();
        });
    }
}
