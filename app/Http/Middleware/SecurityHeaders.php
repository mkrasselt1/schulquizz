<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheits-Header unabhängig vom Webserver/Proxy (Docker-Varianten, lokaler Betrieb).
 *
 * Die Content-Security-Policy erzwingt technisch das Versprechen „keine externen Dienste“:
 * Der Browser lädt Skripte, Styles, Schriften, Bilder und Verbindungen nur von der eigenen
 * Domain. (Kein form-action: der OAuth-Login leitet nach dem Formular zum KI-Assistenten weiter.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $headers->set('Referrer-Policy', 'same-origin', false);

        // Im Vite-Dev-Modus kommen Skripte vom Dev-Server – dann keine CSP.
        if (! $headers->has('Content-Security-Policy') && ! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "media-src 'self' data: blob:",
                "font-src 'self' data:",
                // WebSocket (Reverb) nur zur eigenen Domain; ältere Browser zählen ws/wss nicht zu 'self'.
                "connect-src 'self' wss://{$request->getHttpHost()} ws://{$request->getHttpHost()}",
                "object-src 'none'",
                "base-uri 'self'",
                "frame-ancestors 'self'",
            ]));
        }

        return $response;
    }
}
