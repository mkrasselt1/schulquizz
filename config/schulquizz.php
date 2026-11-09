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
];
