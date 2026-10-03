<?php

use Illuminate\Support\Facades\Schedule;

/*
| Hostpoint has no permanent queue worker. The cron job calls
| `php artisan schedule:run` every minute, which starts a worker that works through the queue
| and then exits. withoutOverlapping prevents two workers at the same time.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50')
	->everyMinute()
	->withoutOverlapping(20)
	->runInBackground();
