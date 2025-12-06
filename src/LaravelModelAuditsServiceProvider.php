<?php

namespace SoftArtisan\LaravelModelAudits;

use SoftArtisan\LaravelModelAudits\Commands\LaravelModelAuditsCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelModelAuditsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-model-audits')
            ->hasConfigFile(['model-audits'])
            ->hasMigration('create_laravel_model_audits_table')
            ->hasCommand(LaravelModelAuditsCommand::class);
    }
}
