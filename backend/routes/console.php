<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('media:prune-chunks')->hourly();

Schedule::command('protocols:send-phase-reminders')->everyMinute()->withoutOverlapping();

Schedule::command('credits:maintain')->hourly()->withoutOverlapping();

Schedule::command('credits:send-expiry-reminders')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('credits:grant-test-monthly')->hourly()->withoutOverlapping();

Schedule::command('intakes:send-submission-notifications')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => DB::table('pending_registrations')->where('expires_at', '<=', now())->delete())->hourly();
