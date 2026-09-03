<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crucible:dispatch-due-query-requests')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('crucible:expire-query-sessions')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('crucible:expire-native-proxy-leases')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('crucible:check-native-proxy-health')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('crucible:prune-native-proxy-state')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
