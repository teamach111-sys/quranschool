<?php

namespace App\Providers;

use App\Auth\SchoolDatabaseUserProvider;
use Illuminate\Support\Facades\Auth;
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
        Auth::provider('school-database', function ($app, array $config) {
            return new SchoolDatabaseUserProvider(
                $app['db']->connection($config['connection'] ?? null),
                $app['hash'],
                $config['table'],
            );
        });

        $dashboardRoles = ['admin', 'teacher', 'secretary'];

        // Only staff accounts may open the dashboard.
        \Illuminate\Support\Facades\Gate::define('access-dashboard', function ($user) use ($dashboardRoles) {
            return in_array($user->role ?? null, $dashboardRoles, true);
        });

        \Illuminate\Support\Facades\Gate::define('access-admin', fn($user) => ($user->role ?? null) === 'admin');
        \Illuminate\Support\Facades\Gate::define('access-teacher', fn($user) => ($user->role ?? null) === 'teacher');
        \Illuminate\Support\Facades\Gate::define('access-secretary', fn($user) => ($user->role ?? null) === 'secretary');
    }
}
