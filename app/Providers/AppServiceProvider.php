<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
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
        Vite::prefetch(concurrency: 3);

        // Escape-Room-Prüfungen: pro Teilnehmer (nicht pro IP – ganze Klassen teilen sich
        // in Schulnetzen oft eine IP). Bremst Durchprobieren von Codes.
        RateLimiter::for('escape', function (Request $request) {
            $token = $request->session()->get('participant.token');

            return Limit::perMinute(30)->by($token ? 'p:'.$token : 'ip:'.$request->ip());
        });
    }
}
