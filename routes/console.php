<?php

use App\Services\ApplicationDatabaseMigrationFence;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('crucible:dispatch-due-query-requests')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => app(ApplicationDatabaseMigrationFence::class)->isActive());

Schedule::command('crucible:expire-query-sessions')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => app(ApplicationDatabaseMigrationFence::class)->isActive());

Schedule::command('crucible:expire-native-proxy-leases')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => app(ApplicationDatabaseMigrationFence::class)->isActive());

Schedule::command('crucible:check-native-proxy-health')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => app(ApplicationDatabaseMigrationFence::class)->isActive());

Schedule::command('crucible:prune-native-proxy-state')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => app(ApplicationDatabaseMigrationFence::class)->isActive());
