<?php

namespace App\Services;

use App\Models\Balance;
use App\Models\Tenant;
use App\Models\Transaction;
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
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Error answering financial question', [
                'tenant_id' => $tenantId,
                'question' => $question,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
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

        $monthNames = [
            'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
            'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
            'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
            'jun' => 6, 'jul' => 7, 'ags' => 8, 'agt' => 8,
            'sep' => 9, 'okt' => 10, 'nov' => 11, 'des' => 12,
        ];

        // === "N hari/minggu/bulan terakhir" ===
        if (preg_match('/(\d+)\s*(hari|minggu|bulan|tahun)\s*(terakhir|lalu|kebelakang)/i', $question, $nMatch)) {
            $n = (int) $nMatch[1];
            $unit = strtolower($nMatch[2]);
            $start = match ($unit) {
                'hari' => $now->copy()->subDays($n)->startOfDay(),
                'minggu' => $now->copy()->subWeeks($n)->startOfDay(),
                'bulan' => $now->copy()->subMonths($n)->startOfDay(),
                'tahun' => $now->copy()->subYears($n)->startOfDay(),
            };

            return [
                'start' => $start,
                'end' => $now->copy()->endOfDay(),
                'label' => "{$n} {$unit} terakhir",
            ];
        }

        // === Nama bulan spesifik + tahun opsional: "Januari 2026", "bulan Maret" ===
        // Sort keys by length descending so longer names match first (e.g., "januari" before "jan")
        // and use word boundary to avoid false positives (e.g., "jan" inside "jajan")
        $sortedMonthNames = $monthNames;
        uksort($sortedMonthNames, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));
        $monthPattern = implode('|', array_map(fn ($k) => preg_quote($k, '/'), array_keys($sortedMonthNames)));
        if (preg_match('/(?:bulan\s+)?\b('.$monthPattern.')\b(?:\s+(\d{4}))?/i', $question, $mMatch)) {
            $monthKey = strtolower($mMatch[1]);
            $month = $monthNames[$monthKey] ?? null;
            $year = isset($mMatch[2]) ? (int) $mMatch[2] : null;

            if ($month) {
                if (! $year) {
                    $year = ($month > $now->month) ? $now->year - 1 : $now->year;
                }
                $target = Carbon::create($year, $month, 1);
                $fullMonthName = $target->translatedFormat('F Y');

                return [
                    'start' => $target->copy()->startOfMonth(),
                    'end' => $target->copy()->endOfMonth(),
                    'label' => $fullMonthName,
                ];
            }
        }

        // === Range tanggal: "tanggal 1-15", "tgl 1 sampai 20" ===
        if (preg_match('/(?:tanggal|tgl)\s*(\d{1,2})\s*(?:-|sampai|s\/d|sd|hingga)\s*(\d{1,2})/i', $question, $rangeMatch)) {
            $startDay = (int) $rangeMatch[1];
            $endDay = (int) $rangeMatch[2];
            $startDate = $now->copy()->startOfMonth()->day($startDay)->startOfDay();
            $endDate = $now->copy()->startOfMonth()->day(min($endDay, $now->daysInMonth))->endOfDay();

            return [
                'start' => $startDate,
                'end' => $endDate,
                'label' => "tanggal {$startDay}-{$endDay} ".$now->translatedFormat('F Y'),
            ];
        }

        // === Tanggal spesifik: "tanggal 5", "tgl 10" ===
        if (preg_match('/(?:tanggal|tgl)\s+(\d{1,2})(?!\s*(?:-|sampai|s\/d|sd|hingga))/i', $question, $dayMatch)) {
            $day = (int) $dayMatch[1];
            if ($day >= 1 && $day <= 31) {
                $target = $now->copy()->day(min($day, $now->daysInMonth));

                return [
                    'start' => $target->copy()->startOfDay(),
                    'end' => $target->copy()->endOfDay(),
                    'label' => "tanggal {$day} ".$now->translatedFormat('F Y'),
                ];
            }
        }

        // === Q1/Q2/Q3/Q4 (kuartal) ===
        if (preg_match('/\b(?:q|kuartal|quarter)\s*([1-4])\s*(?:(\d{4}))?/i', $question, $qMatch)) {
            $quarter = (int) $qMatch[1];
            $year = isset($qMatch[2]) && ! empty($qMatch[2]) ? (int) $qMatch[2] : $now->year;
            $startMonth = ($quarter - 1) * 3 + 1;
            $start = Carbon::create($year, $startMonth, 1)->startOfMonth();
            $end = $start->copy()->addMonths(2)->endOfMonth();

            return [
                'start' => $start,
                'end' => $end,
                'label' => "Q{$quarter} {$year}",
            ];
        }

        // === Semester 1/2 ===
        if (preg_match('/\bsemester\s*([12])\s*(?:(\d{4}))?/i', $question, $semMatch)) {
            $sem = (int) $semMatch[1];
            $year = isset($semMatch[2]) && ! empty($semMatch[2]) ? (int) $semMatch[2] : $now->year;
            $start = Carbon::create($year, $sem === 1 ? 1 : 7, 1)->startOfMonth();
            $end = $start->copy()->addMonths(5)->endOfMonth();

            return [
                'start' => $start,
                'end' => $end,
                'label' => "Semester {$sem} {$year}",
            ];
        }

        // === Tahun lalu ===
        if (str_contains($question, 'tahun lalu') || str_contains($question, 'tahun kemarin')) {
            return [
                'start' => $now->copy()->subYear()->startOfYear(),
                'end' => $now->copy()->subYear()->endOfYear(),
                'label' => 'tahun lalu',
            ];
        }

        // === Pattern dasar yang sudah ada ===
        if (str_contains($question, 'hari ini') || str_contains($question, 'hr ini') || str_contains($question, 'hari ni')) {
            return [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
                'label' => 'hari ini',
            ];
        }

        if (str_contains($question, 'minggu ini')) {
            return [
                'start' => $now->copy()->startOfWeek(),
                'end' => $now->copy()->endOfWeek(),
                'label' => 'minggu ini',
            ];
        }

        if (str_contains($question, 'bulan ini') || str_contains($question, 'bln ini')) {
            return [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'label' => 'bulan ini',
            ];
        }

        if (str_contains($question, 'tahun ini')) {
            return [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
                'label' => 'tahun ini',
            ];
        }

        if (str_contains($question, 'minggu lalu')) {
            return [
                'start' => $now->copy()->subWeek()->startOfWeek(),
                'end' => $now->copy()->subWeek()->endOfWeek(),
                'label' => 'minggu lalu',
            ];
        }

        if (str_contains($question, 'kemarin') || str_contains($question, 'kmrn')) {
            return [
                'start' => $now->copy()->subDay()->startOfDay(),
                'end' => $now->copy()->subDay()->endOfDay(),
                'label' => 'kemarin',
            ];
        }

        if (str_contains($question, 'bulan lalu') || str_contains($question, 'bln lalu') || str_contains($question, 'bulan kemarin')) {
            return [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
                'label' => 'bulan lalu',
            ];
        }

        // === 2 bulan lalu (tanpa angka eksplisit) ===
        if (str_contains($question, '2 bulan lalu')) {
            return [
                'start' => $now->copy()->subMonths(2)->startOfMonth(),
                'end' => $now->copy()->subMonths(2)->endOfMonth(),
                'label' => '2 bulan lalu',
            ];
        }

        // Default: bulan ini
        return [
            'start' => $now->copy()->startOfMonth(),
            'end' => $now->copy()->endOfMonth(),
            'label' => 'bulan ini',
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
            'category_filter' => null, // Filter by category type (e.g., 'pengeluaran_belanja')
        ];

        // Check if asking for list/detail
        if (str_contains($question, 'daftar') || str_contains($question, 'list') || str_contains($question, 'apa saja') || str_contains($question, 'sebutkan') || str_contains($question, 'tunjukkan') || str_contains($question, 'tampilkan') || str_contains($question, 'rincian') || str_contains($question, 'detail') || str_contains($question, 'rinci')) {
            $requested['list'] = true;
        }

        if (str_contains($question, 'pemasukan') || str_contains($question, 'pendapatan') || str_contains($question, 'penghasilan') || str_contains($question, 'omzet') || str_contains($question, 'omset') || str_contains($question, 'income') || str_contains($question, 'revenue') || str_contains($question, 'uang masuk')) {
            $requested['income'] = true;
            if ($requested['list']) {
                $requested['list_type'] = 'income';
            }
        }

        // Check for expense keywords - termasuk "belanjaan" (items yang dibeli)
        if (str_contains($question, 'pengeluaran') || str_contains($question, 'expense') || str_contains($question, 'keluar') ||
            str_contains($question, 'belanjaan') || str_contains($question, 'belanja') ||
            str_contains($question, 'uang keluar') || str_contains($question, 'duit keluar') ||
            str_contains($question, 'biaya') || str_contains($question, 'spending') || str_contains($question, 'habis')) {
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
            'buka bersama' => 'Makanan & Minuman',
            'bukber' => 'Makanan & Minuman',
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
            'donasi' => 'Donasi',
            'sedekah' => 'Donasi',
            'santunan' => 'Donasi',
            'zakat' => 'Donasi',
        ];

        foreach ($categoryMappings as $keyword => $categoryName) {
            if (str_contains($question, $keyword)) {
                $requested['category_name'] = $categoryName;
                break;
            }
        }

        // If asking "berapa" without specific type, show summary
        if (str_contains($question, 'berapa') && ! $requested['income'] && ! $requested['expense']) {
            $requested['summary'] = true;
        }

        // If no specific request, show summary
        if (! $requested['income'] && ! $requested['expense'] && ! $requested['balance'] && ! $requested['list']) {
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
                        'count' => $group->count(),
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
                        'count' => $group->count(),
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
            'transactions' => $transactions, // Include full transaction list for detail view
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

        $categoryLabel = isset($requested['category_name']) ? " - {$requested['category_name']}" : '';
        $answer = "📊 *Ringkasan Keuangan {$period['label']}{$categoryLabel}*\n\n";

        if ($requested['summary'] || ($requested['income'] && $requested['expense'])) {
            $answer .= '💰 *Pemasukan:* Rp '.number_format($data['total_income'], 0, ',', '.')."\n";
            $answer .= '💸 *Pengeluaran:* Rp '.number_format($data['total_expense'], 0, ',', '.')."\n";
            $answer .= '📈 *Saldo Bersih:* Rp '.number_format($data['net_cashflow'], 0, ',', '.')."\n";
            $answer .= "📝 *Total Transaksi:* {$data['transaction_count']}\n\n";

            $topIncome = $data['top_income_categories'] ?? collect();
            if ($topIncome && $topIncome->isNotEmpty()) {
                $answer .= "*Top Pemasukan:*\n";
                foreach ($topIncome as $cat) {
                    $answer .= "• {$cat['category']}: Rp ".number_format($cat['amount'], 0, ',', '.')."\n";
                }
                $answer .= "\n";
            }

            $topExpense = $data['top_expense_categories'] ?? collect();
            if ($topExpense && $topExpense->isNotEmpty()) {
                $answer .= "*Top Pengeluaran:*\n";
                foreach ($topExpense as $cat) {
                    $answer .= "• {$cat['category']}: Rp ".number_format($cat['amount'], 0, ',', '.')."\n";
                }
            }
        } elseif ($requested['income']) {
            $answer .= "💰 *Pemasukan {$period['label']}:* Rp ".number_format($data['total_income'], 0, ',', '.')."\n";
            $answer .= "📝 *Total Transaksi:* {$data['transaction_count']}\n";
        } elseif ($requested['expense']) {
            $answer .= "💸 *Pengeluaran {$period['label']}:* Rp ".number_format($data['total_expense'], 0, ',', '.')."\n";
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
                $answer .= "📈 *Saldo Bersih {$period['label']}:* Rp ".number_format($data['net_cashflow'], 0, ',', '.')."\n";
                $answer .= '💰 Pemasukan: Rp '.number_format($data['total_income'], 0, ',', '.')."\n";
                $answer .= '💸 Pengeluaran: Rp '.number_format($data['total_expense'], 0, ',', '.')."\n\n";
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
                    $answer .= '   Rp '.number_format($balanceAmount, 0, ',', '.')."\n";
                    $answer .= "   Update: {$balanceDate}\n\n";
                }

                $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $answer .= '📊 *Total Saldo:* Rp '.number_format($totalBalance, 0, ',', '.')."\n";
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

        $answer = $title."\n\n";

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
            $answer .= '📊 *Total: Rp '.number_format($totalAmount, 0, ',', '.')."*\n";
            $answer .= "📝 *Jumlah Transaksi: {$count}*\n";
        }

        if ($count >= 20) {
            $answer .= "\n... (menampilkan 20 transaksi terbaru)\n";
        }

        return $answer;
    }
}
