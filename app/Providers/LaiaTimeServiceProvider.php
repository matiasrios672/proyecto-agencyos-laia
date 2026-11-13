<?php

namespace App\Providers;

use App\Services\LaiaTime\HttpLaiaTimeApi;
use App\Services\LaiaTime\LaiaTimeApi;
use Illuminate\Support\ServiceProvider;

class LaiaTimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `bind` (no `singleton`): cada resolución lee la configuración vigente de Configuración.
        $this->app->bind(LaiaTimeApi::class, HttpLaiaTimeApi::class);
    }
}
