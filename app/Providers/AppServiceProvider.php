<?php

namespace App\Providers;

use App\Support\BoundedSsrGateway;
use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\Gateway;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Gateway SSR à timeouts bornés (enregistrement après les
        // packages : notre binding l'emporte sur celui d'Inertia) —
        // si le service SSR tombe, repli immédiat sur le rendu client.
        $this->app->bind(Gateway::class, BoundedSsrGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
