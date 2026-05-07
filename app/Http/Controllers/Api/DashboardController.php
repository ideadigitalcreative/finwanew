<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balance;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $user = $request->user();
        $tenant = $user->currentTenant();

        if (! $tenant) {
            return response()->json([
                'message' => 'No active tenant found',
            ], 404);
        }

        // 1. Total Balance
        $totalBalance = Balance::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->sum('balance');

        // 2. Income/Expense this month
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $income = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'income')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        $expense = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->sum('amount');

        // 3. Recent Transactions (Limit 5)
        $recentTransactions = Transaction::where('tenant_id', $tenant->id)
            ->with(['category', 'balance'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        // 4. Trend Chart (Last 7 Days) — single query
        $weekAgo = Carbon::now()->subDays(6)->startOfDay();
        $trendRows = Transaction::where('tenant_id', $tenant->id)
            ->where('transaction_date', '>=', $weekAgo)
            ->select(
                DB::raw('DATE(transaction_date) as tx_date'),
                'type',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy(DB::raw('DATE(transaction_date)'), 'type')
            ->get();

        $trendGrouped = [];
        foreach ($trendRows as $row) {
            if (! isset($trendGrouped[$row->tx_date])) {
                $trendGrouped[$row->tx_date] = ['income' => 0, 'expense' => 0];
            }
            $trendGrouped[$row->tx_date][$row->type] = (float) $row->total;
        }

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateStr = $date->format('Y-m-d');
            $trend[] = [
                'date' => $dateStr,
                'day_name' => $date->format('D'),
                'income' => $trendGrouped[$dateStr]['income'] ?? 0,
                'expense' => $trendGrouped[$dateStr]['expense'] ?? 0,
            ];
        }

        // 5. Expense Composition (Pie Chart) - This Month
        $expenseCategoryTotals = Transaction::where('tenant_id', $tenant->id)
            ->where('type', 'expense')
            ->whereBetween('transaction_date', [$startOfMonth, $endOfMonth])
            ->select('category_id', DB::raw('SUM(amount) as total'))
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $categories = \App\Models\Category::where('tenant_id', $tenant->id)
            ->whereIn('id', $expenseCategoryTotals->keys())
            ->get()
            ->keyBy('id');

        $expensesByCategory = $expenseCategoryTotals->map(function ($total, $catId) use ($categories) {
            $cat = $categories[$catId] ?? null;

            return [
                'category_name' => $cat->name ?? 'Unknown',
                'color' => $cat->color ?? '#CCCCCC',
                'total' => (float) $total,
            ];
        })->values();

        // 6. Budgets
        $budgets = \App\Models\Budget::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->with('category')
            ->get()
            ->map(function ($budget) {
                return [
                    'category_name' => $budget->category->name ?? 'Unknown',
                    'limit' => (float) $budget->amount,
                    'used' => $budget->getCurrentSpending(),
                    'period' => $budget->period,
                    'color' => $budget->category->color ?? '#CCCCCC',
                ];
            });

        return response()->json([
            'total_balance' => (float) $totalBalance,
            'income' => (float) $income,
            'expense' => (float) $expense,
            'recent_transactions' => $recentTransactions,

            'trend_chart' => $trend,
            'expense_pie' => $expensesByCategory,
            'budgets' => $budgets, // Renaming slightly if needed, but 'budgets' is clear

            'tenant' => $tenant,
        ]);
    }
}
