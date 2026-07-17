<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Schulquizz') }}</title>

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
