<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Budget;
use App\Models\SavingsGoal;
use App\Models\BudgetGlobal;
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

        // Ringkasan arus kas per nomor WhatsApp (anggota) untuk bulan berjalan
        $memberGroups = $transactions
            ->whereNotNull('user_whatsapp_number_id')
            ->groupBy('user_whatsapp_number_id');

        $memberSummary = collect();
        if ($memberGroups->isNotEmpty()) {
            $numbers = \App\Models\UserWhatsAppNumber::where('tenant_id', $tenant->id)
                ->whereIn('id', $memberGroups->keys())
                ->get(['id', 'whatsapp_number', 'name'])
                ->keyBy('id');

            $memberSummary = $memberGroups->map(function ($group, $numberId) use ($numbers) {
                $number = $numbers->get($numberId);

                return [
                    'number_id' => $numberId,
                    'name' => $number && $number->name ? $number->name : ($number ? $number->whatsapp_number : 'Nomor #'.$numberId),
                    'whatsapp_number' => $number?->whatsapp_number,
                    'total_income' => (float) $group->where('type', 'income')->sum('amount'),
                    'total_expense' => (float) $group->where('type', 'expense')->sum('amount'),
                    'count' => $group->count(),
                ];
            })
                ->sortByDesc(fn ($item) => $item['total_income'] + $item['total_expense'])
                ->values();
        }

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
            ->with('category')
            ->get();

        \App\Models\Budget::loadBulkSpending($budgets);

        $totalBudget = $budgets->sum('amount');
        $totalSpending = 0;

        $budgetItems = $budgets->map(function ($budget) use (&$totalSpending) {
            $spending = $budget->getCurrentSpending();
            $totalSpending += $spending;
            $effectiveAmount = $budget->getEffectiveAmount();
            $usagePct = $effectiveAmount > 0 ? round(($spending / $effectiveAmount) * 100, 1) : 0;
            return [
                'id'           => $budget->id,
                'category_name'=> $budget->category?->name ?? 'Lainnya',
                'category_icon'=> $budget->category?->icon ?? '📝',
                'category_type'=> $budget->category?->type ?? '',
                'amount'       => (float) $budget->amount,
                'rollover_amount' => (float) $budget->rollover_amount,
                'effective_amount' => (float) $effectiveAmount,
                'spending'     => (float) $spending,
                'remaining'    => max(0, (float) $effectiveAmount - $spending),
                'usage_percent'=> $usagePct,
                'is_over'      => $spending > (float) $effectiveAmount,
                'alert_level'  => $budget->getAlertLevel(),
            ];
        })->sortByDesc('usage_percent')->values();

        $remaining = $totalBudget - $totalSpending;
        $usagePercentage = $totalBudget > 0 ? ($totalSpending / $totalBudget) * 100 : 0;

        // Budget Global summary
        $budgetGlobal = BudgetGlobal::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $globalBudgetData = null;
        if ($budgetGlobal) {
            $globalSpending = $budgetGlobal->getCurrentSpending();
            $globalPct = $budgetGlobal->amount > 0 ? round(($globalSpending / $budgetGlobal->amount) * 100, 1) : 0;
            $globalBudgetData = [
                'id' => $budgetGlobal->id,
                'amount' => (float) $budgetGlobal->amount,
                'period' => $budgetGlobal->period,
                'spending' => (float) $globalSpending,
                'remaining' => max(0, (float) $budgetGlobal->amount - $globalSpending),
                'usage_percentage' => $globalPct,
                'is_over_budget' => $globalSpending > (float) $budgetGlobal->amount,
            ];
        }

        // Ambil "Celengan Impian" dari tabel tabungan (savings_goals), bukan budget
        $savingsGoals = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->orderByRaw("FIELD(status, 'active', 'completed')")
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(fn ($goal) => [
                'label' => $goal->name,
                'current' => (float) $goal->current_amount,
                'target' => (float) $goal->target_amount,
                'deadline' => $goal->deadline ? $goal->deadline->format('M Y') : null,
                'icon' => $goal->icon,
            ])
            ->values();

        // Calculate streak (consecutive days with transactions, up to 30 days back)
        $streakDays = 0;
        $checkDate = Carbon::now()->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            $hasTx = Transaction::where('tenant_id', $tenant->id)
                ->where('status', 'confirmed')
                ->whereDate('transaction_date', $checkDate->toDateString())
                ->exists();
            if ($hasTx) {
                $streakDays++;
                $checkDate->subDay();
            } else {
                break;
            }
        }

        // Determine which days of this week (Mon-Sun) have transactions
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);
        $weekDaysWithTx = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$weekStart, $weekEnd])
            ->pluck('transaction_date')
            ->map(fn ($d) => Carbon::parse($d)->dayOfWeek)
            ->unique()
            ->values();
        // Map dayOfWeek (0=Sun..6=Sat) to short labels
        $dayMap = [0 => 'Mg', 1 => 'Sn', 2 => 'Sl', 3 => 'Rb', 4 => 'Km', 5 => 'Jm', 6 => 'Sb'];
        $activeDays = $weekDaysWithTx->map(fn ($d) => $dayMap[$d] ?? $d)->values()->toArray();

        // Build weekly flow data (income & expense per day this week)
        $weeklyTransactions = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->whereBetween('transaction_date', [$weekStart, $weekEnd])
            ->get();

        $dailyGrouped = $weeklyTransactions->groupBy(function ($tx) {
            return Carbon::parse($tx->transaction_date)->dayOfWeek;
        });

        $weekDayOrder = [1, 2, 3, 4, 5, 6, 0]; // Mon–Sun
        $weekDayLabels = [0 => 'Min', 1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab'];
        $weeklyFlowData = [];
        $maxWeeklyAmount = 0;

        foreach ($weekDayOrder as $dow) {
            $dayTx = $dailyGrouped->get($dow, collect());
            $income = (float) $dayTx->where('type', 'income')->sum('amount');
            $expense = (float) $dayTx->where('type', 'expense')->sum('amount');
            $maxWeeklyAmount = max($maxWeeklyAmount, $income, $expense);
            $weeklyFlowData[] = [
                'day' => $weekDayLabels[$dow],
                'income' => $income,
                'expense' => $expense,
            ];
        }

        // Normalize to percentages (0–100) for chart bars
        if ($maxWeeklyAmount > 0) {
            $weeklyFlowData = array_map(function ($item) use ($maxWeeklyAmount) {
                return [
                    'day' => $item['day'],
                    'income' => round(($item['income'] / $maxWeeklyAmount) * 100),
                    'expense' => round(($item['expense'] / $maxWeeklyAmount) * 100),
                ];
            }, $weeklyFlowData);
        }

        // Peak expense day note
        $peakDay = collect($weeklyFlowData)->sortByDesc('expense')->first();
        $peakExpenseNote = '';
        if ($peakDay && $peakDay['expense'] > 0) {
            $peakExpenseNote = 'Puncak pengeluaran di ' . $peakDay['day'];
        }

        $periodLabel = $weekStart->format('d M') . ' - ' . $weekEnd->format('d M Y');

        $insightService = new SpendingInsightService($tenant->id);
        $insights = $insightService->generateDashboardInsights();

        // Hitung Level Pengguna Berdasarkan Aktivitas Riil (Transaksi & Streak)
        $totalConfirmedTx = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'confirmed')
            ->count();
        $thisMonthTx = $monthlyTransactions->count();

        // Skema Level Finwa:
        // Level 1: Pemula Cuan (0-5 transaksi)
        // Level 2: Pemburu Cuan (6-20 transaksi)
        // Level 3: Pejuang Tabungan (21-50 transaksi atau streak >= 3 hari)
        // Level 4: Hemat Ranger (51-100 transaksi atau streak >= 7 hari)
        // Level 5: Juragan Cerdas (101-250 transaksi atau streak >= 14 hari)
        // Level 6: Sultan Bijak (>250 transaksi atau streak >= 30 hari)
        if ($totalConfirmedTx > 250 || $streakDays >= 30) {
            $userLevel = ['level' => 6, 'title' => 'Sultan Bijak', 'icon' => 'military_tech'];
        } elseif ($totalConfirmedTx >= 101 || $streakDays >= 14) {
            $userLevel = ['level' => 5, 'title' => 'Juragan Cerdas', 'icon' => 'military_tech'];
        } elseif ($totalConfirmedTx >= 51 || $streakDays >= 7) {
            $userLevel = ['level' => 4, 'title' => 'Hemat Ranger', 'icon' => 'military_tech'];
        } elseif ($totalConfirmedTx >= 21 || $streakDays >= 3) {
            $userLevel = ['level' => 3, 'title' => 'Pejuang Tabungan', 'icon' => 'military_tech'];
        } elseif ($totalConfirmedTx >= 6) {
            $userLevel = ['level' => 2, 'title' => 'Pemburu Cuan', 'icon' => 'military_tech'];
        } else {
            $userLevel = ['level' => 1, 'title' => 'Pemula Cuan', 'icon' => 'military_tech'];
        }

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
            'memberSummary' => $memberSummary->values(),
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
                'items' => $budgetItems->toArray(),
            ],
            'budgetGlobal' => $globalBudgetData,
            'savingsGoals' => $savingsGoals->toArray(),
            'streakDays' => $streakDays,
            'userLevel' => $userLevel,
            'activeDays' => $activeDays,
            'insights' => $insights,
            'weeklyFlowData' => $weeklyFlowData,
            'weeklyPeriodLabel' => $periodLabel,
            'weeklyPeakNote' => $peakExpenseNote,
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
