<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Tenant;
use App\Services\Budget\BudgetAlertService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckDailyBudgetHealth extends Command
{
    protected $signature = 'budget:daily-health-check {--test : Test mode - only show alerts, don\'t send} {--limit=0 : Limit tenants to process (0 = all)}';

    protected $description = 'Proactive budget health check - alerts users even without new transactions';

    public function handle(): int
    {
        $this->info('Starting Daily Budget Health Check...');

        $tenants = Tenant::where('is_active', true)->get();

        if ($limit = (int) $this->option('limit')) {
            $tenants = $tenants->take($limit);
        }

        $this->info("Processing {$tenants->count()} tenants");

        $sent = 0;
        $skipped = 0;
        $noAlert = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            try {
                $settings = $tenant->settings ?? [];

                if (! ($settings['daily_reminder_enabled'] ?? true)) {
                    $skipped++;

                    continue;
                }

                $cacheKey = "budget_health_alert_{$tenant->id}_".Carbon::now('Asia/Jakarta')->format('Y-m-d');
                if (Cache::has($cacheKey)) {
                    $skipped++;

                    continue;
                }

                $alert = BudgetAlertService::generateProactiveBudgetAlert($tenant->id);

                if (! $alert) {
                    $noAlert++;

                    continue;
                }

                if ($this->option('test')) {
                    $this->info("Tenant {$tenant->id}: Alert generated (test mode, not sending)");
                    $this->line($alert);
                    $this->line('---');

                    continue;
                }

                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                if (! $user || ! $user->whatsapp_number) {
                    $skipped++;

                    continue;
                }

                $sent += $this->sendToWhatsApp($tenant, $user->whatsapp_number, $alert)
                    ? 1
                    : 0;

                Cache::put($cacheKey, true, Carbon::now('Asia/Jakarta')->endOfDay());

                sleep(2);

            } catch (\Exception $e) {
                $errors++;
                Log::error('Failed budget health check', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("\n=== Budget Health Check Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("No alert needed: {$noAlert}");
        $this->info("Skipped: {$skipped}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }

    protected function sendToWhatsApp(Tenant $tenant, string $phoneNumber, string $message): bool
    {
        try {
            $whatsappService = new WhatsAppService;
            $sessionId = $this->getActiveSession($tenant);

            if (! $sessionId) {
                $sessionId = $this->getSharedSession();
            }

            if (! $sessionId) {
                Log::warning('No WhatsApp session available for budget health alert', [
                    'tenant_id' => $tenant->id,
                ]);

                return false;
            }

            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            $result = $whatsappService->sendMessage($sessionId, $formattedNumber, $message);

            if ($result['success'] ?? false) {
                Log::info('Budget health alert sent', [
                    'tenant_id' => $tenant->id,
                    'phone' => substr($formattedNumber, 0, 8).'***',
                ]);

                return true;
            }

            Log::error('Failed budget health alert send', [
                'tenant_id' => $tenant->id,
                'error' => $result['error'] ?? 'unknown',
            ]);

            return false;

        } catch (\Exception $e) {
            Log::error('Exception sending budget health alert', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function getActiveSession(Tenant $tenant): ?string
    {
        $channel = Channel::where('tenant_id', $tenant->id)
            ->where('type', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if ($channel) {
            $config = $channel->config ?? [];

            return $config['session_id'] ?? $channel->session_id ?? null;
        }

        return null;
    }

    protected function getSharedSession(): ?string
    {
        $channel = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->where('is_shared_channel', true)
            ->first();

        if (! $channel) {
            $channel = Channel::where('type', 'whatsapp')
                ->where('is_active', true)
                ->first();
        }

        if ($channel) {
            $config = $channel->config ?? [];

            return $config['session_id'] ?? $channel->session_id ?? null;
        }

        return null;
    }

    protected function formatPhoneNumber(string $number): string
    {
        $number = preg_replace('/[^0-9]/', '', $number);

        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }

        return $number;
    }
}
