<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PdfReportService
{
    protected int $tenantId;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Generate monthly financial report PDF
     */
    public function generateMonthlyReport(?int $month = null, ?int $year = null): ?string
    {
        try {
            $month = $month ?? Carbon::now()->month;
            $year = $year ?? Carbon::now()->year;

            $startDate = Carbon::create($year, $month, 1)->startOfDay();
            $endDate = $startDate->copy()->endOfMonth();

            // Get transactions
            $transactions = Transaction::where('tenant_id', $this->tenantId)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->with('category')
                ->orderBy('transaction_date', 'desc')
                ->get();

            // Calculate totals
            $totalIncome = $transactions->where('type', 'income')->sum('amount');
            $totalExpense = $transactions->where('type', 'expense')->sum('amount');
            $netCashflow = $totalIncome - $totalExpense;

            // Group expenses by category
            $expenseByCategory = $transactions->where('type', 'expense')
                ->groupBy('category_id')
                ->map(function ($items) {
                    return [
                        'name' => optional($items->first()->category)->name ?? 'Lainnya',
                        'total' => $items->sum('amount'),
                        'count' => $items->count(),
                    ];
                })
                ->sortByDesc('total')
                ->values()
                ->toArray();

            // Group income by category
            $incomeByCategory = $transactions->where('type', 'income')
                ->groupBy('category_id')
                ->map(function ($items) {
                    return [
                        'name' => optional($items->first()->category)->name ?? 'Lainnya',
                        'total' => $items->sum('amount'),
                        'count' => $items->count(),
                    ];
                })
                ->sortByDesc('total')
                ->values()
                ->toArray();

            // Daily spending for chart
            $dailySpending = $transactions->where('type', 'expense')
                ->groupBy(fn ($tx) => $tx->transaction_date->format('d'))
                ->map(fn ($items) => $items->sum('amount'))
                ->toArray();

            // Get budgets
            $budgets = Budget::where('tenant_id', $this->tenantId)
                ->where('is_active', true)
                ->with('category')
                ->get()
                ->map(function ($budget) use ($startDate, $endDate) {
                    $spent = Transaction::where('tenant_id', $this->tenantId)
                        ->where('category_id', $budget->category_id)
                        ->where('type', 'expense')
                        ->whereBetween('transaction_date', [$startDate, $endDate])
                        ->sum('amount');

                    return [
                        'category' => optional($budget->category)->name ?? 'Unknown',
                        'budget' => $budget->amount,
                        'spent' => $spent,
                        'percentage' => $budget->amount > 0 ? round(($spent / $budget->amount) * 100) : 0,
                    ];
                });

            // Previous month comparison
            $prevStart = $startDate->copy()->subMonth();
            $prevEnd = $prevStart->copy()->endOfMonth();
            $prevExpense = Transaction::where('tenant_id', $this->tenantId)
                ->where('type', 'expense')
                ->whereBetween('transaction_date', [$prevStart, $prevEnd])
                ->sum('amount');

            $expenseChange = 0;
            if ($prevExpense > 0) {
                $expenseChange = round((($totalExpense - $prevExpense) / $prevExpense) * 100);
            }

            // Prepare data for view
            $data = [
                'month' => $startDate->translatedFormat('F Y'),
                'generatedAt' => Carbon::now()->translatedFormat('d F Y H:i'),
                'totalIncome' => $totalIncome,
                'totalExpense' => $totalExpense,
                'netCashflow' => $netCashflow,
                'transactionCount' => $transactions->count(),
                'expenseByCategory' => $expenseByCategory,
                'incomeByCategory' => $incomeByCategory,
                'dailySpending' => $dailySpending,
                'daysInMonth' => $endDate->day,
                'budgets' => $budgets,
                'expenseChange' => $expenseChange,
                'recentTransactions' => $transactions->take(20),
                'pieChartData' => $this->generatePieChartData($expenseByCategory, $totalExpense),
            ];

            // Generate PDF
            $pdf = Pdf::loadView('reports.monthly', $data);
            $pdf->setPaper('a4', 'portrait');

            // Save to storage
            $filename = "laporan-keuangan-{$year}-{$month}-".time().'.pdf';
            $path = "reports/{$this->tenantId}/{$filename}";

            Storage::disk('public')->put($path, $pdf->output());

            Log::info('PDF report generated', [
                'tenant_id' => $this->tenantId,
                'month' => $month,
                'year' => $year,
                'path' => $path,
            ]);

            return Storage::disk('public')->path($path);

        } catch (\Throwable $e) {
            Log::error('Failed to generate PDF report', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Generate weekly report PDF
     */
    public function generateWeeklyReport(): ?string
    {
        $startDate = Carbon::now()->subWeek()->startOfWeek();
        $endDate = Carbon::now()->subWeek()->endOfWeek();

        // Similar logic as monthly but for week
        // For now, reuse monthly logic with week dates
        return $this->generateCustomReport($startDate, $endDate, 'Mingguan');
    }

    /**
     * Generate custom date range report
     */
    protected function generateCustomReport(Carbon $startDate, Carbon $endDate, string $label): ?string
    {
        try {
            $transactions = Transaction::where('tenant_id', $this->tenantId)
                ->whereBetween('transaction_date', [$startDate, $endDate])
                ->with('category')
                ->orderBy('transaction_date', 'desc')
                ->get();

            $totalIncome = $transactions->where('type', 'income')->sum('amount');
            $totalExpense = $transactions->where('type', 'expense')->sum('amount');

            $expenseByCategory = $transactions->where('type', 'expense')
                ->groupBy('category_id')
                ->map(fn ($items) => [
                    'name' => optional($items->first()->category)->name ?? 'Lainnya',
                    'total' => $items->sum('amount'),
                    'count' => $items->count(),
                ])
                ->sortByDesc('total')
                ->values()
                ->toArray();

            $data = [
                'month' => "Laporan {$label}",
                'generatedAt' => Carbon::now()->translatedFormat('d F Y H:i'),
                'totalIncome' => $totalIncome,
                'totalExpense' => $totalExpense,
                'netCashflow' => $totalIncome - $totalExpense,
                'transactionCount' => $transactions->count(),
                'expenseByCategory' => $expenseByCategory,
                'incomeByCategory' => [],
                'dailySpending' => [],
                'daysInMonth' => $endDate->diffInDays($startDate),
                'budgets' => collect([]),
                'expenseChange' => 0,
                'recentTransactions' => $transactions->take(20),
            ];

            $pdf = Pdf::loadView('reports.monthly', $data);
            $pdf->setPaper('a4', 'portrait');

            $filename = "laporan-{$label}-".time().'.pdf';
            $path = "reports/{$this->tenantId}/{$filename}";

            Storage::disk('public')->put($path, $pdf->output());

            return Storage::disk('public')->path($path);

        } catch (\Throwable $e) {
            Log::error('Failed to generate custom PDF report', [
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get public URL for PDF
     */
    public function getPublicUrl(string $path): string
    {
        return url('storage/'.ltrim($path, '/'));
    }

    /**
     * Generate pie chart data with SVG segments
     */
    protected function generatePieChartData(array $expenseByCategory, float $totalExpense): array
    {
        if (empty($expenseByCategory) || $totalExpense <= 0) {
            return [];
        }

        // Colors for pie segments
        $colors = ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#6366f1', '#14b8a6'];

        // Take top 6 categories, group rest as "Lainnya"
        $topCategories = array_slice($expenseByCategory, 0, 6);
        $otherTotal = array_sum(array_column(array_slice($expenseByCategory, 6), 'total'));

        if ($otherTotal > 0) {
            $topCategories[] = ['name' => 'Lainnya', 'total' => $otherTotal];
        }

        $chartData = [];
        $startAngle = 0;
        $centerX = 80;
        $centerY = 80;
        $radius = 70;

        foreach ($topCategories as $index => $category) {
            $percentage = ($category['total'] / $totalExpense) * 100;
            $angle = ($percentage / 100) * 360;
            $endAngle = $startAngle + $angle;

            // Calculate SVG arc path
            $startX = $centerX + $radius * cos(deg2rad($startAngle - 90));
            $startY = $centerY + $radius * sin(deg2rad($startAngle - 90));
            $endX = $centerX + $radius * cos(deg2rad($endAngle - 90));
            $endY = $centerY + $radius * sin(deg2rad($endAngle - 90));

            $largeArc = $angle > 180 ? 1 : 0;

            $chartData[] = [
                'name' => $category['name'],
                'total' => $category['total'],
                'percentage' => round($percentage, 1),
                'color' => $colors[$index % count($colors)],
                'path' => sprintf(
                    'M %s %s L %s %s A %s %s 0 %s 1 %s %s Z',
                    $centerX, $centerY,
                    round($startX, 2), round($startY, 2),
                    $radius, $radius,
                    $largeArc,
                    round($endX, 2), round($endY, 2)
                ),
            ];

            $startAngle = $endAngle;
        }

        return $chartData;
    }
}
