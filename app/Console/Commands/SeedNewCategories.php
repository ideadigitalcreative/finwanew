<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\Category\CategoryManagerService;
use Illuminate\Console\Command;

class SeedNewCategories extends Command
{
    protected $signature = 'categories:seed-new {--tenant= : Seed untuk tenant tertentu saja} {--dry-run : Hanya tampilkan tanpa menulis DB}';

    protected $description = 'Seed kategori baru ke semua tenant existing (pakaian, acara, perawatan_diri, otomotif, sosial, hadiah, usaha, sewa, refund)';

    private array $newCategoryTypes = [
        'pengeluaran_pakaian',
        'pengeluaran_perawatan_diri',
        'pengeluaran_acara',
        'pengeluaran_otomotif',
        'pengeluaran_sosial',
        'pengeluaran_hadiah',
        'pendapatan_usaha',
        'pendapatan_sewa',
        'pendapatan_refund',
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::cursor();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($tenantId ? 1 : Tenant::count());
        $bar->start();

        foreach ($tenants as $tenant) {
            try {
                foreach ($this->newCategoryTypes as $type) {
                    $exists = Category::where('tenant_id', $tenant->id)
                        ->where('type', $type)
                        ->exists();

                    if ($exists) {
                        $skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        app(CategoryManagerService::class)->createCategoriesForTenant($tenant->id);
                    }
                    $created++;
                    break;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info("[DRY RUN] Tenant yang perlu update kategori baru: ~{$created}");
        } else {
            $this->info("Selesai! Tenant diproses: {$created}, Skip (sudah ada): {$skipped}, Error: {$errors}");
        }

        return self::SUCCESS;
    }
}
