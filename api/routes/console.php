<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Resynchronisation calendrier iCloud
|--------------------------------------------------------------------------
|
| Cadence HORAIRE et non quotidienne, volontairement : une tâche planifiée à
| une heure fixe peut, lors du passage à l'heure d'hiver, tourner DEUX fois
| (l'heure locale est rejouée) et, au passage à l'heure d'été, ne tourner
| AUCUNE fois (l'heure locale n'existe pas ce jour-là). Un créneau horaire
| échappe à ces deux cas, et la commande reste de toute façon rejouable :
| elle ne fait que redispatcher SyncCalendarEvent, qui est idempotent
| (UID déterministe + If-None-Match / If-Match).
|
| withoutOverlapping() : un gros rattrapage (compte reconnecté, panne iCloud)
| peut durer plus d'une heure ; sans ce verrou l'exécution suivante
| redispatcherait les mêmes événements en parallèle.
|
*/
Schedule::command('papers:calendar-resync')
    ->hourly()
    ->withoutOverlapping(60)
    ->runInBackground()
    ->onFailure(function (): void {
        logger()->error('papers:calendar-resync a échoué.');
    });
