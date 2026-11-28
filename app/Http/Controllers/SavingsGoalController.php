<?php

namespace App\Http\Controllers;

use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SavingsGoalController extends Controller
{
    /**
     * Display savings goals (Celengan Impian) page
     */
    public function index(Request $request): Response
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goals = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->orderByRaw("FIELD(status, 'active', 'completed')")
            ->orderBy('created_at', 'desc')
            ->get();

        $recentTransactions = SavingsTransaction::where('tenant_id', $tenant->id)
            ->with('savingsGoal')
            ->orderBy('transaction_date', 'desc')
            ->limit(6)
            ->get();

        return Inertia::render('SavingsGoals/Index', [
            'tenant_id' => $tenant->id,
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->id,
                'name' => $goal->name,
                'target_amount' => (float) $goal->target_amount,
                'current_amount' => (float) $goal->current_amount,
                'deadline' => $goal->deadline ? $goal->deadline->format('Y-m-d') : null,
                'status' => $goal->status,
                'icon' => $goal->icon,
                'progress_percentage' => round($goal->getProgressPercentage(), 1),
                'remaining_amount' => $goal->getRemainingAmount(),
                'days_remaining' => $goal->getDaysRemaining(),
                'suggested_monthly' => $goal->getSuggestedMonthlySavings(),
                'is_completed' => $goal->isCompleted(),
            ]),
            'recentTransactions' => $recentTransactions->map(fn ($tx) => [
                'id' => $tx->id,
                'goal_id' => $tx->savings_goal_id,
                'goal_name' => $tx->savingsGoal?->name ?? 'Celengan',
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'note' => $tx->note,
                'transaction_date' => $tx->transaction_date ? $tx->transaction_date->format('Y-m-d H:i') : null,
            ]),
        ]);
    }

    /**
     * Store a new savings goal
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'target_amount' => 'required|numeric|min:1',
            'current_amount' => 'nullable|numeric|min:0',
            'deadline' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goal = SavingsGoal::create([
            'tenant_id' => $tenant->id,
            'name' => $request->input('name'),
            'target_amount' => $request->input('target_amount'),
            'current_amount' => $request->input('current_amount', 0),
            'deadline' => $request->input('deadline'),
            'status' => 'active',
            'icon' => $request->input('icon', '🎯'),
        ]);

        // Record initial deposit as a savings transaction
        if ((float) $goal->current_amount > 0) {
            SavingsTransaction::create([
                'tenant_id' => $tenant->id,
                'savings_goal_id' => $goal->id,
                'type' => 'deposit',
                'amount' => $goal->current_amount,
                'note' => 'Saldo awal',
                'transaction_date' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Celengan impian berhasil dibuat');
    }

    /**
     * Update a savings goal
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'target_amount' => 'sometimes|numeric|min:1',
            'current_amount' => 'sometimes|numeric|min:0',
            'deadline' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goal = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $goal->update($request->only([
            'name',
            'target_amount',
            'current_amount',
            'deadline',
        ]));

        // Re-evaluate status: if current >= target, mark completed
        if ($goal->isCompleted() && $goal->status === 'active') {
            $goal->status = 'completed';
            $goal->save();
        }

        return to_route('tabungan.index')->with('success', 'Celengan impian berhasil diperbarui');
    }

    /**
     * Add savings (deposit) to a goal
     */
    public function addSavings(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goal = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        DB::transaction(function () use ($goal, $request, $tenant) {
            $goal->addSavings((float) $request->input('amount'));

            SavingsTransaction::create([
                'tenant_id' => $tenant->id,
                'savings_goal_id' => $goal->id,
                'type' => 'deposit',
                'amount' => $request->input('amount'),
                'note' => $request->input('note'),
                'transaction_date' => now(),
            ]);
        });

        if ($goal->isCompleted()) {
            return redirect()->back()->with('success', 'Selamat! Target celengan telah tercapai');
        }

        return redirect()->back()->with('success', 'Tabungan berhasil ditambahkan');
    }

    /**
     * Withdraw (claim) savings from a completed goal
     */
    public function withdraw(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goal = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $amount = (float) $request->input('amount');
        if ($amount > (float) $goal->current_amount) {
            return back()->withErrors(['amount' => 'Nominal penarikan melebihi saldo celengan'])->withInput();
        }

        DB::transaction(function () use ($goal, $request, $tenant, $amount) {
            $goal->current_amount -= $amount;
            $goal->save();

            SavingsTransaction::create([
                'tenant_id' => $tenant->id,
                'savings_goal_id' => $goal->id,
                'type' => 'withdrawal',
                'amount' => $amount,
                'note' => $request->input('note') ?: 'Penarikan celengan',
                'transaction_date' => now(),
            ]);

            // Jika saldo sudah 0, tandai selesai
            if ($goal->current_amount <= 0) {
                $goal->status = 'completed';
                $goal->save();
            }
        });

        return to_route('tabungan.index')->with('success', 'Saldo celengan berhasil ditarik');
    }

    /**
     * Delete (cancel) a savings goal
     */
    public function destroy(Request $request, $id)
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $goal = SavingsGoal::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $goal->update(['status' => 'cancelled']);

        return to_route('tabungan.index')->with('success', 'Celengan impian dihapus');
    }
}