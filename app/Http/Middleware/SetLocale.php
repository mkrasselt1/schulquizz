<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Setzt die App-Sprache aus der Nutzerwahl (Cookie `locale`, gesetzt vom Sprachumschalter),
 * damit auch serverseitige Meldungen (Login-/Validierungsfehler) in der gewählten Sprache
 * erscheinen. Fallback: Browser-Sprache, dann App-Default.
 */
class SetLocale
{
    private const SUPPORTED = ['de', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->cookie('locale');

        if (! in_array($locale, self::SUPPORTED, true)) {
            $browser = substr((string) $request->getPreferredLanguage(self::SUPPORTED), 0, 2);
            $locale = in_array($browser, self::SUPPORTED, true) ? $browser : config('app.locale');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
