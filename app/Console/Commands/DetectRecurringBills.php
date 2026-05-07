<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\RecurringBillDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DetectRecurringBills extends Command
{
    protected $signature = 'detect:recurring-bills {--test : Test mode - only show detections, don\'t create reminders} {--tenant= : Process specific tenant ID only}';

    protected $description = 'Analyze transaction patterns and auto-detect recurring bills';

    public function handle(): int
    {
        $this->info('Starting Recurring Bill Detection...');

        $tenants = Tenant::where('is_active', true);

        if ($tenantId = $this->option('tenant')) {
            $tenants = $tenants->where('id', $tenantId);
        }

        $tenants = $tenants->get();
        $this->info("Processing {$tenants->count()} tenants");

        $service = new RecurringBillDetectionService;
        $totalDetected = 0;
        $totalSkipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            try {
                $txCount = \App\Models\Transaction::where('tenant_id', $tenant->id)
                    ->where('type', 'expense')
                    ->where('transaction_date', '>=', now()->subMonths(3))
                    ->count();

                if ($txCount < 4) {
                    $totalSkipped++;

                    continue;
                }

                if ($this->option('test')) {
                    $this->info("Tenant {$tenant->id}: Analyzing...");
                    $patterns = $service->analyze($tenant->id);
                    if (! empty($patterns)) {
                        foreach ($patterns as $p) {
                            $this->info("  Detected: {$p['title']} ({$p['type']}, Rp ".number_format($p['amount'], 0, ',', '.').", {$p['confidence']})");
                        }
                        $totalDetected += count($patterns);
                    } else {
                        $this->info('  No recurring bills detected');
                    }

                    continue;
                }

                $created = $service->analyze($tenant->id);

                if (! empty($created)) {
                    $totalDetected += count($created);
                    $this->info("Tenant {$tenant->id}: ".count($created).' recurring bill(s) detected');
                }

            } catch (\Exception $e) {
                $errors++;
                Log::error('Recurring bill detection failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Error for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("\n=== Recurring Bill Detection Complete ===");
        $this->info("Detected: {$totalDetected}");
        $this->info("Skipped (insufficient data): {$totalSkipped}");
        $this->info("Errors: {$errors}");

        return Command::SUCCESS;
    }
}
