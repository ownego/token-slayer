<?php

namespace App\Providers;

use App\Models\User;
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
        // Store/parse timestamps in UTC (config('app.timezone')); display them
        // in Vietnam time (UTC+7) across the Filament admin. Every dateTime
        // column/picker without an explicit ->timezone() honors this default.
        FilamentTimezone::set('Asia/Ho_Chi_Minh');

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('slack', Provider::class);
        });

        Gate::define('admin', fn (User $user): bool => $user->isAdministrator());
    }
}
