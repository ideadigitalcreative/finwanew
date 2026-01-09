<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id;
        $tenant = Tenant::findOrFail($tenantId);

        $query = Transaction::where('tenant_id', $tenant->id)
            ->with(['category', 'message'])
            ->orderByRaw('COALESCE(created_at, updated_at, NOW()) DESC')  // Handle NULL created_at
            ->orderBy('id', 'desc');  // Secondary sort by ID to ensure consistent ordering

        // Filters
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        } elseif ($request->filled('category')) {
            // If category name is provided, find the category ID
            $categoryName = $request->category;
            $category = Category::where('tenant_id', $tenant->id)
                ->where('name', $categoryName)
                ->first();
                
            if ($category) {
                $query->where('category_id', $category->id);
                // Merge category_id into request so it's passed to frontend filters
                $request->merge(['category_id' => $category->id]);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->where('transaction_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        $transactions = $query->paginate(20)->withQueryString();

        // Get categories for filter
        $categories = Category::where('tenant_id', $tenant->id)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'categories' => $categories,
            'filters' => $request->only(['type', 'category_id', 'status', 'start_date', 'end_date', 'search'])
        ]);
    }

    public function show(Request $request, Transaction $transaction): Response
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $transaction->load(['category', 'message', 'reviewer']);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction
        ]);
    }

    public function updateStatus(Request $request, Transaction $transaction)
    {
        $user = $request->user();
        
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $request->validate([
            'status' => 'required|in:confirmed,review,rejected'
        ]);

        $transaction->update([
            'status' => $request->status,
            'reviewed_by' => $request->status !== 'review' ? $user->id : null,
            'reviewed_at' => $request->status !== 'review' ? now() : null
        ]);

        return redirect()->back()->with('success', 'Status transaksi berhasil diperbarui');
    }

    public function update(Request $request, Transaction $transaction)
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:0',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:255',
            'source' => 'nullable|string|max:255',
            'category_id' => 'required|integer|exists:categories,id',
            'status' => 'required|in:confirmed,review,rejected',
        ]);

        // Verify category belongs to tenant
        $category = Category::findOrFail((int)$validated['category_id']);
        if ($category->tenant_id !== $request->tenant_id) {
            return redirect()->back()->withErrors(['category_id' => 'Kategori tidak valid']);
        }

        // Convert category_id to integer
        $validated['category_id'] = (int)$validated['category_id'];
        
        $transaction->update($validated);

        return redirect()->back()->with('success', 'Transaksi berhasil diperbarui');
    }

    public function destroy(Request $request, Transaction $transaction)
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $transaction->delete();

        return redirect()->back()->with('success', 'Transaksi berhasil dihapus');
    }
}
