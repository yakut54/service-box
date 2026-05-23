<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
*/

Schedule::command('bookings:send-reminders')->everyFiveMinutes();
Schedule::command('bookings:morning-digest')->everyFifteenMinutes();
Schedule::command('storage:cleanup')->dailyAt('03:00');
