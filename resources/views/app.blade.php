<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name') }}</title>

        {{-- Name/Beschreibung für Browser-Tab, Suchmaschinen und Link-Vorschauen (Messenger) --}}
        <meta name="application-name" content="{{ config('app.name') }}">
        <meta name="description" content="{{ config('schulquizz.tagline') }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('app.name') }}">
        <meta property="og:title" content="{{ config('app.name') }}">
        <meta property="og:description" content="{{ config('schulquizz.tagline') }}">
        <meta property="og:url" content="{{ url('/') }}">
        <meta name="twitter:card" content="summary">
        <meta name="theme-color" content="#4338ca">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        {{-- Theme (dark/light) vor dem Paint setzen – kein Flackern. Rein lokal. --}}
        <script>
            (function () {
                try {
                    var t = localStorage.getItem('schulquizz.theme');
                    var dark = t === 'dark' || (t === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        {{-- Keine externen Schriften/CDNs (Datenschutz): System-Font-Fallback. --}}

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
