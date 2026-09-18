<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule subscription expiration check daily at midnight (TETAP AKTIF — bukan blast)
Schedule::command('subscriptions:check-expired')->daily();

// Schedule subscription expiry reminders daily at 09:00 (TETAP AKTIF — H-2/H-1 paket Pro)
Schedule::command('subscriptions:send-reminders')->daily()->at('09:00');

// Schedule security monitoring every 5 minutes (TETAP AKTIF — bukan blast WA ke user)
Schedule::command('security:monitor --alert')->everyFiveMinutes();

// Schedule security cleanup daily at 02:00
Schedule::command('security:monitor --clean')->daily()->at('02:00');

// =====================================================================
// Reminder blasts — hanya jalan kalau FINWA_REMINDER_BLASTS_ENABLED=true
// Tujuannya: kurangi traffic keluar dari nomor bot (risiko restrict WA).
// =====================================================================
if (config('services.reminder_blasts.enabled', false)) {
    // Schedule weekly digest every Sunday at 20:00 (system-wide toggle)
    if (config('services.weekly_digest.enabled', false)) {
        Schedule::command('digest:weekly')->weekly()->sundays()->at('20:00');
    }

    // Schedule daily reminder every 3 minutes (processes batches of 30 users)
    Schedule::command('reminder:daily')->everyThreeMinutes();

    // Schedule custom user reminders every minute
    Schedule::command('finwa:send-reminders')->everyMinute();

    // Schedule daily budget health check at 19:00 WIB (12:00 UTC)
    Schedule::command('budget:daily-health-check')->daily()->at('19:00');

    // Schedule recurring bill detection every Sunday at 19:30 WIB (12:30 UTC)
    Schedule::command('detect:recurring-bills')->weekly()->sundays()->at('19:30');

    // Schedule morning escalation for 2+ day inactive users at 09:00 WIB (02:00 UTC)
    Schedule::command('reminder:morning-escalation')->daily()->at('09:00');

    // Schedule mid-month cashflow prediction on 15th at 08:00 WIB (01:00 UTC)
    Schedule::command('cashflow:mid-month')->monthlyOn(15, '08:00');
}
