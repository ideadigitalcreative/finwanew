<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillFreeSubscriptions extends Command
{
    protected $signature = 'subscriptions:backfill-free
                            {--dry-run : Show what would be done without making changes}';

    protected $description = 'Create free subscriptions for tenants that have no active subscription (backfill for existing expired users)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE — no changes will be made.');
        }

        $this->info('Scanning tenants without active subscriptions...');

        // Find all tenants
        $tenants = Tenant::all();
        $backfilledCount = 0;
        $skippedCount = 0;

        foreach ($tenants as $tenant) {
            // Check if tenant has any active subscription
            $hasActive = Subscription::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->exists();

            if ($hasActive) {
                $skippedCount++;

                continue;
            }

            // Check if tenant is in trial
            if ($tenant->trial_ends_at && $tenant->trial_ends_at->isFuture()) {
                $skippedCount++;

                continue;
            }

            // Get the latest expired subscription for metadata
            $latestExpired = Subscription::where('tenant_id', $tenant->id)
                ->whereIn('status', ['expired', 'cancelled'])
                ->orderBy('ends_at', 'desc')
                ->first();

            $this->line("  → Tenant #{$tenant->id} ({$tenant->name})"
                .($latestExpired ? " — was {$latestExpired->plan}, expired {$latestExpired->ends_at}" : ' — no previous subscription'));

            if (! $isDryRun) {
                Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan' => 'free',
                    'duration_months' => 0,
                    'price' => 0,
                    'status' => 'active',
                    'starts_at' => Carbon::now(),
                    'ends_at' => null,
                    'payment_provider' => 'internal',
                    'payment_reference' => null,
                    'metadata' => [
                        'backfilled' => true,
                        'backfilled_at' => Carbon::now()->toIso8601String(),
                        'downgraded_from' => $latestExpired?->plan,
                        'previous_subscription_id' => $latestExpired?->id,
                    ],
                ]);

                Log::info('Backfilled free subscription', [
                    'tenant_id' => $tenant->id,
                    'previous_plan' => $latestExpired?->plan,
                ]);
            }

            $backfilledCount++;
        }

        $this->newLine();
        $this->info("Scanned {$tenants->count()} tenants.");
        $this->info("Skipped {$skippedCount} (already have active subscription or trial).");

        if ($isDryRun) {
            $this->warn("Would backfill {$backfilledCount} tenants with free subscription.");
            $this->warn('Run without --dry-run to apply changes.');
        } else {
            $this->info("Backfilled {$backfilledCount} tenants with free subscription.");
        }

        return Command::SUCCESS;
    }
}
