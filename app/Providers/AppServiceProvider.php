<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Roles\DefaultRolePermissions;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Slack\Provider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // Memoise default-role lookups for the request; consulted on every
        // Gate::before check below.
        $this->app->singleton(DefaultRolePermissions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Store/parse timestamps in UTC (config('app.timezone')); display them
        // in Vietnam time (UTC+7) across the Filament admin. Every dateTime
        // column/picker without an explicit ->timezone() honors this default.
        FilamentTimezone::set('Asia/Ho_Chi_Minh');

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('slack', Provider::class);
        });

        Gate::define('admin', fn (User $user): bool => $user->isAdministrator());

        // Default roles are virtual: every user implicitly holds the
        // permissions granted by any `is_default` role, without a per-user
        // `model_has_roles` row. Returning null falls through to the normal
        // policy/permission check (a user's own roles can grant more).
        Gate::before(function (mixed $user, string $ability): ?bool {
            return app(DefaultRolePermissions::class)->grants($ability) ? true : null;
        });
    }
}
