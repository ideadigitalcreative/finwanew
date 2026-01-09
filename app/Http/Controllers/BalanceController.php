<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Validator;

class BalanceController extends Controller
{
    /**
     * Display balances management page
     */
    public function index(Request $request): Response
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $balances = Balance::where('tenant_id', $tenant->id)
            ->orderBy('is_active', 'desc')
            ->orderBy('account_type')
            ->orderBy('account_name')
            ->get();

        return Inertia::render('Balances/Index', [
            'tenant_id' => $tenant->id,
            'balances' => $balances->map(function ($balance) {
                return [
                    'id' => $balance->id,
                    'account_name' => $balance->account_name,
                    'account_number' => $balance->account_number,
                    'account_type' => $balance->account_type,
                    'currency' => $balance->currency,
                    'balance' => (float) $balance->balance,
                    'balance_date' => $balance->balance_date ? $balance->balance_date->format('Y-m-d') : null,
                    'is_active' => $balance->is_active,
                    'is_default' => $balance->is_default ?? false,
                ];
            })
        ]);
    }

    /**
     * Store a new balance
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_type' => 'required|in:bank,cash,wallet,investment,other',
            'currency' => 'nullable|string|size:3',
            'balance' => 'required|numeric|min:0',
            'balance_date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $balance = Balance::create([
            'tenant_id' => $tenant->id,
            'account_name' => $request->input('account_name'),
            'account_number' => $request->input('account_number'),
            'account_type' => $request->input('account_type'),
            'currency' => $request->input('currency', 'IDR'),
            'balance' => $request->input('balance'),
            'balance_date' => $request->input('balance_date'),
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Saldo akun berhasil ditambahkan');
    }

    /**
     * Update a balance
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'sometimes|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_type' => 'sometimes|in:bank,cash,wallet,investment,other',
            'currency' => 'nullable|string|size:3',
            'balance' => 'sometimes|numeric|min:0',
            'balance_date' => 'sometimes|date',
            'is_active' => 'sometimes|boolean',
            'is_default' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $balance = Balance::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        // If setting as default, unset all other defaults first
        if ($request->has('is_default') && $request->input('is_default')) {
            Balance::where('tenant_id', $tenant->id)
                ->where('id', '!=', $id)
                ->update(['is_default' => false]);
        }

        $balance->update($request->only([
            'account_name',
            'account_number',
            'account_type',
            'currency',
            'balance',
            'balance_date',
            'is_active',
            'is_default'
        ]));

        return redirect()->back()->with('success', 'Saldo akun berhasil diperbarui');
    }

    /**
     * Set default balance
     */
    public function setDefault(Request $request, $id)
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $balanceService = app(\App\Services\BalanceService::class);
        $success = $balanceService->setDefaultBalance($tenant->id, $id);

        if ($success) {
            return redirect()->back()->with('success', 'Dompet utama berhasil diatur');
        } else {
            return redirect()->back()->with('error', 'Gagal mengatur dompet utama');
        }
    }

    /**
     * Delete a balance
     */
    public function destroy(Request $request, $id)
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $balance = Balance::where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        // Soft delete: set is_active to false
        $balance->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Saldo akun berhasil dihapus');
    }
}

