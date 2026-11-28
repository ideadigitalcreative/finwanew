<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Console\Command;

class FixCategoryMetadata extends Command
{
    protected $signature = 'categories:fix-metadata {--tenant= : Fix untuk tenant tertentu saja} {--dry-run : Hanya tampilkan tanpa menulis DB}';

    protected $description = 'Perbaiki metadata kategori yang salah (name, icon) untuk semua tenant';

    private const EXPECTED_METADATA = [
        'pendapatan_gaji' => ['name' => 'Gaji', 'icon' => '💰'],
        'pengeluaran_gaji' => ['name' => 'Gaji Karyawan', 'icon' => '👷'],
    ];

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::cursor();

        $fixed = 0;
        $skipped = 0;
        $errors = 0;

        $bar = $this->output->createProgressBar($tenantId ? 1 : Tenant::count());
        $bar->start();

        foreach ($tenants as $tenant) {
            try {
                foreach (self::EXPECTED_METADATA as $type => $expected) {
                    $category = Category::where('tenant_id', $tenant->id)
                        ->where('type', $type)
                        ->first();

                    if (! $category) {
                        $skipped++;
                        continue;
                    }

                    if ($category->name === $expected['name'] && $category->icon === $expected['icon']) {
                        $skipped++;
                        continue;
                    }

                    $this->newLine();
                    $this->info("Tenant {$tenant->id}: {$type}");
                    $this->info("  Old: {$category->name} ({$category->icon})");
                    $this->info("  New: {$expected['name']} ({$expected['icon']})");

                    if (! $dryRun) {
                        $category->update([
                            'name' => $expected['name'],
                            'icon' => $expected['icon'],
                            'slug' => str($expected['name'])->slug()->append('-', time())->toString(),
                        ]);
                        $this->info("  ✓ Fixed!");
                    } else {
                        $this->info("  [DRY RUN] Would fix");
                    }

                    $fixed++;
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
            $this->info("[DRY RUN] Kategori yang perlu diperbaiki: {$fixed}");
        } else {
            $this->info("Selesai! Fixed: {$fixed}, Skipped: {$skipped}, Errors: {$errors}");
        }

        return self::SUCCESS;
    }
}
