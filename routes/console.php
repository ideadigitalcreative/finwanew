<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule subscription expiration check daily at midnight
Schedule::command('subscriptions:check-expired')->daily();

// Schedule subscription expiry reminders daily at 09:00
Schedule::command('subscriptions:send-reminders')->daily()->at('09:00');

// Schedule weekly digest every Sunday at 20:00
Schedule::command('digest:weekly')->weekly()->sundays()->at('20:00');

// Schedule daily reminder every 3 minutes (processes batches of 30 users)
Schedule::command('reminder:daily')->everyThreeMinutes();
