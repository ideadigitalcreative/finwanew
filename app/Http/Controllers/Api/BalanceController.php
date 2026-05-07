<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balance;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BalanceController extends Controller
{
    /**
     * Get balances for tenant
     */
    public function index(Request $request, $tenant = null)
    {
        try {
            $tenantId = $tenant ?? $request->input('tenant_id') ?? $request->tenant_id;

            if (! $tenantId && auth()->check()) {
                $tenantId = auth()->user()->currentTenant()->id;
            }

            $tenantModel = Tenant::findOrFail($tenantId);

            $balances = Balance::where('tenant_id', $tenantModel->id)
                ->where('is_active', true)
                ->orderBy('account_type')
                ->orderBy('account_name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $balances,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a new balance
     */
    public function store(Request $request, $tenant = null)
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
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tenantId = $tenant ?? $request->input('tenant_id') ?? $request->tenant_id;

            if (! $tenantId && auth()->check()) {
                $tenantId = auth()->user()->currentTenant()->id;
            }

            $tenantModel = Tenant::findOrFail($tenantId);

            $balance = Balance::create([
                'tenant_id' => $tenantModel->id,
                'account_name' => $request->input('account_name'),
                'account_number' => $request->input('account_number'),
                'account_type' => $request->input('account_type'),
                'currency' => $request->input('currency', 'IDR'),
                'balance' => $request->input('balance'),
                'balance_date' => $request->input('balance_date'),
                'is_active' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Balance created successfully',
                'data' => $balance,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating balance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a balance
     */
    public function update(Request $request, $tenant = null, $id = null)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'sometimes|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_type' => 'sometimes|in:bank,cash,wallet,investment,other',
            'currency' => 'nullable|string|size:3',
            'balance' => 'sometimes|numeric|min:0',
            'balance_date' => 'sometimes|date',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tenantId = $tenant ?? $request->input('tenant_id') ?? $request->tenant_id;
            $balanceId = $id ?? $request->input('id');

            $balance = Balance::where('tenant_id', $tenantId)
                ->where('id', $balanceId)
                ->firstOrFail();

            $balance->update($request->only([
                'account_name',
                'account_number',
                'account_type',
                'currency',
                'balance',
                'balance_date',
                'is_active',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Balance updated successfully',
                'data' => $balance,
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating balance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a balance
     */
    public function destroy(Request $request, $tenant = null, $id = null)
    {
        try {
            $tenantId = $tenant ?? $request->input('tenant_id') ?? $request->tenant_id;
            $balanceId = $id ?? $request->input('id');

            $balance = Balance::where('tenant_id', $tenantId)
                ->where('id', $balanceId)
                ->firstOrFail();

            app(TenantProvisioningService::class)->permanentlyDeleteBalance($balance);

            return response()->json([
                'success' => true,
                'message' => 'Balance deleted permanently',
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting balance', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
