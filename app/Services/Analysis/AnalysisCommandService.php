<?php

namespace App\Services\Analysis;

use App\Models\Message;
use App\Models\Transaction;
use App\Services\FinWaAIService;
use Illuminate\Support\Facades\Log;

/**
 * AnalysisCommandService - Handles analysis and statistics commands
 */
class AnalysisCommandService
{
    protected Message $message;
    protected $sendReplyCallback;
    protected FinWaAIService $finwaService;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
        // Instantiate FinWaAIService (or inject if possible, but for now instantiate as per original code)
        // Original code: $finwaService = new FinWaAIService();
        $this->finwaService = new FinWaAIService();
    }

    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Handle check statistics request (cek statistik)
     */
    public function handleCheckStatistics(): void
    {
        try {
            $reply = "📈 *Statistik Keuangan Bulan Ini*\n";
            $reply .= "━━━━━━━━━━━━━━━\n\n";
            
            $startOfMonth = now()->startOfMonth();
            $endOfMonth = now()->endOfMonth();
            
            // Get transactions this month
            $transactions = Transaction::where('tenant_id', $this->message->tenant_id)
                ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
                ->with('category')
                ->get();
            
            $totalIncome = $transactions->where('type', 'income')->sum('amount');
            $totalExpense = $transactions->where('type', 'expense')->sum('amount');
            $netFlow = $totalIncome - $totalExpense;
            
            // Overview
            $reply .= "💰 *Pemasukan*: Rp " . number_format($totalIncome, 0, ',', '.') . "\n";
            $reply .= "💸 *Pengeluaran*: Rp " . number_format($totalExpense, 0, ',', '.') . "\n";
            $netEmoji = $netFlow >= 0 ? '📈' : '📉';
            $netLabel = $netFlow >= 0 ? 'Surplus' : 'Defisit';
            $reply .= "{$netEmoji} *{$netLabel}*: Rp " . number_format(abs($netFlow), 0, ',', '.') . "\n\n";
            
            // Top 3 expense categories
            $reply .= "🏆 *Top 3 Pengeluaran*\n";
            $expensesByCategory = $transactions
                ->where('type', 'expense')
                ->groupBy('category_id')
                ->map(fn($items) => [
                    'name' => $items->first()->category->name ?? 'Lainnya',
                    'total' => $items->sum('amount')
                ])
                ->sortByDesc('total')
                ->take(3)
                ->values();
            
            $rank = 1;
            foreach ($expensesByCategory as $cat) {
                $medal = match($rank) {
                    1 => '🥇',
                    2 => '🥈',
                    3 => '🥉',
                    default => '•'
                };
                $reply .= "{$medal} {$cat['name']}: Rp " . number_format($cat['total'], 0, ',', '.') . "\n";
                $rank++;
            }
            
            if ($expensesByCategory->isEmpty()) {
                $reply .= "Belum ada data pengeluaran.\n";
            }
            
            // Average daily spending
            $daysInMonth = now()->day;
            $avgDaily = $daysInMonth > 0 ? $totalExpense / $daysInMonth : 0;
            $reply .= "\n📅 *Rata-rata harian*: Rp " . number_format($avgDaily, 0, ',', '.') . "\n";
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error checking statistics', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            
            $this->sendReply(
                "⚠️ *Gagal memuat statistik*\n\n" .
                "Terjadi kesalahan. Silakan coba lagi nanti."
            );
        }
    }

    /**
     * Handle check statistics request with AI Insight
     */
    public function handleCheckStatisticsWithAI(): void
    {
        try {
            $tenantId = $this->message->tenant_id;
            $now = now();
            $lastMonth = now()->subMonth();
            
            // Current Month Data
            $currIncome = (float) Transaction::where('tenant_id', $tenantId)
                ->where('type', 'income')
                ->whereMonth('transaction_date', $now->month)
                ->whereYear('transaction_date', $now->year)
                ->sum('amount');
                
            $currExpense = (float) Transaction::where('tenant_id', $tenantId)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $now->month)
                ->whereYear('transaction_date', $now->year)
                ->sum('amount');
                
            // Last Month Data
            $lastIncome = (float) Transaction::where('tenant_id', $tenantId)
                ->where('type', 'income')
                ->whereMonth('transaction_date', $lastMonth->month)
                ->whereYear('transaction_date', $lastMonth->year)
                ->sum('amount');
                
            $lastExpense = (float) Transaction::where('tenant_id', $tenantId)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $lastMonth->month)
                ->whereYear('transaction_date', $lastMonth->year)
                ->sum('amount');
                
            // Top Categories (Current Month)
            $topCats = Transaction::where('tenant_id', $tenantId)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $now->month)
                ->whereYear('transaction_date', $now->year)
                ->selectRaw('category_id, sum(amount) as total')
                ->groupBy('category_id')
                ->orderByDesc('total')
                ->limit(3)
                ->with('category') // Eager load category name
                ->get();
                
            $categoriesData = [];
            foreach ($topCats as $cat) {
                 $name = $cat->category ? $cat->category->name : 'Uncategorized';
                 $name = ucwords(str_replace('_', ' ', $name));
                 
                 // Get last month category amount for diff comparison
                 $lastCatAmount = (float) Transaction::where('tenant_id', $tenantId)
                    ->where('type', 'expense')
                    ->where('category_id', $cat->category_id)
                    ->whereMonth('transaction_date', $lastMonth->month)
                    ->whereYear('transaction_date', $lastMonth->year)
                    ->sum('amount');
                 
                 $catTotal = (float) $cat->total;
                 $diffPct = 0;
                 if ($lastCatAmount > 0) {
                     $diffPct = (($catTotal - $lastCatAmount) / $lastCatAmount) * 100;
                 }
                 
                 $categoriesData[] = [
                     'name' => $name,
                     'amount' => $catTotal,
                     'diff_pct' => $diffPct
                 ];
            }
            
            // Prepare Data for AI
            $aiData = [
                'current_month' => ['income' => $currIncome, 'expense' => $currExpense],
                'last_month' => ['income' => $lastIncome, 'expense' => $lastExpense],
                'top_categories' => $categoriesData
            ];
            
            // Generate Report
            $insight = $this->finwaService->generateReport($aiData);
            
            // Build Reply
            $monthName = $now->translatedFormat('F Y');
            $reply = "📊 *Laporan Keuangan - {$monthName}*\n\n";
            $reply .= "💰 Pemasukan: Rp " . number_format($currIncome, 0, ',', '.') . "\n";
            $reply .= "💸 Pengeluaran: Rp " . number_format($currExpense, 0, ',', '.') . "\n";
            $reply .= "⚖️ Cashflow: Rp " . number_format($currIncome - $currExpense, 0, ',', '.') . "\n\n";
            
            // Explicitly show Top Categories
            if (!empty($categoriesData)) {
                $reply .= "🏆 *Pengeluaran Terbesar:*\n";
                foreach ($categoriesData as $idx => $cat) {
                    $medal = match($idx) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '•' };
                    $reply .= "{$medal} {$cat['name']}: Rp " . number_format($cat['amount'], 0, ',', '.') . "\n";
                }
                $reply .= "\n";
            }
            
            if ($insight) {
                $reply .= "\n📝 *Analisis AI:*\n{$insight}";
            } else {
                $reply .= "\n⚠️ _Analisis AI belum tersedia_";
            }
            
            $this->sendReply($reply);
            
        } catch (\Exception $e) {
            Log::error('Error checking stats', ['error' => $e->getMessage()]);
            $this->sendReply("⚠️ Maaf, gagal mengambil data statistik saat ini.");
        }
    }
}
