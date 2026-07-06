<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface lazy-loading, discarded-attribute, and missing-attribute
        // mistakes during development instead of hiding them in production.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Domain models live in their module namespace (app/Modules/*/Models)
        // while factories stay in the flat database/factories directory.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName): string => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        // One password policy everywhere: the self-service reset flow
        // (Fortify's Password::default()) must match the 12-character minimum
        // the admin user form enforces.
        Password::defaults(fn () => Password::min(12));
    }
}
