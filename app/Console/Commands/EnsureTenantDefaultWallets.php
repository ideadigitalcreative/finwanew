<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class EnsureTenantDefaultWallets extends Command
{
    protected $signature = 'tenants:ensure-default-wallets
                            {--dry-run : Hanya tampilkan tenant_id yang perlu perbaikan, tanpa menulis DB}';

    protected $description = 'Pastikan setiap tenant punya dompet aktif default (Dompet Utama bila belum ada)';

    public function handle(TenantProvisioningService $provisioning): int
    {
        $needsWork = [];

        foreach (Tenant::query()->cursor() as $tenant) {
            if ($provisioning->tenantNeedsDefaultWalletProvisioning($tenant->id)) {
                $needsWork[] = $tenant->id;
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Tenant yang perlu provisioning: '.count($needsWork));
            foreach ($needsWork as $id) {
                $this->line((string) $id);
            }

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($needsWork));
        $bar->start();

        foreach ($needsWork as $tenantId) {
            $provisioning->ensureDefaultWallet($tenantId, 'artisan_tenants_ensure_default_wallets');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Selesai. Tenant diperbaiki: '.count($needsWork));

        return self::SUCCESS;
    }
}
