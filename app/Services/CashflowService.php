<?php

namespace App\Services;

use App\Models\Cashflow;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashflowService
{
    /**
     * Calculate and persist cashflow for a tenant and period.
     * Excludes debit_internal/kredit_internal from income/expense totals.
     * Also excludes transactions with non-null linked_transaction_id.
     * Updates existing record if one exists for the same period.
     *
     * @throws \InvalidArgumentException
     */
    public function calculateForPeriod(
        int $tenantId,
        string $periodType,
        ?Carbon $referenceDate = null
    ): Cashflow {
        if (!in_array($periodType, ['daily', 'weekly', 'monthly'])) {
            throw new \InvalidArgumentException(
                "Invalid period_type '{$periodType}'. Valid values are: daily, weekly, monthly."
            );
        }

        $referenceDate = $referenceDate ?? Carbon::today();

        [$periodStart, $periodEnd] = $this->calculatePeriodBoundaries($periodType, $referenceDate);

        // Query confirmed transactions for the period, excluding internal transfers
        $transactions = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereNotIn('type', ['debit_internal', 'kredit_internal'])
            ->whereNull('linked_transaction_id')
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->get();

        // Calculate totals
        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netCashflow = $totalIncome - $totalExpense;

        // Generate category breakdown
        $breakdown = $this->generateBreakdown($tenantId, $periodStart, $periodEnd);

        // Persist using updateOrCreate keyed on (tenant_id, period_start, period_end)
        return Cashflow::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ],
            [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_cashflow' => $netCashflow,
                'breakdown' => $breakdown,
            ]
        );
    }

    /**
     * Calculate period start and end dates based on period type and reference date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function calculatePeriodBoundaries(string $periodType, Carbon $referenceDate): array
    {
        return match ($periodType) {
            'daily' => [
                $referenceDate->copy()->startOfDay(),
                $referenceDate->copy()->endOfDay(),
            ],
            'weekly' => [
                $referenceDate->copy()->startOfWeek(Carbon::MONDAY),
                $referenceDate->copy()->endOfWeek(Carbon::SUNDAY),
            ],
            'monthly' => [
                $referenceDate->copy()->startOfMonth(),
                $referenceDate->copy()->endOfMonth(),
            ],
            default => throw new \InvalidArgumentException(
                "Invalid period_type '{$periodType}'. Valid values are: daily, weekly, monthly."
            ),
        };
    }

    /**
     * Get cashflow data formatted for the dashboard response.
     * Includes current period, previous period comparison, percentage changes, and health status.
     */
    public function getDashboardData(int $tenantId): array
    {
        $now = Carbon::now();

        // Calculate current month cashflow
        $currentCashflow = $this->calculateForPeriod($tenantId, 'monthly', $now);

        // Calculate previous month cashflow
        $prevMonth = $now->copy()->subMonth();
        $prevCashflow = $this->calculateForPeriod($tenantId, 'monthly', $prevMonth);

        $totalIncome = (float) $currentCashflow->total_income;
        $totalExpense = (float) $currentCashflow->total_expense;
        $netCashflow = (float) $currentCashflow->net_cashflow;

        $prevTotalIncome = (float) $prevCashflow->total_income;
        $prevTotalExpense = (float) $prevCashflow->total_expense;
        $prevNetCashflow = (float) $prevCashflow->net_cashflow;

        // Calculate percentage changes (capped at ±999%)
        $incomeChange = $this->calculatePercentageChange($totalIncome, $prevTotalIncome);
        $expenseChange = $this->calculatePercentageChange($totalExpense, $prevTotalExpense);
        $netChange = $this->calculateNetPercentageChange($netCashflow, $prevNetCashflow);

        // Determine health status
        $healthStatus = $this->determineHealthStatus($netCashflow, $prevNetCashflow);

        // Calculate internal transfer volume (sum of debit_internal amounts for current month)
        $periodStart = $now->copy()->startOfMonth();
        $periodEnd = $now->copy()->endOfMonth();

        $internalTransferVolume = (float) Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->where('type', 'debit_internal')
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->sum('amount');

        return [
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_cashflow' => $netCashflow,
            'period_start' => $currentCashflow->period_start,
            'period_end' => $currentCashflow->period_end,
            'income_change' => round($incomeChange, 1),
            'expense_change' => round($expenseChange, 1),
            'net_change' => round($netChange, 1),
            'health_status' => $healthStatus,
            'prev_total_income' => $prevTotalIncome,
            'prev_total_expense' => $prevTotalExpense,
            'prev_net_cashflow' => $prevNetCashflow,
            'internal_transfer_volume' => $internalTransferVolume,
        ];
    }

    /**
     * Get chart data (monthly income/expense/net) for the last N months.
     * Calculates on-demand if Cashflow records don't exist for historical periods.
     */
    public function getChartData(int $tenantId, int $months = 6): array
    {
        $now = Carbon::now();
        $data = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i);
            $periodStart = $date->copy()->startOfMonth();
            $periodEnd = $date->copy()->endOfMonth();

            // Try to load from cashflows table first
            $cashflow = Cashflow::where('tenant_id', $tenantId)
                ->where('period_start', $periodStart->toDateString())
                ->where('period_end', $periodEnd->toDateString())
                ->first();

            // If not found, calculate on-demand (this also persists it)
            if (!$cashflow) {
                $cashflow = $this->calculateForPeriod($tenantId, 'monthly', $date);
            }

            $data[] = [
                'period' => $date->format('M Y'),
                'income' => (float) $cashflow->total_income,
                'expense' => (float) $cashflow->total_expense,
                'net' => (float) $cashflow->net_cashflow,
            ];
        }

        return $data;
    }

    /**
     * Calculate percentage change between current and previous values.
     * Capped at ±999%. Returns 0 if previous is zero and current is also zero.
     */
    private function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 999.0 : 0.0;
        }

        $change = (($current - $previous) / $previous) * 100;

        return max(-999.0, min(999.0, $change));
    }

    /**
     * Calculate net cashflow percentage change, handling negative previous values.
     * Capped at ±999%.
     */
    private function calculateNetPercentageChange(float $current, float $previous): float
    {
        if ($previous == 0) {
            if ($current == 0) {
                return 0.0;
            }
            return $current > 0 ? 999.0 : -999.0;
        }

        $change = (($current - $previous) / abs($previous)) * 100;

        return max(-999.0, min(999.0, $change));
    }

    /**
     * Determine financial health status based on current vs previous net cashflow.
     * 'growing' when net > prev * 1.1, 'declining' when net < prev * 0.9, 'stable' otherwise.
     */
    private function determineHealthStatus(float $currentNet, float $previousNet): string
    {
        if ($currentNet > $previousNet * 1.1) {
            return 'growing';
        }

        if ($currentNet < $previousNet * 0.9) {
            return 'declining';
        }

        return 'stable';
    }

    /**
     * Query cashflow records filtered by tenant, optionally by date range.
     * Results ordered by period_start descending.
     */
    public function query(int $tenantId, ?string $periodType = null, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = Cashflow::where('tenant_id', $tenantId);

        if ($from) {
            $query->where('period_start', '>=', $from);
        }

        if ($to) {
            $query->where('period_end', '<=', $to);
        }

        return $query->orderBy('period_start', 'desc')->get();
    }

    /**
     * Format a monthly summary message for WhatsApp notification.
     * Returns a WhatsApp-friendly message in Indonesian with emojis.
     */
    public function formatMonthlySummary(int $tenantId, Carbon $month): string
    {
        $periodStart = $month->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth();

        // Get or calculate cashflow for this month
        $cashflow = Cashflow::where('tenant_id', $tenantId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();

        if (!$cashflow) {
            $cashflow = $this->calculateForPeriod($tenantId, 'monthly', $month);
        }

        // Get previous month cashflow for comparison
        $prevMonth = $month->copy()->subMonth();
        $prevCashflow = Cashflow::where('tenant_id', $tenantId)
            ->where('period_start', $prevMonth->copy()->startOfMonth())
            ->where('period_end', $prevMonth->copy()->endOfMonth())
            ->first();

        if (!$prevCashflow) {
            $prevCashflow = $this->calculateForPeriod($tenantId, 'monthly', $prevMonth);
        }

        $totalIncome = (float) $cashflow->total_income;
        $totalExpense = (float) $cashflow->total_expense;
        $netCashflow = (float) $cashflow->net_cashflow;

        $prevTotalExpense = (float) $prevCashflow->total_expense;

        // Format month name in Indonesian
        $monthName = $this->getIndonesianMonthName($month->month);
        $year = $month->year;

        // Build message
        $lines = [];
        $lines[] = "📊 *Ringkasan Keuangan Bulan {$monthName} {$year}*";
        $lines[] = '━━━━━━━━━━━━━━━';
        $lines[] = '💰 Pemasukan: ' . $this->formatRupiah($totalIncome);
        $lines[] = '💸 Pengeluaran: ' . $this->formatRupiah($totalExpense);

        $netSign = $netCashflow >= 0 ? '+' : '-';
        $lines[] = ($netCashflow >= 0 ? '📈' : '📉') . ' Net: ' . $netSign . $this->formatRupiah(abs($netCashflow));

        // Top 3 expense categories from breakdown
        $breakdown = $cashflow->breakdown;
        $expenseCategories = $breakdown['expense'] ?? [];

        if (!empty($expenseCategories)) {
            // Sort by amount descending
            usort($expenseCategories, fn ($a, $b) => $b['amount'] <=> $a['amount']);
            $top3 = array_slice($expenseCategories, 0, 3);

            $lines[] = '';
            $lines[] = '📋 *Top Pengeluaran:*';

            $categoryEmojis = ['🍽️', '🚗', '⚡', '🏠', '🎮', '👕', '📱', '💊', '📚', '🎁'];
            foreach ($top3 as $index => $category) {
                $emoji = $categoryEmojis[$index] ?? '•';
                $num = $index + 1;
                $lines[] = "{$num}. {$emoji} {$category['name']} — " . $this->formatRupiah($category['amount']);
            }
        }

        // Comparison with previous month
        $lines[] = '';
        if ($prevTotalExpense > 0) {
            $changePercent = (($totalExpense - $prevTotalExpense) / $prevTotalExpense) * 100;
            $changePercent = round($changePercent);

            if ($changePercent > 0) {
                $lines[] = "📊 vs bulan lalu: Pengeluaran naik {$changePercent}%";
            } elseif ($changePercent < 0) {
                $lines[] = '📊 vs bulan lalu: Pengeluaran turun ' . abs($changePercent) . '%';
            } else {
                $lines[] = '📊 vs bulan lalu: Pengeluaran sama';
            }
        } else {
            $lines[] = '📊 vs bulan lalu: Belum ada data perbandingan';
        }

        return implode("\n", $lines);
    }

    /**
     * Format a number as Indonesian Rupiah (e.g., Rp 1.500.000).
     */
    private function formatRupiah(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
    }

    /**
     * Get Indonesian month name.
     */
    private function getIndonesianMonthName(int $month): string
    {
        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        return $months[$month] ?? '';
    }

    /**
     * Generate category breakdown JSON with separate internal_transfers section.
     */
    private function generateBreakdown(int $tenantId, Carbon $periodStart, Carbon $periodEnd): array
    {
        // Get income transactions grouped by category
        $incomeByCategory = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->where('type', 'income')
            ->whereNull('linked_transaction_id')
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(function (Collection $transactions, $categoryId) {
                $category = $transactions->first()->category;
                return [
                    'category_id' => (int) $categoryId,
                    'name' => $category?->name ?? 'Tidak Berkategori',
                    'amount' => (float) $transactions->sum('amount'),
                ];
            })
            ->values()
            ->toArray();

        // Get expense transactions grouped by category
        $expenseByCategory = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->where('type', 'expense')
            ->whereNull('linked_transaction_id')
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(function (Collection $transactions, $categoryId) {
                $category = $transactions->first()->category;
                return [
                    'category_id' => (int) $categoryId,
                    'name' => $category?->name ?? 'Tidak Berkategori',
                    'amount' => (float) $transactions->sum('amount'),
                ];
            })
            ->values()
            ->toArray();

        // Get internal transfer totals
        $internalTransfers = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereIn('type', ['debit_internal', 'kredit_internal'])
            ->whereBetween('transaction_date', [$periodStart, $periodEnd])
            ->get();

        $totalDebit = (float) $internalTransfers->where('type', 'debit_internal')->sum('amount');
        $totalCredit = (float) $internalTransfers->where('type', 'kredit_internal')->sum('amount');
        $count = $internalTransfers->count();

        return [
            'expense' => $expenseByCategory,
            'income' => $incomeByCategory,
            'internal_transfers' => [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'count' => $count,
            ],
        ];
    }
}
