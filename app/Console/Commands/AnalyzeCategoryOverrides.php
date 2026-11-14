<?php

namespace App\Console\Commands;

use App\Models\CategoryCorrection;
use Illuminate\Console\Command;

class AnalyzeCategoryOverrides extends Command
{
    protected $signature = 'category:analyze-overrides 
        {--month= : Filter by month (YYYY-MM format, default: current month)}
        {--tenant= : Filter by specific tenant ID}
        {--min-frequency=2 : Minimum frequency to show}
        {--export : Export results to CSV}';

    protected $description = 'Analyze user category overrides from feedback loop and suggest keyword improvements';

    public function handle(): int
    {
        $month = $this->option('month') ?? now()->format('Y-m');
        $tenantId = $this->option('tenant');
        $minFrequency = (int) $this->option('min-frequency');

        $this->newLine();
        $this->info("📊 Analisis Category Overrides — {$month}");
        $this->line('─'.str_repeat('─', 60));

        $query = CategoryCorrection::query()
            ->where('frequency', '>=', $minFrequency);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $startDate = \Carbon\Carbon::parse($month.'-01')->startOfMonth();
        $endDate = (clone $startDate)->endOfMonth();
        $query->whereBetween('created_at', [$startDate, $endDate]);

        $corrections = $query->orderByDesc('frequency')->get();

        if ($corrections->isEmpty()) {
            $this->warn("Tidak ada data override untuk periode {$month}.");
            $this->line('Tip: Jalankan tanpa --month untuk melihat semua data.');

            return self::SUCCESS;
        }

        // ── Summary ──
        $this->newLine();
        $this->info('📈 Ringkasan:');
        $this->line("  Total unique corrections: {$corrections->count()}");
        $this->line("  Total frequency: {$corrections->sum('frequency')}");
        $this->newLine();

        // ── High Frequency Overrides ──
        $highFreq = $corrections->filter(fn ($c) => $c->frequency >= 5);
        if ($highFreq->isNotEmpty()) {
            $this->warn('🔴 Override Sering Terjadi (≥5x):');
            $this->line('');
            $this->table(
                ['Deskripsi', 'Dari Kategori', 'Ke Kategori', 'Frekuensi', 'Saran'],
                $highFreq->map(fn ($c) => [
                    mb_substr($c->original_text, 0, 35),
                    $this->shortCategory($c->original_category),
                    $this->shortCategory($c->corrected_category),
                    $c->frequency.'x',
                    $c->frequency >= 10 ? '⚠️ UBAH CONFIG' : '📌 PANTAU',
                ])->toArray()
            );
        }

        // ── Medium Frequency ──
        $medFreq = $corrections->filter(fn ($c) => $c->frequency >= 2 && $c->frequency < 5);
        if ($medFreq->isNotEmpty()) {
            $this->info('🟡 Override Sedang (2-4x):');
            $this->line('');
            $this->table(
                ['Deskripsi', 'Dari Kategori', 'Ke Kategori', 'Frekuensi'],
                $medFreq->map(fn ($c) => [
                    mb_substr($c->original_text, 0, 35),
                    $this->shortCategory($c->original_category),
                    $this->shortCategory($c->corrected_category),
                    $c->frequency.'x',
                ])->toArray()
            );
        }

        // ── Category Flow Analysis ──
        $this->newLine();
        $this->info('🔄 Alur Perubahan Kategori:');
        $this->line('');

        $flows = $corrections->groupBy(fn ($c) => $c->original_category.' → '.$c->corrected_category);
        $flowData = $flows->map(fn ($group, $flow) => [
            'flow' => $flow,
            'count' => $group->sum('frequency'),
            'unique_texts' => $group->count(),
        ])->sortByDesc('count')->take(15);

        $this->table(
            ['Alur', 'Total Override', 'Unique Texts'],
            $flowData->map(fn ($f) => [
                $f['flow'],
                $f['count'],
                $f['unique_texts'],
            ])->toArray()
        );

        // ── Recommendations ──
        $this->newLine();
        $this->info('💡 Rekomendasi Perubahan Config:');
        $this->line('');

        $recommendations = $this->generateRecommendations($corrections);
        foreach ($recommendations as $i => $rec) {
            $this->line("  ".($i + 1).". {$rec}");
        }

        // ── Export ──
        if ($this->option('export')) {
            $filename = "category_overrides_{$month}.csv";
            $path = storage_path("app/{$filename}");
            $handle = fopen($path, 'w');
            fputcsv($handle, ['Original Text', 'Original Category', 'Corrected Category', 'Merchant', 'Amount', 'Frequency', 'Created At']);
            foreach ($corrections as $c) {
                fputcsv($handle, [
                    $c->original_text,
                    $c->original_category,
                    $c->corrected_category,
                    $c->merchant,
                    $c->amount,
                    $c->frequency,
                    $c->created_at,
                ]);
            }
            fclose($handle);
            $this->newLine();
            $this->info("📁 Exported to: {$path}");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    protected function shortCategory(string $category): string
    {
        return str_replace(['pengeluaran_', 'pendapatan_'], ['exp_', 'inc_'], $category);
    }

    protected function generateRecommendations($corrections): array
    {
        $recommendations = [];

        $highFreq = $corrections->filter(fn ($c) => $c->frequency >= 5);

        foreach ($highFreq as $c) {
            $text = mb_strtolower(trim($c->original_text));
            $from = $c->original_category;
            $to = $c->corrected_category;
            $freq = $c->frequency;

            if ($freq >= 10) {
                $recommendations[] = "PINDAH keyword '{$text}' dari {$this->shortCategory($from)} ke {$this->shortCategory($to)} ({$freq}x override)";
            } elseif ($freq >= 5) {
                $recommendations[] = "PANTAU keyword '{$text}' → {$this->shortCategory($to)} ({$freq}x, pertimbangkan pindah)";
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Belum ada rekomendasi — data override belum cukup.';
            $recommendations[] = 'Jalankan lagi setelah 1-2 minggu penggunaan.';
        }

        return $recommendations;
    }
}
