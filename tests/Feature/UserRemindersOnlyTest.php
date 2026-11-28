<?php

/**
 * Pengingat hanya yang DIMINTA user (blast massal OFF):
 *  - reminder:daily hanya untuk akun yang mengetik "aktifkan reminder" (opt-in)
 *  - pengingat buatan user & pengingat langganan tetap terjadwal
 *  - cek kesehatan anggaran harian & blast lain tidak terjadwal
 */

use App\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

function scheduledCommands(): array
{
    return collect(app(Schedule::class)->events())
        ->map(fn ($e) => preg_replace('/^.*artisan.?\s+/', '', (string) $e->command))
        ->map(fn ($c) => trim(str_replace(["'", '"'], '', $c)))
        ->all();
}

it('blast OFF: reminder harian hanya untuk akun yang opt-in', function () {
    config(['services.reminder_blasts.enabled' => false, 'services.user_reminders.enabled' => true]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);

    $optIn = Tenant::create(['name' => 'OptIn', 'slug' => 'optin', 'is_active' => true, 'settings' => ['daily_reminder_enabled' => true]]);
    $default = Tenant::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true, 'settings' => []]);
    $off = Tenant::create(['name' => 'Off', 'slug' => 'off', 'is_active' => true, 'settings' => ['daily_reminder_enabled' => false]]);

    $this->artisan('reminder:daily', ['--force' => true, '--limit' => 10])
        ->expectsOutputToContain("Tenant {$default->id}: Daily reminder disabled by user, skipping")
        ->expectsOutputToContain("Tenant {$off->id}: Daily reminder disabled by user, skipping")
        ->doesntExpectOutputToContain("Tenant {$optIn->id}: Daily reminder disabled by user, skipping")
        ->assertSuccessful();
});

it('blast ON: perilaku lama — semua akun kecuali yang mematikan', function () {
    config(['services.reminder_blasts.enabled' => true]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);

    $default = Tenant::create(['name' => 'Default', 'slug' => 'default', 'is_active' => true, 'settings' => []]);
    $off = Tenant::create(['name' => 'Off', 'slug' => 'off', 'is_active' => true, 'settings' => ['daily_reminder_enabled' => false]]);

    $this->artisan('reminder:daily', ['--force' => true, '--limit' => 10])
        ->expectsOutputToContain("Tenant {$off->id}: Daily reminder disabled by user, skipping")
        ->doesntExpectOutputToContain("Tenant {$default->id}: Daily reminder disabled by user, skipping")
        ->assertSuccessful();
});

it('jadwal saat blast OFF: pengingat user & langganan aktif, cek anggaran harian tidak', function () {
    config(['services.reminder_blasts.enabled' => false, 'services.user_reminders.enabled' => true]);
    require base_path('routes/console.php');

    $commands = implode("\n", scheduledCommands());
    expect($commands)->toContain('finwa:send-reminders')
        ->toContain('reminder:daily')
        ->toContain('subscriptions:send-reminders')
        ->not->toContain('budget:daily-health-check')
        ->not->toContain('reminder:morning-escalation')
        ->not->toContain('cashflow:mid-month')
        ->not->toContain('digest:weekly');
});
