<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DailyReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a WhatsApp channel for testing
        Channel::create([
            'type' => 'whatsapp',
            'is_active' => true,
            'config' => [
                'session_id' => 'test-session',
            ],
        ]);
    }

    /** @test */
    public function it_skips_sending_reminder_when_user_disabled_it()
    {
        // Arrange: Create tenant with reminder DISABLED
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'is_active' => true,
            'settings' => [
                'daily_reminder_enabled' => false, // DISABLED
                'reminder_hour' => Carbon::now('Asia/Jakarta')->hour, // Current hour
            ],
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'whatsapp_number' => '081234567890',
            'tenant_id' => $tenant->id,
        ]);

        // Act: Run the command with --test flag
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            '--force' => true, // Force send even if has transactions
        ]);

        // Assert: Command should succeed but skip this tenant
        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringContainsString('Daily reminder disabled by user, skipping', $output);
        $this->assertStringContainsString('Sent: 0', $output);
        $this->assertStringContainsString('Skipped: 1', $output);
    }

    /** @test */
    public function it_sends_reminder_when_user_enabled_it()
    {
        // Arrange: Create tenant with reminder ENABLED
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'is_active' => true,
            'settings' => [
                'daily_reminder_enabled' => true, // ENABLED
                'reminder_hour' => Carbon::now('Asia/Jakarta')->hour,
            ],
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'whatsapp_number' => '081234567890',
            'tenant_id' => $tenant->id,
        ]);

        // Mock WhatsApp service to prevent actual sending
        $this->mock(\App\Services\WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn(['success' => true]);
        });

        // Act: Run the command
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            '--force' => true,
        ]);

        // Assert: Should attempt to send
        $this->assertEquals(0, $exitCode);

        $output = Artisan::output();
        $this->assertStringNotContainsString('Daily reminder disabled by user', $output);
    }

    /** @test */
    public function it_defaults_to_enabled_for_tenants_without_setting()
    {
        // Arrange: Create tenant WITHOUT daily_reminder_enabled setting (backward compatibility)
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'is_active' => true,
            'settings' => [
                'reminder_hour' => Carbon::now('Asia/Jakarta')->hour,
                // NO 'daily_reminder_enabled' key
            ],
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'whatsapp_number' => '081234567890',
            'tenant_id' => $tenant->id,
        ]);

        // Mock WhatsApp service
        $this->mock(\App\Services\WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn(['success' => true]);
        });

        // Act
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            '--force' => true,
        ]);

        // Assert: Should send (default is enabled)
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringNotContainsString('Daily reminder disabled by user', $output);
    }

    /** @test */
    public function it_skips_when_user_has_transactions_today()
    {
        // Arrange
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'is_active' => true,
            'settings' => [
                'daily_reminder_enabled' => true,
                'reminder_hour' => Carbon::now('Asia/Jakarta')->hour,
            ],
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'whatsapp_number' => '081234567890',
            'tenant_id' => $tenant->id,
        ]);

        // Create a transaction today
        Transaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'expense',
            'amount' => 50000,
            'transaction_date' => Carbon::now('Asia/Jakarta'),
            'description' => 'Test transaction',
            'category_id' => 1, // Assume category exists
            'status' => 'confirmed',
        ]);

        // Act: Run WITHOUT --force flag
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            // NO --force flag
        ]);

        // Assert: Should skip because has transactions
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Already has transactions today, skipping', $output);
        $this->assertStringContainsString('Sent: 0', $output);
    }

    /** @test */
    public function integration_test_disable_then_enable_reminder()
    {
        // This simulates the full user flow

        // Step 1: Create tenant with reminder enabled
        $tenant = Tenant::create([
            'name' => 'Integration Test Tenant',
            'is_active' => true,
            'settings' => [
                'daily_reminder_enabled' => true,
                'reminder_hour' => Carbon::now('Asia/Jakarta')->hour,
            ],
        ]);

        $user = User::create([
            'name' => 'Integration User',
            'email' => 'integration@example.com',
            'password' => bcrypt('password'),
            'whatsapp_number' => '081234567890',
            'tenant_id' => $tenant->id,
        ]);

        // Step 2: User disables reminder (simulate "matikan reminder")
        $settings = $tenant->settings;
        $settings['daily_reminder_enabled'] = false;
        $tenant->update(['settings' => $settings]);

        // Step 3: Run reminder command
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            '--force' => true,
        ]);

        // Step 4: Verify it was skipped
        $output = Artisan::output();
        $this->assertStringContainsString('Daily reminder disabled by user, skipping', $output);
        $this->assertStringContainsString('Sent: 0', $output);

        // Step 5: User re-enables reminder (simulate "aktifkan reminder")
        $settings['daily_reminder_enabled'] = true;
        $tenant->update(['settings' => $settings]);

        // Mock WhatsApp for this test
        $this->mock(\App\Services\WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('sendMessage')
                ->once()
                ->andReturn(['success' => true]);
        });

        // Step 6: Run reminder again
        $exitCode = Artisan::call('reminder:daily', [
            '--test' => true,
            '--force' => true,
        ]);

        // Step 7: Verify it was sent this time
        $output = Artisan::output();
        $this->assertStringNotContainsString('Daily reminder disabled by user', $output);
    }
}
