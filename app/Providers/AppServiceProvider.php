<?php

namespace App\Providers;

use App\Models\User;
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
        Gate::define('access-admin', function (User $user) {
            return $user->hasRole('admin');
        });
        Gate::define('access-teacher', function (User $user) {
            return $user->hasRole('teacher');
        });
        Gate::define('access-secretary', function (User $user) {
            return $user->hasRole('secretary');
        });
    }
}
