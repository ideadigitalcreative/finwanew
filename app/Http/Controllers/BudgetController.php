<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\BudgetGlobal;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    /**
     * Display a listing of budgets
     */
    public function index(Request $request): Response
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $budgets = Budget::where('tenant_id', $tenantId)
            ->with('category')
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        Budget::loadBulkSpending($budgets);

        $budgets = $budgets->map(function ($budget) {
            return [
                'id' => $budget->id,
                'category' => [
                    'id' => $budget->category->id,
                    'name' => $budget->category->name,
                    'icon' => $budget->category->icon,
                    'color' => $budget->category->color,
                ],
                'amount' => $budget->amount,
                'period' => $budget->period,
                'start_date' => $budget->start_date->format('Y-m-d'),
                'end_date' => $budget->end_date?->format('Y-m-d'),
                'is_active' => $budget->is_active,
                'alert_enabled' => $budget->alert_enabled,
                'alert_threshold' => $budget->alert_threshold,
                'current_spending' => $budget->getCurrentSpending(),
                'remaining' => $budget->getRemainingBudget(),
                'usage_percentage' => $budget->getUsagePercentage(),
                'is_over_budget' => $budget->isOverBudget(),
                'should_alert' => $budget->shouldTriggerAlert(),
                'alert_level' => $budget->getAlertLevel(),
                'alert_color' => $budget->getAlertColor(),
            ];
        });

        // Get categories for budget creation
        $categories = Category::where('tenant_id', $tenantId)
            ->where('type', 'like', 'pengeluaran_%')
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color', 'type']);

        // Budget Global
        $global = BudgetGlobal::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->latest()
            ->first();

        $budgetGlobal = null;
        if ($global) {
            $gSpending = $global->getCurrentSpending();
            $budgetGlobal = [
                'id' => $global->id,
                'amount' => (float) $global->amount,
                'period' => $global->period,
                'start_date' => $global->start_date->format('Y-m-d'),
                'end_date' => $global->end_date?->format('Y-m-d'),
                'is_active' => $global->is_active,
                'alert_enabled' => $global->alert_enabled,
                'alert_threshold' => $global->alert_threshold,
                'current_spending' => (float) $gSpending,
                'remaining' => max(0, (float) $global->amount - $gSpending),
                'usage_percentage' => $global->amount > 0 ? round(($gSpending / $global->amount) * 100, 1) : 0,
                'is_over_budget' => $gSpending > (float) $global->amount,
            ];
        }

        // Budget vs Actual comparison data (for chart)
        $comparisonData = $budgets->filter(fn ($b) => $b['amount'] > 0)->map(function ($budget) {
            return [
                'category' => $budget['category']['name'] ?? 'Lainnya',
                'budget' => (float) $budget['amount'],
                'actual' => (float) $budget['current_spending'],
            ];
        })->values();

        return Inertia::render('Budgets/Index', [
            'budgets' => $budgets,
            'categories' => $categories,
            'budgetGlobal' => $budgetGlobal,
            'comparisonData' => $comparisonData,
        ]);
    }

    /**
     * Store a newly created budget
     */
    public function store(Request $request): RedirectResponse
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'amount' => 'required|numeric|min:0',
            'period' => 'required|in:daily,weekly,monthly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'alert_enabled' => 'boolean',
            'alert_threshold' => 'integer|min:0|max:100',
            'rollover_enabled' => 'boolean',
        ]);

        // Deactivate existing active budgets for same category+period
        Budget::where('tenant_id', $tenantId)
            ->where('category_id', $validated['category_id'])
            ->where('period', $validated['period'])
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Create new budget
        Budget::create([
            'tenant_id' => $tenantId,
            'category_id' => $validated['category_id'],
            'amount' => $validated['amount'],
            'period' => $validated['period'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => true,
            'alert_enabled' => $validated['alert_enabled'] ?? true,
            'alert_threshold' => $validated['alert_threshold'] ?? 80,
        ]);

        return redirect()->route('budgets.index')
            ->with('success', 'Budget berhasil dibuat!');
    }

    /**
     * Update the specified budget
     */
    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        // Ensure budget belongs to tenant
        if ($budget->tenant_id !== $tenantId) {
            abort(403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'alert_enabled' => 'boolean',
            'alert_threshold' => 'integer|min:0|max:100',
        ]);

        $budget->update($validated);

        return redirect()->route('budgets.index')
            ->with('success', 'Budget berhasil diupdate!');
    }

    /**
     * Remove the specified budget
     */
    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        // Ensure budget belongs to tenant
        if ($budget->tenant_id !== $tenantId) {
            abort(403);
        }

        $budget->delete();

        return redirect()->route('budgets.index')
            ->with('success', 'Budget berhasil dihapus!');
    }

    /**
     * Get budget summary for dashboard
     */
    public function summary(Request $request)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $budgets = Budget::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('period', 'monthly')
            ->with('category')
            ->get();

        Budget::loadBulkSpending($budgets);

        $totalBudget = $budgets->sum('amount');
        $totalSpending = $budgets->sum(fn ($b) => $b->getCurrentSpending());
        $totalRemaining = max(0, $totalBudget - $totalSpending);
        $overBudgetCount = $budgets->filter(fn ($b) => $b->isOverBudget())->count();
        $alertCount = $budgets->filter(fn ($b) => $b->shouldTriggerAlert())->count();

        return response()->json([
            'total_budget' => $totalBudget,
            'total_spending' => $totalSpending,
            'total_remaining' => $totalRemaining,
            'usage_percentage' => $totalBudget > 0 ? ($totalSpending / $totalBudget) * 100 : 0,
            'over_budget_count' => $overBudgetCount,
            'alert_count' => $alertCount,
            'budgets_count' => $budgets->count(),
        ]);
    }

    /**
     * Export budgets to CSV
     */
    public function export(Request $request)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $budgets = Budget::where('tenant_id', $tenantId)
            ->with('category')
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        Budget::loadBulkSpending($budgets);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="budget_'.date('Y-m-d').'.csv"',
        ];

        $callback = function () use ($budgets) {
            $file = fopen('php://output', 'w');

            // Header
            fputcsv($file, [
                'Kategori',
                'Anggaran (Rp)',
                'Terpakai (Rp)',
                'Sisa (Rp)',
                'Persentase (%)',
                'Status',
                'Periode',
                'Tanggal Mulai',
                'Tanggal Akhir',
                'Aktif',
                'Alert Aktif',
                'Threshold (%)',
            ]);

            foreach ($budgets as $budget) {
                $spent = $budget->getCurrentSpending();
                $remaining = $budget->getRemainingBudget();
                $percentage = $budget->getUsagePercentage();

                if ($percentage >= 100) {
                    $status = 'Melebihi Anggaran';
                } elseif ($percentage >= 80) {
                    $status = 'Perlu Perhatian';
                } elseif ($percentage >= 50) {
                    $status = 'Hampir Setengah';
                } else {
                    $status = 'Aman';
                }

                fputcsv($file, [
                    $budget->category->name ?? '-',
                    $budget->amount,
                    $spent,
                    $remaining,
                    round($percentage, 1),
                    $status,
                    $budget->period,
                    $budget->start_date->format('d/m/Y'),
                    $budget->end_date?->format('d/m/Y') ?? '-',
                    $budget->is_active ? 'Ya' : 'Tidak',
                    $budget->alert_enabled ? 'Ya' : 'Tidak',
                    $budget->alert_threshold,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Toggle budget active status
     */
    public function toggle(Request $request, Budget $budget): RedirectResponse
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        // Ensure budget belongs to tenant
        if ($budget->tenant_id !== $tenantId) {
            abort(403);
        }

        $budget->update(['is_active' => ! $budget->is_active]);

        return redirect()->route('budgets.index')
            ->with('success', 'Status budget berhasil diubah!');
    }

    // ─── Budget Global Methods ────────────────────────────────────────

    /**
     * Show current active global budget
     */
    public function showGlobal(Request $request)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $global = BudgetGlobal::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (! $global) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id' => $global->id,
                'amount' => $global->amount,
                'period' => $global->period,
                'start_date' => $global->start_date->format('Y-m-d'),
                'end_date' => $global->end_date?->format('Y-m-d'),
                'is_active' => $global->is_active,
                'alert_enabled' => $global->alert_enabled,
                'alert_threshold' => $global->alert_threshold,
                'current_spending' => $global->getCurrentSpending(),
                'remaining' => $global->getRemainingBudget(),
                'usage_percentage' => $global->getUsagePercentage(),
                'is_over_budget' => $global->isOverBudget(),
            ],
        ]);
    }

    /**
     * Store a new global budget
     */
    public function storeGlobal(Request $request)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'period' => 'required|in:monthly,yearly',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'alert_enabled' => 'boolean',
            'alert_threshold' => 'integer|min:0|max:100',
        ]);

        // Deactivate existing active global budgets for same period
        BudgetGlobal::where('tenant_id', $tenantId)
            ->where('period', $validated['period'])
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $global = BudgetGlobal::create([
            'tenant_id' => $tenantId,
            'amount' => $validated['amount'],
            'period' => $validated['period'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'] ?? null,
            'is_active' => true,
            'alert_enabled' => $validated['alert_enabled'] ?? true,
            'alert_threshold' => $validated['alert_threshold'] ?? 80,
        ]);

        return redirect()->route('budgets.index')
            ->with('success', 'Budget global berhasil dibuat!');
    }

    /**
     * Update global budget
     */
    public function updateGlobal(Request $request, BudgetGlobal $budgetGlobal)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        if ($budgetGlobal->tenant_id !== $tenantId) {
            abort(403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'alert_enabled' => 'boolean',
            'alert_threshold' => 'integer|min:0|max:100',
        ]);

        $budgetGlobal->update($validated);

        return redirect()->route('budgets.index')
            ->with('success', 'Budget global berhasil diupdate!');
    }

    /**
     * Delete global budget
     */
    public function destroyGlobal(Request $request, BudgetGlobal $budgetGlobal)
    {
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;

        if ($budgetGlobal->tenant_id !== $tenantId) {
            abort(403);
        }

        $budgetGlobal->delete();

        return redirect()->route('budgets.index')
            ->with('success', 'Budget global berhasil dihapus!');
    }
}
