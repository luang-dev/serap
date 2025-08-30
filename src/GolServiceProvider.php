<?php

namespace Zzzul\Gol;

use Illuminate\Foundation\Console\AboutCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zzzul\Gol\Commands\GolCommand;
use Zzzul\Gol\Watchers\WatcherManager;

class GolServiceProvider extends PackageServiceProvider
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
            ->hasCommand(commandClassName: GolCommand::class);

        WatcherManager::register(app: $this->app);

        AboutCommand::add(section: 'Gol', data: fn (): array => [
            'Version' => '0.1.0',
        ]);
    }
}
