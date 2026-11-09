<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Datensparsamkeit & Speicherplatz: verwaiste Sessions beenden, alte Sessions, verwaiste
// Bilder und abgelaufene Cache-Einträge löschen.
Schedule::command('schulquizz:cleanup')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
Schedule::command('passport:purge')->daily();
