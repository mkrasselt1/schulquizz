<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        // Eigener Health-Check statt Laravels /up-Seite (die lädt Schrift + Skript von CDNs).
        then: function () {
            \Illuminate\Support\Facades\Route::get('/up', fn () => response('OK', 200, ['Content-Type' => 'text/plain']));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            \App\Http\Middleware\SetLocale::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Hinter einem TLS-terminierenden Reverse-Proxy (Plesk, Traefik, nginx …):
        // dem Proxy vertrauen und X-Forwarded-Proto/Host auswerten, damit Laravel
        // korrekte https-URLs für Assets erzeugt (sonst Mixed-Content → weiße Seite).
        // Nur Proxys aus privaten Netzen (Docker-Bridge, Plesk auf demselben Host) – sonst könnte
        // jeder per X-Forwarded-For eine fremde IP vortäuschen (Rate-Limits). Anpassbar per
        // TRUSTED_PROXIES (kommagetrennt, "*" = allen vertrauen).
        $proxies = env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1,fc00::/7');
        $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // MCP-Clients (KI-Assistenten) brauchen JSON + 401 statt Weiterleitung zum Login,
            // damit sie die OAuth-Anmeldung starten können.
            fn (Request $request) => $request->is('api/*', 'mcp', 'mcp/*'),
        );
    })->create();
