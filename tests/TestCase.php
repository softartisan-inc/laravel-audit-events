<?php

namespace SoftArtisan\LaravelModelAudits\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use SoftArtisan\LaravelModelAudits\LaravelModelAuditsServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'SoftArtisan\\LaravelModelAudits\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelModelAuditsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Base de données SQLite en mémoire
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Charger les migrations du package
        $this->loadMigrationsFrom(realpath(__DIR__.'/../database/migrations'));

        // Créer la table articles pour les tests
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('secret_token')->nullable();
            $table->timestamps();
        });

        // Créer une table avec soft deletes pour les tests spécifiques
        Schema::create('soft_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('secret_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
