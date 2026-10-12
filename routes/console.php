<?php

use Illuminate\Support\Facades\Schedule;

// The test engine runs every minute. In development: php artisan schedule:work
// In production, add one cron entry: * * * * * php /path/to/artisan schedule:run
Schedule::command('bms:engine')->everyMinute()->withoutOverlapping()->runInBackground()->when(fn () => config('bms.engine_enabled'));
