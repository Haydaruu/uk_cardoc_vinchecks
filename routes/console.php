<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('refunds:reconcile')->hourly();
Schedule::command('paypal:reconcile-credit-captures')->everyFifteenMinutes();
Schedule::command('paypal:reconcile-guest-captures')->everyFifteenMinutes();
Schedule::command('webhooks:reconcile-stale --stale-minutes=10')->everyTenMinutes()->withoutOverlapping(15);
