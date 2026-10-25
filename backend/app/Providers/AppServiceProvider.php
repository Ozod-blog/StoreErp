<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (config('app.env') !== 'local') {
            URL::forceScheme('https');
        }
        URL::forceScheme('https');
        // Har qanday "can:modul.amal" tekshiruvi shu yerdan o'tadi.
        // true qaytarsa ruxsat; null qaytarsa Gate keyingi qoidalarga o'tadi va (aniqlanmagan bo'lsa) rad etadi.
        Gate::before(fn(User $user, string $ability) => $user->hasPermission($ability) ? true : null);

        RateLimiter::for('login', fn(Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn(Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
    }
}
