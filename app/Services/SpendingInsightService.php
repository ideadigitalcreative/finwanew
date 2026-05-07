<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;

class SpendingInsightService
{
    protected int $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Generate comprehensive spending insight report
     */
    public function generateInsightReport(): string
    {
        $insights = [];

        // 1. Spending Pattern by Day of Week
        $dayPattern = $this->analyzeByDayOfWeek();
        if ($dayPattern) {
            $insights[] = $dayPattern;
        }

        // 2. Budget Prediction
        $prediction = $this->predictEndOfMonthSpending();
        if ($prediction) {
            $insights[] = $prediction;
        }

        // 3. Top Categories This Month
        $topCategories = $this->getTopCategories();
        if ($topCategories) {
            $insights[] = $topCategories;
        }

        // 4. Anomaly Detection
        $anomalies = $this->detectAnomalies();
        if ($anomalies) {
            $insights[] = $anomalies;
        }

        if (empty($insights)) {
            return "📊 *Insight Keuangan*\n\n".
                   "Belum cukup data untuk analisis.\n".
                   'Terus catat transaksi secara rutin untuk mendapat insight yang akurat! 💪';
        }

        $message = "📊 *Insight Keuangan Anda*\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        $message .= implode("\n\n", $insights);

        return $message;
    }

    /**
     * Analyze spending by day of week
     */
    public function analyzeByDayOfWeek(): ?string
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $transactions = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $thirtyDaysAgo)
            ->get()
            ->groupBy(fn ($tx) => Carbon::parse($tx->transaction_date)->dayOfWeek);

        if ($transactions->count() < 3) {
            return null;
        }

        $dailyAvg = [];
        foreach ($transactions as $day => $txs) {
            $dailyAvg[$day] = [
                'total' => $txs->sum('amount'),
                'count' => $txs->count(),
            ];
        }

        // Find highest spending day
        $maxDay = null;
        $maxAmount = 0;
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        foreach ($dailyAvg as $day => $data) {
            if ($data['total'] > $maxAmount) {
                $maxAmount = $data['total'];
                $maxDay = $day;
            }
        }

        if ($maxDay === null) {
            return null;
        }

        $totalAll = array_sum(array_column($dailyAvg, 'total'));
        $avgPerDay = $totalAll / 7;
        $percentage = $avgPerDay > 0 ? (($maxAmount / 4) / $avgPerDay - 1) * 100 : 0;

        $dayName = $days[$maxDay] ?? 'Hari';
        $maxFormatted = number_format($maxAmount / 4, 0, ',', '.'); // weekly avg

        $result = "📅 *Pola Pengeluaran*\n";
        $result .= "Pengeluaran tertinggi: Hari *{$dayName}*\n";
        $result .= "Rata-rata: Rp {$maxFormatted}/minggu\n";

        if ($percentage > 30) {
            $result .= "💡 _Pertimbangkan batasi aktivitas di hari {$dayName}_";
        }

        return $result;
    }

    /**
     * Predict end of month spending based on current trend
     */
    public function predictEndOfMonthSpending(): ?string
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $currentDay = $now->day;
        $daysInMonth = $now->daysInMonth;
        $daysRemaining = $daysInMonth - $currentDay;

        // Need at least 7 days of data
        if ($currentDay < 7) {
            return null;
        }

        // Current month spending
        $currentSpending = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startOfMonth)
            ->sum('amount');

        // Daily average
        $dailyAvg = $currentSpending / $currentDay;

        // Predicted total
        $predictedTotal = $currentSpending + ($dailyAvg * $daysRemaining);

        // Get monthly budget (total all categories)
        $totalBudget = Budget::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->sum('amount');

        $predictedFormatted = number_format($predictedTotal, 0, ',', '.');
        $currentFormatted = number_format($currentSpending, 0, ',', '.');

        $result = "🔮 *Prediksi Bulan Ini*\n";
        $result .= "Terpakai: Rp {$currentFormatted} ({$currentDay} hari)\n";
        $result .= "Prediksi: Rp {$predictedFormatted}\n";

        if ($totalBudget > 0) {
            $budgetFormatted = number_format($totalBudget, 0, ',', '.');
            $percentage = ($predictedTotal / $totalBudget) * 100;

            if ($predictedTotal > $totalBudget) {
                $overAmount = $predictedTotal - $totalBudget;
                $overFormatted = number_format($overAmount, 0, ',', '.');
                $result .= "⚠️ Lebih Rp {$overFormatted} dari budget!\n";

                // Calculate needed daily reduction
                $neededReduction = $daysRemaining > 0 ? $overAmount / $daysRemaining : 0;
                $reductionFormatted = number_format($neededReduction, 0, ',', '.');
                $result .= "💡 _Kurangi Rp {$reductionFormatted}/hari_";
            } else {
                $result .= "✅ Masih dalam budget Rp {$budgetFormatted}";
            }
        }

        return $result;
    }

    /**
     * Get top spending categories this month
     */
    public function getTopCategories(): ?string
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $categories = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startOfMonth)
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->limit(3)
            ->with('category')
            ->get();

        if ($categories->isEmpty()) {
            return null;
        }

        $result = "📈 *Top Pengeluaran Bulan Ini*\n";
        $index = 1;
        foreach ($categories as $cat) {
            $name = $cat->category->name ?? 'Lainnya';
            $icon = $cat->category->icon ?? '📦';
            $amount = number_format($cat->total, 0, ',', '.');
            $result .= "{$index}. {$icon} {$name}: Rp {$amount}\n";
            $index++;
        }

        return $result;
    }

    /**
     * Detect unusual spending (anomalies)
     */
    public function detectAnomalies(): ?string
    {
        // Get last 7 days unusual transactions
        $sevenDaysAgo = Carbon::now()->subDays(7);
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Get 30-day average per transaction
        $avgTransaction = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $thirtyDaysAgo)
            ->where('transaction_date', '<', $sevenDaysAgo)
            ->avg('amount');

        if (! $avgTransaction || $avgTransaction < 1000) {
            return null;
        }

        // Find transactions more than 3x the average in last 7 days
        $threshold = $avgTransaction * 3;

        $anomalies = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $sevenDaysAgo)
            ->where('amount', '>', $threshold)
            ->orderByDesc('amount')
            ->limit(2)
            ->with('category')
            ->get();

        if ($anomalies->isEmpty()) {
            return null;
        }

        $result = "🔍 *Pengeluaran Tidak Biasa*\n";
        foreach ($anomalies as $tx) {
            $amount = number_format($tx->amount, 0, ',', '.');
            $category = $tx->category->name ?? 'Lainnya';
            $date = Carbon::parse($tx->transaction_date)->translatedFormat('d M');
            $result .= "⚡ Rp {$amount} - {$category} ({$date})\n";
        }
        $result .= '_Lebih tinggi dari rata-rata Anda_';

        return $result;
    }

    /**
     * Generate structured dashboard insights for QuickInsightCard
     */
    public function generateDashboardInsights(): array
    {
        $insights = [];

        $categoryChange = $this->getCategoryMonthOverMonthChange();
        if ($categoryChange) {
            $insights[] = $categoryChange;
        }

        $budgetInsight = $this->getBudgetProgressInsight();
        if ($budgetInsight) {
            $insights[] = $budgetInsight;
        }

        $dailyAvg = $this->getDailyAverageInsight();
        if ($dailyAvg) {
            $insights[] = $dailyAvg;
        }

        $anomaly = $this->getAnomalyInsight();
        if ($anomaly) {
            $insights[] = $anomaly;
        }

        $savingTip = $this->getSavingTipInsight();
        if ($savingTip) {
            $insights[] = $savingTip;
        }

        if (empty($insights)) {
            $insights[] = [
                'icon' => '💡',
                'type' => 'info',
                'title' => 'Mulai Catat Transaksi',
                'message' => 'Kirim transaksi via WhatsApp untuk mulai mendapat insight keuangan otomatis.',
                'priority' => 0,
            ];
        }

        usort($insights, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($insights, 0, 4);
    }

    protected function getCategoryMonthOverMonthChange(): ?array
    {
        $now = Carbon::now();
        $currentStart = $now->copy()->startOfMonth();
        $prevStart = $now->copy()->subMonth()->startOfMonth();
        $prevEnd = $now->copy()->subMonth()->endOfMonth();

        $currentCategories = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $currentStart)
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $prevCategories = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$prevStart, $prevEnd])
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        if ($currentCategories->isEmpty() || $prevCategories->isEmpty()) {
            return null;
        }

        $bestChange = null;
        $bestPercentage = 0;

        foreach ($currentCategories as $catId => $currentAmount) {
            $prevAmount = $prevCategories[$catId] ?? 0;
            if ($prevAmount < 10000) {
                continue;
            }

            $changePercent = (($currentAmount - $prevAmount) / $prevAmount) * 100;

            if (abs($changePercent) > abs($bestPercentage) && abs($changePercent) > 20) {
                $bestPercentage = $changePercent;
                $category = \App\Models\Category::find($catId);
                $bestChange = [
                    'category' => $category?->name ?? 'Lainnya',
                    'current' => (float) $currentAmount,
                    'previous' => (float) $prevAmount,
                    'percentage' => round($changePercent, 1),
                ];
            }
        }

        if (! $bestChange) {
            return null;
        }

        $isUp = $bestChange['percentage'] > 0;
        $formattedCurrent = 'Rp '.number_format($bestChange['current'], 0, ',', '.');
        $formattedPercent = abs($bestChange['percentage']);

        if ($isUp) {
            return [
                'icon' => '📈',
                'type' => 'warning',
                'title' => "Pengeluaran {$bestChange['category']} naik {$formattedPercent}%",
                'message' => "{$formattedCurrent} bulan ini vs bulan lalu. Periksa pengeluaran tidak terduga.",
                'priority' => 3,
            ];
        }

        return [
            'icon' => '📉',
            'type' => 'success',
            'title' => "Pengeluaran {$bestChange['category']} turun {$formattedPercent}%",
            'message' => "{$formattedCurrent} bulan ini. Pertahankan!",
            'priority' => 2,
        ];
    }

    protected function getBudgetProgressInsight(): ?array
    {
        $budgets = Budget::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->get();

        if ($budgets->isEmpty()) {
            return null;
        }

        $now = Carbon::now();
        $daysInMonth = $now->daysInMonth;
        $currentDay = $now->day;
        $monthProgress = ($currentDay / $daysInMonth) * 100;

        foreach ($budgets as $budget) {
            $spending = $budget->getCurrentSpending();
            $budgetAmount = $budget->amount;
            if ($budgetAmount <= 0) {
                continue;
            }

            $usagePercent = ($spending / $budgetAmount) * 100;
            $remaining = $budgetAmount - $spending;

            if ($usagePercent >= 100) {
                $overAmount = 'Rp '.number_format(abs($remaining), 0, ',', '.');
                $budgetCategoryName = $budget->category->name ?? '';

                return [
                    'icon' => '🚨',
                    'type' => 'danger',
                    'title' => "Budget {$budgetCategoryName} sudah habis",
                    'message' => "Melebihi {$overAmount}. Kurangi pengeluaran di kategori ini.",
                    'priority' => 5,
                ];
            }

            if ($usagePercent >= 80 && $monthProgress < 80) {
                $daysRemaining = $daysInMonth - $currentDay;
                $dailyLimit = $daysRemaining > 0 ? $remaining / $daysRemaining : 0;
                $formattedLimit = 'Rp '.number_format($dailyLimit, 0, ',', '.');
                $formattedRemaining = 'Rp '.number_format($remaining, 0, ',', '.');
                $budgetCategoryName = $budget->category->name ?? '';

                return [
                    'icon' => '⚠️',
                    'type' => 'warning',
                    'title' => "Budget {$budgetCategoryName} tinggal {$formattedRemaining}",
                    'message' => "Batas {$formattedLimit}/hari untuk {$daysRemaining} hari ke depan.",
                    'priority' => 4,
                ];
            }
        }

        return null;
    }

    protected function getDailyAverageInsight(): ?array
    {
        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $currentDay = $now->day;

        if ($currentDay < 3) {
            return null;
        }

        $totalExpense = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $startOfMonth)
            ->sum('amount');

        if ($totalExpense < 10000) {
            return null;
        }

        $dailyAvg = $totalExpense / $currentDay;
        $daysRemaining = $now->daysInMonth - $currentDay;

        $prevMonthExpense = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->subMonth()->endOfMonth(),
            ])
            ->sum('amount');

        $formattedDaily = 'Rp '.number_format($dailyAvg, 0, ',', '.');

        if ($prevMonthExpense > 0) {
            $prevDailyAvg = $prevMonthExpense / $now->copy()->subMonth()->daysInMonth;
            $changePercent = (($dailyAvg - $prevDailyAvg) / $prevDailyAvg) * 100;

            if ($changePercent > 20) {
                return [
                    'icon' => '💰',
                    'type' => 'warning',
                    'title' => "Rata-rata pengeluaran harian: {$formattedDaily}",
                    'message' => 'Naik '.round($changePercent).'% dari bulan lalu. '.$daysRemaining.' hari tersisa.',
                    'priority' => 2,
                ];
            }
        }

        return [
            'icon' => '💰',
            'type' => 'info',
            'title' => "Rata-rata pengeluaran harian: {$formattedDaily}",
            'message' => $daysRemaining.' hari tersisa di bulan ini.',
            'priority' => 1,
        ];
    }

    protected function getAnomalyInsight(): ?array
    {
        $sevenDaysAgo = Carbon::now()->subDays(7);
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $avgTransaction = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $thirtyDaysAgo)
            ->where('transaction_date', '<', $sevenDaysAgo)
            ->avg('amount');

        if (! $avgTransaction || $avgTransaction < 10000) {
            return null;
        }

        $anomaly = Transaction::where('tenant_id', $this->tenantId)
            ->where('type', 'expense')
            ->where('transaction_date', '>=', $sevenDaysAgo)
            ->where('amount', '>', $avgTransaction * 3)
            ->orderByDesc('amount')
            ->first();

        if (! $anomaly) {
            return null;
        }

        $formattedAmount = 'Rp '.number_format($anomaly->amount, 0, ',', '.');
        $category = $anomaly->category?->name ?? 'Lainnya';
        $date = Carbon::parse($anomaly->transaction_date)->translatedFormat('d M');

        return [
            'icon' => '🔍',
            'type' => 'warning',
            'title' => "Pengeluaran tidak biasa: {$formattedAmount}",
            'message' => "{$category} tanggal {$date} — lebih tinggi dari rata-rata Anda.",
            'priority' => 2,
        ];
    }

    protected function getSavingTipInsight(): ?array
    {
        $hasBudget = Budget::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $hasBudget) {
            return [
                'icon' => '🎯',
                'type' => 'info',
                'title' => 'Belum ada budget aktif',
                'message' => 'Set budget kategori utama untuk kontrol pengeluaran lebih baik.',
                'priority' => 1,
            ];
        }

        return null;
    }

    /**
     * Get personalized financial tips based on spending pattern
     */
    public function getPersonalizedTips(): string
    {
        $tips = [];

        // Check if user has budget
        $hasBudget = Budget::where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->exists();

        if (! $hasBudget) {
            $tips[] = '🎯 Belum ada budget? Set budget kategori utama dengan: _set budget makan 1jt_';
        }

        // Check spending trend
        $prediction = $this->predictEndOfMonthSpending();
        if ($prediction && str_contains($prediction, '⚠️')) {
            $tips[] = '💡 Gunakan fitur batch input untuk catat pengeluaran lebih cepat: kirim daftar belanja sekaligus!';
        }

        // Generic tips if no specific tips
        if (empty($tips)) {
            $genericTips = [
                '📸 Foto struk belanja untuk catat otomatis dengan OCR!',
                '📊 Ketik _cek statistik_ untuk lihat tren pengeluaran',
                '💳 Pisahkan dompet: Kebutuhan, Tabungan, Dana Darurat',
            ];
            $tips[] = $genericTips[array_rand($genericTips)];
        }

        return implode("\n", $tips);
    }
}
