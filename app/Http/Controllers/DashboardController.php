<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Budget;
use App\Models\Cashflow;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\SpendingInsightService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        // Redirect super admin to their dashboard
        if ($request->user()->is_super_admin) {
            return redirect()->route('superadmin.dashboard');
        }

        $tenant = Tenant::findOrFail($request->tenant_id);

        // Get current month period
        $now = Carbon::now();
        $startDate = $now->copy()->startOfMonth();
        $endDate = $now->copy()->endOfMonth();

        // Always recalculate cashflow from transactions (don't use cache)
        // This ensures data is always up-to-date
        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->get();

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netCashflow = $totalIncome - $totalExpense;

        // Update or create cashflow record
        $cashflow = Cashflow::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
            ],
            [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_cashflow' => $netCashflow,
                'breakdown' => [],
            ]
        );

        // Calculate previous period for comparison
        $prevStartDate = $now->copy()->subMonth()->startOfMonth();
        $prevEndDate = $now->copy()->subMonth()->endOfMonth();

        $prevTransactions = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$prevStartDate, $prevEndDate])
            ->get();

        $prevTotalIncome = $prevTransactions->where('type', 'income')->sum('amount');
        $prevTotalExpense = $prevTransactions->where('type', 'expense')->sum('amount');
        $prevNetCashflow = $prevTotalIncome - $prevTotalExpense;

        // Calculate percentage changes with caps for better UX
        if ($prevTotalIncome > 0) {
            $incomeChange = (($totalIncome - $prevTotalIncome) / $prevTotalIncome) * 100;
            $incomeChange = max(-999, min(999, $incomeChange)); // Cap at ±999%
        } else {
            $incomeChange = $totalIncome > 0 ? 999 : 0;
        }

        if ($prevTotalExpense > 0) {
            $expenseChange = (($totalExpense - $prevTotalExpense) / $prevTotalExpense) * 100;
            $expenseChange = max(-999, min(999, $expenseChange)); // Cap at ±999%
        } else {
            $expenseChange = $totalExpense > 0 ? 999 : 0;
        }

        // For net cashflow, handle negative values carefully
        if ($prevNetCashflow == 0) {
            $netChange = $netCashflow != 0 ? ($netCashflow > 0 ? 999 : -999) : 0;
        } else {
            $netChange = (($netCashflow - $prevNetCashflow) / abs($prevNetCashflow)) * 100;
            $netChange = max(-999, min(999, $netChange)); // Cap at ±999%
        }

        // Determine financial health status
        $healthStatus = 'stable';
        if ($netCashflow > $prevNetCashflow * 1.1) {
            $healthStatus = 'growing';
        } elseif ($netCashflow < $prevNetCashflow * 0.9) {
            $healthStatus = 'declining';
        }

        // Get recent transactions (last 5) for transaction list
        $recentTransactions = Transaction::where('tenant_id', $tenant->id)
            ->with('category')
            ->orderBy('transaction_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // Get ALL transactions for current month (for Activity Calendar)
        $monthlyTransactions = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->orderBy('transaction_date', 'desc')
            ->get(['id', 'transaction_date', 'type', 'amount']);

        // Get balances
        $balances = Balance::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('account_type')
            ->orderBy('account_name')
            ->get();

        // Get chart data (last 6 months)
        $chartData = $this->getChartData($tenant, 6);

        // Get top categories
        $topCategories = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(function ($group) {
                $category = $group->first()->category;
                if (! $category) {
                    return null;
                }

                return [
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                    'total_income' => $group->where('type', 'income')->sum('amount'),
                    'total_expense' => $group->where('type', 'expense')->sum('amount'),
                    'count' => $group->count(),
                ];
            })
            ->filter() // Remove null entries
            ->sortByDesc(function ($item) {
                return $item['total_income'] + $item['total_expense'];
            })
            ->take(5)
            ->values();

        // Get subscription/trial information
        $activeSubscription = $tenant->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->orderBy('ends_at', 'desc')
            ->first();

        // Also check for any paid subscription (for upgrade banner logic)
        // Even if expired, we want to know if user ever had a paid plan
        $latestPaidSubscription = $tenant->subscriptions()
            ->whereIn('status', ['active', 'expired', 'cancelled'])
            ->whereNotIn('plan', ['free', 'trial'])
            ->orderBy('ends_at', 'desc')
            ->first();

        $isOnTrial = false;
        $trialEndsAt = null;
        $trialDaysRemaining = null;

        if (! $activeSubscription && $tenant->trial_ends_at) {
            $isOnTrial = $tenant->trial_ends_at->isFuture();
            $trialEndsAt = $tenant->trial_ends_at->toISOString();
            $trialDaysRemaining = $isOnTrial ? (int) now()->diffInDays($tenant->trial_ends_at, false) : 0;
        }

        // Determine plan - use active subscription, or latest paid, or trial/free
        $currentPlan = 'free';
        if ($activeSubscription) {
            $currentPlan = $activeSubscription->plan;
        } elseif ($latestPaidSubscription) {
            // User had a paid plan but it's expired - still mark as paid plan
            $currentPlan = $latestPaidSubscription->plan;
        } elseif ($isOnTrial) {
            $currentPlan = 'trial';
        }

        // Get budget summary for current month
        $budgets = \App\Models\Budget::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->get();

        $totalBudget = $budgets->sum('amount');
        $totalSpending = 0;

        foreach ($budgets as $budget) {
            $totalSpending += $budget->getCurrentSpending();
        }

        $remaining = $totalBudget - $totalSpending;
        $usagePercentage = $totalBudget > 0 ? ($totalSpending / $totalBudget) * 100 : 0;

        $insightService = new SpendingInsightService($tenant->id);
        $insights = $insightService->generateDashboardInsights();

        return Inertia::render('Dashboard', [
            'cashflow' => [
                'total_income' => (float) $cashflow->total_income,
                'total_expense' => (float) $cashflow->total_expense,
                'net_cashflow' => (float) $cashflow->net_cashflow,
                'period_start' => $cashflow->period_start,
                'period_end' => $cashflow->period_end,
                // Comparison with previous period
                'income_change' => round($incomeChange, 1),
                'expense_change' => round($expenseChange, 1),
                'net_change' => round($netChange, 1),
                'health_status' => $healthStatus,
                'prev_total_income' => (float) $prevTotalIncome,
                'prev_total_expense' => (float) $prevTotalExpense,
                'prev_net_cashflow' => (float) $prevNetCashflow,
            ],
            'recentTransactions' => $recentTransactions->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                    'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
                    'description' => $transaction->description ?? '',
                    'category' => $transaction->category ? [
                        'name' => $transaction->category->name,
                        'type' => $transaction->category->type,
                    ] : null,
                    'status' => $transaction->status,
                ];
            }),
            // All transactions for current month (for Activity Calendar)
            'monthlyTransactions' => $monthlyTransactions->map(function ($transaction) {
                return [
                    'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
                    'type' => $transaction->type,
                    'amount' => (float) $transaction->amount,
                ];
            }),
            'balances' => $balances->map(function ($balance) {
                return [
                    'account_name' => $balance->account_name,
                    'balance' => (float) $balance->balance,
                    'currency' => $balance->currency,
                    'balance_date' => $balance->balance_date ? $balance->balance_date->format('Y-m-d') : null,
                ];
            }),
            'chartData' => array_map(function ($item) {
                return [
                    'month' => $item['month'],
                    'income' => (float) $item['income'],
                    'expense' => (float) $item['expense'],
                    'net' => (float) $item['net'],
                ];
            }, $chartData),
            'topCategories' => $topCategories->toArray(),
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
                'label' => $startDate->format('F Y'),
            ],
            'hasWhatsAppNumber' => \App\Models\UserWhatsAppNumber::where('user_id', $request->user()->id)->exists(),
            // Subscription/Trial data
            'subscription' => [
                'isOnTrial' => $isOnTrial,
                'trialEndsAt' => $trialEndsAt,
                'trialDaysRemaining' => $trialDaysRemaining,
                'hasActiveSubscription' => $activeSubscription !== null,
                'hasPaidPlan' => $latestPaidSubscription !== null,
                'plan' => $currentPlan,
                'endsAt' => $activeSubscription?->ends_at?->toISOString(),
            ],
            'budgetSummary' => [
                'totalBudget' => (float) $totalBudget,
                'totalSpending' => (float) $totalSpending,
                'remaining' => (float) $remaining,
                'usagePercentage' => round($usagePercentage, 1),
            ],
            'insights' => $insights,
        ]);
    }

    protected function getChartData(Tenant $tenant, int $months = 6): array
    {
        $now = Carbon::now();
        $startDate = $now->copy()->subMonths($months - 1)->startOfMonth();

        $rows = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->where('transaction_date', '>=', $startDate)
            ->select(
                DB::raw('YEAR(transaction_date) as year'),
                DB::raw('MONTH(transaction_date) as month'),
                'type',
                DB::raw('SUM(amount) as total')
            )
            ->groupBy(DB::raw('YEAR(transaction_date)'), DB::raw('MONTH(transaction_date)'), 'type')
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->year.'-'.str_pad($row->month, 2, '0', STR_PAD_LEFT);
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['income' => 0, 'expense' => 0];
            }
            $grouped[$key][$row->type] = (float) $row->total;
        }

        $data = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $date = $now->copy()->subMonths($i);
            $key = $date->format('Y-m');
            $income = $grouped[$key]['income'] ?? 0;
            $expense = $grouped[$key]['expense'] ?? 0;

            $data[] = [
                'month' => $date->format('M Y'),
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];
        }

        return $data;
    }
}
