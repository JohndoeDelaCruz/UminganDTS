<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::define('admin', fn (User $user) => $user->isAdmin() && $user->is_active);
        RateLimiter::for('public-tracking', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());
        DB::prohibitDestructiveCommands(app()->isProduction());
    }
}
