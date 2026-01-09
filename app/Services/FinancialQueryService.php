<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Balance;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FinancialQueryService
{
    protected $tenantId;
    
    /**
     * Answer financial question
     */
    public function answerQuestion(int $tenantId, string $question): array
    {
        try {
            $this->tenantId = $tenantId;
            
            // Parse question to determine what data is needed
            $questionLower = strtolower($question);
            
            // Determine period (bulan ini, minggu ini, hari ini, dll)
            $period = $this->extractPeriod($questionLower);
            
            // Determine what data is requested
            $requestedData = $this->extractRequestedData($questionLower);
            
            // Get financial data
            $categoryFilter = $requestedData['category_name'] ?? null;
            $data = $this->getFinancialData($tenantId, $period['start'], $period['end'], $categoryFilter);
            
            // Generate answer
            $answer = $this->generateAnswer($requestedData, $data, $period);
            
            return [
                'success' => true,
                'answer' => $answer,
                'data' => $data
            ];
        } catch (\Exception $e) {
            Log::error('Error answering financial question', [
                'tenant_id' => $tenantId,
                'question' => $question,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get tenant ID
     */
    protected function getTenantId(): int
    {
        return $this->tenantId;
    }
    
    /**
     * Extract period from question
     */
    protected function extractPeriod(string $question): array
    {
        $now = Carbon::now();
        
        if (str_contains($question, 'hari ini') || str_contains($question, 'hari ini')) {
            return [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'hari ini'
            ];
        }
        
        if (str_contains($question, 'minggu ini') || str_contains($question, 'minggu ini')) {
            return [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
                'label' => 'minggu ini'
            ];
        }
        
        if (str_contains($question, 'bulan ini') || str_contains($question, 'bulan ini')) {
            return [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'label' => 'bulan ini'
            ];
        }
        
        if (str_contains($question, 'tahun ini') || str_contains($question, 'tahun ini')) {
            return [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
                'label' => 'tahun ini'
            ];
        }
        
        // Minggu lalu
        if (str_contains($question, 'minggu lalu')) {
            return [
                'start' => $now->copy()->subWeek()->startOfWeek(),
                'end' => $now->copy()->subWeek()->endOfWeek(),
                'label' => 'minggu lalu'
            ];
        }
        
        // Kemarin
        if (str_contains($question, 'kemarin')) {
            return [
                'start' => $now->copy()->subDay()->startOfDay(),
                'end' => $now->copy()->subDay()->endOfDay(),
                'label' => 'kemarin'
            ];
        }
        
        // Bulan lalu
        if (str_contains($question, 'bulan lalu')) {
            return [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
                'label' => 'bulan lalu'
            ];
        }
        
        // Default: bulan ini
        return [
            'start' => $now->copy()->startOfMonth(),
            'end' => $now->copy()->endOfMonth(),
            'label' => 'bulan ini'
        ];
    }
    
    /**
     * Extract what data is requested
     */
    protected function extractRequestedData(string $question): array
    {
        $requested = [
            'income' => false,
            'expense' => false,
            'balance' => false,
            'summary' => false,
            'list' => false, // For detailed transaction list
            'list_type' => null, // 'income', 'expense', or null for both
            'category_filter' => null // Filter by category type (e.g., 'pengeluaran_belanja')
        ];
        
        // Check if asking for list/detail
        if (str_contains($question, 'daftar') || str_contains($question, 'list') || str_contains($question, 'apa saja') || str_contains($question, 'sebutkan') || str_contains($question, 'tunjukkan') || str_contains($question, 'tampilkan')) {
            $requested['list'] = true;
        }
        
        if (str_contains($question, 'pemasukan') || str_contains($question, 'pendapatan') || str_contains($question, 'income')) {
            $requested['income'] = true;
            if ($requested['list']) {
                $requested['list_type'] = 'income';
            }
        }
        
        // Check for expense keywords - termasuk "belanjaan" (items yang dibeli)
        if (str_contains($question, 'pengeluaran') || str_contains($question, 'expense') || str_contains($question, 'keluar') || 
            str_contains($question, 'belanjaan') || str_contains($question, 'belanja')) {
            $requested['expense'] = true;
            if ($requested['list']) {
                $requested['list_type'] = 'expense';
            }
        }
        
        // Special case: "belanjaan hari ini" = daftar pengeluaran kategori belanja hari ini
        if (str_contains($question, 'belanjaan') && str_contains($question, 'hari ini')) {
            $requested['expense'] = true;
            $requested['list'] = true;
            $requested['list_type'] = 'expense';
            // Filter by category "belanja" (pengeluaran_belanja)
            $requested['category_filter'] = 'pengeluaran_belanja';
        }
        
        if (str_contains($question, 'saldo') || str_contains($question, 'balance')) {
            $requested['balance'] = true;
        }
        
        // Extract category filter from question (e.g., "makan", "transport")
        $categoryMappings = [
            'makan' => 'Makanan & Minuman',
            'makanan' => 'Makanan & Minuman',
            'minuman' => 'Makanan & Minuman',
            'transport' => 'Transportasi',
            'transportasi' => 'Transportasi',
            'belanja' => 'Belanja',
            'hiburan' => 'Hiburan',
            'tagihan' => 'Tagihan',
            'listrik' => 'Tagihan',
            'air' => 'Tagihan',
            'pulsa' => 'Komunikasi',
            'internet' => 'Komunikasi',
            'kesehatan' => 'Kesehatan',
            'obat' => 'Kesehatan',
            'pendidikan' => 'Pendidikan',
            'kursus' => 'Pendidikan',
        ];
        
        foreach ($categoryMappings as $keyword => $categoryName) {
            if (str_contains($question, $keyword)) {
                $requested['category_name'] = $categoryName;
                break;
            }
        }
        
        // If asking "berapa" without specific type, show summary
        if (str_contains($question, 'berapa') && !$requested['income'] && !$requested['expense']) {
            $requested['summary'] = true;
        }
        
        // If no specific request, show summary
        if (!$requested['income'] && !$requested['expense'] && !$requested['balance'] && !$requested['list']) {
            $requested['summary'] = true;
        }
        
        return $requested;
    }
    
    /**
     * Get financial data for period
     */
    protected function getFinancialData(int $tenantId, Carbon $startDate, Carbon $endDate, ?string $categoryFilter = null): array
    {
        $transactions = Transaction::where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->with('category') // Eager load category relationship
            ->get();
        
        // Filter by category name if specified
        if ($categoryFilter) {
            $transactions = $transactions->filter(function ($tx) use ($categoryFilter) {
                $categoryName = $tx->category->name ?? '';
                return str_contains(strtolower($categoryName), strtolower($categoryFilter)) 
                    || strtolower($categoryName) === strtolower($categoryFilter);
            });
        }
        
        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netCashflow = $totalIncome - $totalExpense;
        
        // Top categories
        $topIncomeCategories = collect();
        $incomeTransactions = $transactions->where('type', 'income');
        if ($incomeTransactions->isNotEmpty()) {
            $topIncomeCategories = $incomeTransactions
                ->groupBy('category_id')
                ->map(function ($group) {
                    $first = $group->first();
                    return [
                        'category' => $first->category->name ?? 'Lainnya',
                        'amount' => $group->sum('amount'),
                        'count' => $group->count()
                    ];
                })
                ->sortByDesc('amount')
                ->take(3)
                ->values();
        }
        
        $topExpenseCategories = collect();
        $expenseTransactions = $transactions->where('type', 'expense');
        if ($expenseTransactions->isNotEmpty()) {
            $topExpenseCategories = $expenseTransactions
                ->groupBy('category_id')
                ->map(function ($group) {
                    $first = $group->first();
                    return [
                        'category' => $first->category->name ?? 'Lainnya',
                        'amount' => $group->sum('amount'),
                        'count' => $group->count()
                    ];
                })
                ->sortByDesc('amount')
                ->take(3)
                ->values();
        }
        
        return [
            'total_income' => (float) $totalIncome,
            'total_expense' => (float) $totalExpense,
            'net_cashflow' => (float) $netCashflow,
            'transaction_count' => $transactions->count(),
            'top_income_categories' => $topIncomeCategories,
            'top_expense_categories' => $topExpenseCategories,
            'transactions' => $transactions // Include full transaction list for detail view
        ];
    }
    
    /**
     * Generate human-readable answer
     */
    protected function generateAnswer(array $requested, array $data, array $period): string
    {
        // If asking for list/detail
        if ($requested['list']) {
            return $this->generateListAnswer($requested, $data, $period);
        }
        
        $categoryLabel = isset($requested['category_name']) ? " - {$requested['category_name']}" : "";
        $answer = "📊 *Ringkasan Keuangan {$period['label']}{$categoryLabel}*\n\n";
        
        if ($requested['summary'] || ($requested['income'] && $requested['expense'])) {
            $answer .= "💰 *Pemasukan:* Rp " . number_format($data['total_income'], 0, ',', '.') . "\n";
            $answer .= "💸 *Pengeluaran:* Rp " . number_format($data['total_expense'], 0, ',', '.') . "\n";
            $answer .= "📈 *Saldo Bersih:* Rp " . number_format($data['net_cashflow'], 0, ',', '.') . "\n";
            $answer .= "📝 *Total Transaksi:* {$data['transaction_count']}\n\n";
            
            $topIncome = $data['top_income_categories'] ?? collect();
            if ($topIncome && $topIncome->isNotEmpty()) {
                $answer .= "*Top Pemasukan:*\n";
                foreach ($topIncome as $cat) {
                    $answer .= "• {$cat['category']}: Rp " . number_format($cat['amount'], 0, ',', '.') . "\n";
                }
                $answer .= "\n";
            }
            
            $topExpense = $data['top_expense_categories'] ?? collect();
            if ($topExpense && $topExpense->isNotEmpty()) {
                $answer .= "*Top Pengeluaran:*\n";
                foreach ($topExpense as $cat) {
                    $answer .= "• {$cat['category']}: Rp " . number_format($cat['amount'], 0, ',', '.') . "\n";
                }
            }
        } elseif ($requested['income']) {
            $answer .= "💰 *Pemasukan {$period['label']}:* Rp " . number_format($data['total_income'], 0, ',', '.') . "\n";
            $answer .= "📝 *Total Transaksi:* {$data['transaction_count']}\n";
        } elseif ($requested['expense']) {
            $answer .= "💸 *Pengeluaran {$period['label']}:* Rp " . number_format($data['total_expense'], 0, ',', '.') . "\n";
            $answer .= "📝 *Total Transaksi:* {$data['transaction_count']}\n";
        } elseif ($requested['balance']) {
            // Get balance accounts (saldo akun)
            $balances = Balance::where('tenant_id', $this->getTenantId())
                ->where('is_active', true)
                ->orderBy('account_type')
                ->orderBy('account_name')
                ->get();
            
            if ($balances->isEmpty()) {
                // If no balance accounts, show cashflow instead
                $answer .= "📈 *Saldo Bersih {$period['label']}:* Rp " . number_format($data['net_cashflow'], 0, ',', '.') . "\n";
                $answer .= "💰 Pemasukan: Rp " . number_format($data['total_income'], 0, ',', '.') . "\n";
                $answer .= "💸 Pengeluaran: Rp " . number_format($data['total_expense'], 0, ',', '.') . "\n\n";
                $answer .= "💡 *Tips:* Untuk menampilkan saldo akun, tambahkan akun di dashboard terlebih dahulu.\n";
            } else {
                // Show balance accounts
                $answer = "💰 *Saldo Akun Anda:*\n\n";
                $totalBalance = 0;
                
                foreach ($balances as $balance) {
                    $balanceAmount = (float) $balance->balance;
                    $totalBalance += $balanceAmount;
                    $balanceDate = $balance->balance_date ? Carbon::parse($balance->balance_date)->format('d M Y') : '-';
                    
                    $answer .= "🏦 *{$balance->account_name}*\n";
                    $answer .= "   Rp " . number_format($balanceAmount, 0, ',', '.') . "\n";
                    $answer .= "   Update: {$balanceDate}\n\n";
                }
                
                $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $answer .= "📊 *Total Saldo:* Rp " . number_format($totalBalance, 0, ',', '.') . "\n\n";
                
                // Also show cashflow for reference
                $answer .= "📈 *Net Cashflow {$period['label']}:* Rp " . number_format($data['net_cashflow'], 0, ',', '.') . "\n";
            }
        }
        
        return $answer;
    }
    
    /**
     * Generate detailed transaction list answer
     */
    protected function generateListAnswer(array $requested, array $data, array $period): string
    {
        $transactions = $data['transactions'] ?? collect();
        $listType = $requested['list_type'] ?? null;
        $categoryFilter = $requested['category_filter'] ?? null;
        
        // Filter by type if specified
        if ($listType === 'income') {
            $transactions = $transactions->where('type', 'income');
            $title = "💰 *Daftar Pemasukan {$period['label']}*";
        } elseif ($listType === 'expense') {
            $transactions = $transactions->where('type', 'expense');
            // Special title for "belanjaan"
            if ($categoryFilter === 'pengeluaran_belanja') {
                $title = "🛍️ *Daftar Belanjaan {$period['label']}*";
            } else {
                $title = "💸 *Daftar Pengeluaran {$period['label']}*";
            }
        } else {
            // Both types
            $title = "📋 *Daftar Transaksi {$period['label']}*";
        }
        
        // Filter by category if specified (e.g., "belanjaan" = pengeluaran_belanja)
        if ($categoryFilter) {
            $transactions = $transactions->filter(function ($tx) use ($categoryFilter) {
                return $tx->category && $tx->category->type === $categoryFilter;
            });
        }
        
        $answer = $title . "\n\n";
        
        if ($transactions->isEmpty()) {
            if ($categoryFilter === 'pengeluaran_belanja') {
                $answer .= "Tidak ada belanjaan pada periode ini.\n";
            } else {
                $answer .= "Tidak ada transaksi pada periode ini.\n";
            }
            return $answer;
        }
        
        // Sort by date (newest first)
        $transactions = $transactions->sortByDesc('transaction_date');
        
        // Limit to 20 transactions to avoid message too long
        $transactions = $transactions->take(20);
        
        $count = 0;
        $totalAmount = 0;
        foreach ($transactions as $tx) {
            $count++;
            $type = $tx->type === 'income' ? '💰' : '💸';
            $amount = (float) $tx->amount;
            $totalAmount += $amount;
            $amountFormatted = number_format($amount, 0, ',', '.');
            $category = $tx->category->name ?? 'Lainnya';
            $description = $tx->description ?? '-';
            $date = $tx->transaction_date ? $tx->transaction_date->format('d/m/Y') : '-';
            
            $answer .= "{$count}. {$type} Rp {$amountFormatted}\n";
            $answer .= "   📁 {$category}\n";
            if ($description && $description !== '-') {
                $answer .= "   📝 {$description}\n";
            }
            $answer .= "   📅 {$date}\n\n";
        }
        
        // Add total summary
        if ($count > 0) {
            $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $answer .= "📊 *Total: Rp " . number_format($totalAmount, 0, ',', '.') . "*\n";
            $answer .= "📝 *Jumlah Transaksi: {$count}*\n";
        }
        
        if ($count >= 20) {
            $answer .= "\n... (menampilkan 20 transaksi terbaru)\n";
        }
        
        return $answer;
    }
}

