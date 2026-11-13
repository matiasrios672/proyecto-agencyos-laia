<?php

namespace App\Providers;

use App\Support\MailSettings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
        // El sysadmin tiene acceso total a cualquier habilidad / policy.
        Gate::before(fn ($user) => $user->hasRole('sysadmin') ? true : null);

        // Requisitos de contraseña más estrictos en producción.
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        // SMTP editable desde el panel del sysadmin. En workers de larga vida se reaplica antes de cada job.
        if (! $this->app->runningUnitTests()) {
            MailSettings::apply();
            Queue::before(fn () => MailSettings::apply());
        }
    }
}
