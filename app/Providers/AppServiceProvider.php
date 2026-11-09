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

        // In Schulnetzen teilt sich oft die ganze Klasse EINE öffentliche IP. Limits daher pro
        // Gerät (Session-Cookie) bzw. Teilnehmer – plus ein großzügiges Netz pro IP gegen Missbrauch.
        RateLimiter::for('join', function (Request $request) {
            return [
                Limit::perMinute(20)->by('join-s:'.$request->session()->getId()),
                Limit::perMinute(600)->by('join-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('play', function (Request $request) {
            $token = $request->session()->get('participant.token');

            return [
                Limit::perMinute(120)->by($token ? 'play-p:'.$token : 'play-s:'.$request->session()->getId()),
                Limit::perMinute(3000)->by('play-ip:'.$request->ip()),
            ];
        });

        // Escape-Room-Prüfungen: pro Teilnehmer (nicht pro IP – ganze Klassen teilen sich
        // in Schulnetzen oft eine IP). Bremst Durchprobieren von Codes.
        RateLimiter::for('escape', function (Request $request) {
            $token = $request->session()->get('participant.token');

            return Limit::perMinute(30)->by($token ? 'p:'.$token : 'ip:'.$request->ip());
        });
    }
}
