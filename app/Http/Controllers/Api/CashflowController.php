<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cashflow;
use App\Models\Tenant;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CashflowController extends Controller
{
    /**
     * Get cashflow summary for tenant
     * Query params: start, end (date format: YYYY-MM-DD)
     */
    public function index(Request $request, $tenant = null)
    {
        $validator = Validator::make($request->all(), [
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid date parameters',
                'errors' => $validator->errors(),
            ], 400);
        }

        try {
            $tenantId = $tenant ?? $request->input('tenant_id') ?? $request->tenant_id;
            $tenantModel = Tenant::findOrFail($tenantId);
            $startDate = Carbon::parse($request->input('start'));
            $endDate = Carbon::parse($request->input('end'));

            // Check if cashflow already exists
            $cashflow = Cashflow::where('tenant_id', $tenantModel->id)
                ->where('period_start', $startDate->toDateString())
                ->where('period_end', $endDate->toDateString())
                ->first();

            if (! $cashflow) {
                // Calculate cashflow
                $transactions = Transaction::where('tenant_id', $tenantModel->id)
                    ->where('status', 'confirmed')
                    ->whereBetween('transaction_date', [$startDate, $endDate])
                    ->get();

                $totalIncome = $transactions->where('type', 'income')->sum('amount');
                $totalExpense = $transactions->where('type', 'expense')->sum('amount');
                $netCashflow = $totalIncome - $totalExpense;

                // Breakdown by category
                $breakdown = $transactions->groupBy('category_id')->map(function ($group) {
                    $category = $group->first()->category;

                    return [
                        'category_id' => $category->id,
                        'category_name' => $category->name,
                        'category_type' => $category->type,
                        'income' => $group->where('type', 'income')->sum('amount'),
                        'expense' => $group->where('type', 'expense')->sum('amount'),
                        'count' => $group->count(),
                    ];
                })->values();

                // Create cashflow record
                $cashflow = Cashflow::create([
                    'tenant_id' => $tenantModel->id,
                    'period_start' => $startDate->toDateString(),
                    'period_end' => $endDate->toDateString(),
                    'total_income' => $totalIncome,
                    'total_expense' => $totalExpense,
                    'net_cashflow' => $netCashflow,
                    'breakdown' => $breakdown,
                    'summary' => $this->generateSummary($totalIncome, $totalExpense, $netCashflow, $breakdown),
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $cashflow,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate markdown summary
     */
    protected function generateSummary(float $income, float $expense, float $net, $breakdown): array
    {
        $markdown = "# Ringkasan Cashflow\n\n";
        $markdown .= "## Total\n\n";
        $markdown .= '- **Pendapatan**: Rp '.number_format($income, 0, ',', '.')."\n";
        $markdown .= '- **Pengeluaran**: Rp '.number_format($expense, 0, ',', '.')."\n";
        $markdown .= '- **Net Cashflow**: Rp '.number_format($net, 0, ',', '.')."\n\n";

        if ($net > 0) {
            $markdown .= "✅ **Surplus**: Cashflow positif, kondisi keuangan sehat.\n\n";
        } else {
            $markdown .= "⚠️ **Defisit**: Cashflow negatif, perlu perhatian.\n\n";
        }

        $markdown .= "## Breakdown per Kategori\n\n";
        foreach ($breakdown as $item) {
            $markdown .= "### {$item['category_name']}\n";
            if ($item['income'] > 0) {
                $markdown .= '- Pendapatan: Rp '.number_format($item['income'], 0, ',', '.')."\n";
            }
            if ($item['expense'] > 0) {
                $markdown .= '- Pengeluaran: Rp '.number_format($item['expense'], 0, ',', '.')."\n";
            }
            $markdown .= "- Jumlah transaksi: {$item['count']}\n\n";
        }

        return [
            'markdown' => $markdown,
            'json' => [
                'total_income' => $income,
                'total_expense' => $expense,
                'net_cashflow' => $net,
                'breakdown' => $breakdown,
            ],
        ];
    }
}
