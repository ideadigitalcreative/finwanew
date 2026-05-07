<?php

namespace App\Http\Controllers;

use App\Models\Budget;
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
            ->get()
            ->map(function ($budget) {
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
                ];
            });

        // Get categories for budget creation
        $categories = Category::where('tenant_id', $tenantId)
            ->where('type', 'like', 'pengeluaran_%')
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color', 'type']);

        return Inertia::render('Budgets/Index', [
            'budgets' => $budgets,
            'categories' => $categories,
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
}
