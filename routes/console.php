<?php

use Illuminate\Support\Facades\Schedule;

/*
| Hostpoint hat keinen dauerhaften Queue-Worker. Der Cronjob ruft jede Minute
| `php artisan schedule:run` auf, das startet einen Worker, der die Warteschlange
| abarbeitet und sich dann beendet. withoutOverlapping verhindert zwei Worker gleichzeitig.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->runInBackground();
