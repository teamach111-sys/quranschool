<?php

namespace App\Providers;

use Gate;
use Illuminate\Support\ServiceProvider;

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
        $dashboardRoles = ['admin', 'teacher', 'secretary'];

        // Only staff accounts may open the dashboard.
        Gate::define('access-dashboard', function ($user) use ($dashboardRoles) {
            return in_array($user->role ?? null, $dashboardRoles, true);
        });

        Gate::define('access-admin', fn ($user) => ($user->role ?? null) === 'admin');
        Gate::define('access-teacher', fn ($user) => ($user->role ?? null) === 'teacher');
        Gate::define('access-secretary', fn ($user) => ($user->role ?? null) === 'secretary');
    }
}
