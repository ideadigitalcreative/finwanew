<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Budget;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

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
            return "📊 *Insight Keuangan*\n\n" .
                   "Belum cukup data untuk analisis.\n" .
                   "Terus catat transaksi secara rutin untuk mendapat insight yang akurat! 💪";
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
                'count' => $txs->count()
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
        
        if (!$avgTransaction || $avgTransaction < 1000) {
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
        $result .= "_Lebih tinggi dari rata-rata Anda_";
        
        return $result;
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
        
        if (!$hasBudget) {
            $tips[] = "🎯 Belum ada budget? Set budget kategori utama dengan: _set budget makan 1jt_";
        }
        
        // Check spending trend
        $prediction = $this->predictEndOfMonthSpending();
        if ($prediction && str_contains($prediction, '⚠️')) {
            $tips[] = "💡 Gunakan fitur batch input untuk catat pengeluaran lebih cepat: kirim daftar belanja sekaligus!";
        }
        
        // Generic tips if no specific tips
        if (empty($tips)) {
            $genericTips = [
                "📸 Foto struk belanja untuk catat otomatis dengan OCR!",
                "📊 Ketik _cek statistik_ untuk lihat tren pengeluaran",
                "💳 Pisahkan dompet: Kebutuhan, Tabungan, Dana Darurat",
            ];
            $tips[] = $genericTips[array_rand($genericTips)];
        }
        
        return implode("\n", $tips);
    }
}
