<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckExpiredSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:check-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and update expired subscriptions, downgrade to free plan';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired subscriptions...');

        // Find active paid subscriptions that have expired (exclude free — free never expires)
        $expiredSubscriptions = Subscription::where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', Carbon::now())
            ->where('plan', '!=', 'free')
            ->get();

        $expiredCount = 0;
        $downgradedCount = 0;

        foreach ($expiredSubscriptions as $subscription) {
            // Mark as expired
            $subscription->update(['status' => 'expired']);
            $expiredCount++;

            // Check if tenant already has an active free subscription
            $hasActiveFree = Subscription::where('tenant_id', $subscription->tenant_id)
                ->where('status', 'active')
                ->where('plan', 'free')
                ->exists();

            if (! $hasActiveFree) {
                // Auto-create free subscription so user can still access the app with limited features
                Subscription::create([
                    'tenant_id' => $subscription->tenant_id,
                    'plan' => 'free',
                    'duration_months' => 0,
                    'price' => 0,
                    'status' => 'active',
                    'starts_at' => Carbon::now(),
                    'ends_at' => null, // Free plan never expires
                    'payment_provider' => 'internal',
                    'payment_reference' => null,
                    'metadata' => [
                        'downgraded_from' => $subscription->plan,
                        'downgraded_at' => Carbon::now()->toIso8601String(),
                        'previous_subscription_id' => $subscription->id,
                        'previous_ends_at' => $subscription->ends_at->toIso8601String(),
                    ],
                ]);
                $downgradedCount++;

                Log::info('Subscription downgraded to free', [
                    'tenant_id' => $subscription->tenant_id,
                    'previous_plan' => $subscription->plan,
                    'previous_subscription_id' => $subscription->id,
                ]);
            }
        }

        $this->info("Updated {$expiredCount} expired subscriptions.");
        $this->info("Downgraded {$downgradedCount} tenants to free plan.");

        return Command::SUCCESS;
    }
}
