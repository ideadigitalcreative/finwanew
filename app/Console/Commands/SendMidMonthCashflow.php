<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Tenant;
use App\Services\CashflowPredictionService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendMidMonthCashflow extends Command
{
    protected $signature = 'cashflow:mid-month {--test : Test mode - only show predictions} {--limit=0 : Limit tenants (0 = all)}';

    protected $description = 'Send mid-month cashflow prediction to users with budget';

    public function handle(): int
    {
        $this->info('Starting Mid-Month Cashflow Prediction...');

        $now = Carbon::now('Asia/Jakarta');
        $tenants = Tenant::where('is_active', true)->get();

        if ($limit = (int) $this->option('limit')) {
            $tenants = $tenants->take($limit);
        }

        $this->info("Processing {$tenants->count()} tenants");

        $service = new CashflowPredictionService;
        $sent = 0;
        $skipped = 0;
        $noBudget = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            try {
                $settings = $tenant->settings ?? [];

                if (! ($settings['daily_reminder_enabled'] ?? true)) {
                    $skipped++;

                    continue;
                }

                $cacheKey = "mid_month_cashflow_{$tenant->id}_".$now->format('Y-m');
                if (Cache::has($cacheKey)) {
                    $skipped++;

                    continue;
                }

                $prediction = $service->predict($tenant->id);

                if (! $prediction) {
                    $skipped++;

                    continue;
                }

                if ($prediction['status'] === 'no_budget') {
                    $noBudget++;

                    continue;
                }

                $message = $service->formatMessage($prediction);

                if ($this->option('test')) {
                    $this->info("Tenant {$tenant->id}: {$prediction['status']}");
                    $this->line($message);
                    $this->line('---');

                    continue;
                }

                $user = $tenant->users()->whereNotNull('whatsapp_number')->first();

                if (! $user || ! $user->whatsapp_number) {
                    $skipped++;

                    continue;
                }

                $success = $this->sendToWhatsApp($tenant, $user->whatsapp_number, $message);

                if ($success) {
                    $sent++;
                    Cache::put($cacheKey, true, $now->copy()->endOfMonth());
                    $this->info("Tenant {$tenant->id}: Sent ({$prediction['status']})");
                } else {
                    $errors++;
                }

                sleep(2);

            } catch (\Exception $e) {
                $errors++;
                Log::error('Mid-month cashflow failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("\n=== Mid-Month Cashflow Complete ===");
        $this->info("Sent: {$sent}");
        $this->info("No budget: {$noBudget}");
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
                Log::warning('No WhatsApp session for mid-month cashflow', [
                    'tenant_id' => $tenant->id,
                ]);

                return false;
            }

            $formattedNumber = $this->formatPhoneNumber($phoneNumber);
            $result = $whatsappService->sendMessage($sessionId, $formattedNumber, $message);

            if ($result['success'] ?? false) {
                Log::info('Mid-month cashflow sent', [
                    'tenant_id' => $tenant->id,
                    'phone' => substr($formattedNumber, 0, 8).'***',
                ]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Exception mid-month cashflow', [
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
