<?php

return [
    /*
     * Registrierung neuer Lehrkraft-Konten:
     *  - REGISTRATION_CODE leer  → jeder kann sich registrieren (Standard)
     *  - REGISTRATION_CODE=xyz   → nur mit diesem Code (an Kolleg:innen weitergeben)
     *  - REGISTRATION_ENABLED=false → Registrierung komplett aus
     */
    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', true),
    'registration_code' => env('REGISTRATION_CODE'),

    /*
     * Kurzbeschreibung für Suchmaschinen und Link-Vorschauen (Messenger, Schul-Chat).
     */
    'tagline' => env('APP_TAGLINE', 'Live-Quizze und Escape-Rooms für die Schule – ohne Schülerkonten, ohne dass Antworten gespeichert werden.'),
];
