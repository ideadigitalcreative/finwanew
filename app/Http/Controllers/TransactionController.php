<?php

namespace App\Http\Controllers;

use App\Models\Balance;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\GeminiAIService;
use App\Services\SubscriptionLimitService;
use App\Services\Transaction\TransactionParserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $limitService = app(\App\Services\SubscriptionLimitService::class);
        $txLimit = $limitService->canCreateTransaction($tenant->id);

        return Inertia::render('Transactions/Index', [
            'transactions' => $transactions,
            'categories' => $categories,
            'filters' => $request->only(['type', 'category_id', 'status', 'start_date', 'end_date', 'search']),
            'transactionLimit' => $txLimit,
        ]);
    }

    public function show(Request $request, Transaction $transaction): Response
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $transaction->load(['category', 'message.ocrJob', 'reviewer']);

        return Inertia::render('Transactions/Show', [
            'transaction' => $transaction,
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
            'status' => 'required|in:confirmed,review,rejected',
        ]);

        $oldStatus = $transaction->status;

        $transaction->update([
            'status' => $request->status,
            'reviewed_by' => $request->status !== 'review' ? $user->id : null,
            'reviewed_at' => $request->status !== 'review' ? now() : null,
        ]);

        // Sync Balance
        $balanceService = app(\App\Services\BalanceService::class);
        if ($oldStatus !== 'confirmed' && $transaction->status === 'confirmed') {
            $balanceService->updateBalanceFromTransaction($transaction);
        } elseif ($oldStatus === 'confirmed' && $transaction->status !== 'confirmed') {
            $balanceService->reverseBalanceUpdate($transaction);
        }

        return redirect()->back()->with('success', 'Status transaksi berhasil diperbarui');
    }

    public function update(Request $request, Transaction $transaction)
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $validated = $request->validate([
            'type' => 'required|in:income,expense,debit_internal,kredit_internal',
            'amount' => 'required|numeric|min:0',
            'transaction_date' => 'required|date',
            'description' => 'required|string|max:255',
            'source' => 'nullable|string|max:255',
            'category_id' => 'required|integer|exists:categories,id',
            'status' => 'required|in:confirmed,review,rejected',
        ]);

        // Verify category belongs to tenant
        $category = Category::findOrFail((int) $validated['category_id']);
        if ($category->tenant_id !== $request->tenant_id) {
            return redirect()->back()->withErrors(['category_id' => 'Kategori tidak valid']);
        }

        // Store old values for balance sync
        $oldBalanceId = $transaction->balance_id;
        $oldType = $transaction->type;
        $oldAmount = $transaction->amount;
        $oldStatus = $transaction->status;

        // Convert category_id to integer
        $validated['category_id'] = (int) $validated['category_id'];

        $transaction->update($validated);

        // Sync Balance
        $balanceService = app(\App\Services\BalanceService::class);

        // If it was confirmed, reverse the old impact
        if ($oldStatus === 'confirmed' && $oldBalanceId) {
            $oldTx = clone $transaction;
            $oldTx->balance_id = $oldBalanceId;
            $oldTx->type = $oldType;
            $oldTx->amount = $oldAmount;
            $balanceService->reverseBalanceUpdate($oldTx);
        }

        // If it is now confirmed, apply the new impact
        if ($transaction->status === 'confirmed' && $transaction->balance_id) {
            $balanceService->updateBalanceFromTransaction($transaction);
        }

        return redirect()->back()->with('success', 'Transaksi berhasil diperbarui');
    }

    public function destroy(Request $request, Transaction $transaction)
    {
        // Verify transaction belongs to current tenant
        if ($transaction->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        // Sync Balance before deletion
        if ($transaction->status === 'confirmed' && $transaction->balance_id) {
            app(\App\Services\BalanceService::class)->reverseBalanceUpdate($transaction);
        }

        $transaction->delete();

        return redirect()->back()->with('success', 'Transaksi berhasil dihapus');
    }

    public function parse(Request $request, TransactionParserService $parser)
    {
        $user = $request->user();
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $user->tenant_id;

        if (! $tenantId) {
            return response()->json(['success' => false, 'error' => 'Tenant tidak ditemukan'], 404);
        }

        $request->validate([
            'text' => 'required|string|max:500',
        ]);

        $result = $parser->parse($request->text, $tenantId);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function parseReceipt(Request $request)
    {
        try {
            $user = $request->user();
            $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $user->tenant_id;

            if (! $tenantId) {
                return response()->json(['success' => false, 'error' => 'Tenant tidak ditemukan'], 404);
            }

            $request->validate([
                'image' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240',
            ]);

            $file = $request->file('image');
            $fileContent = file_get_contents($file->getRealPath());
            $base64 = base64_encode($fileContent);

            $mimeType = $file->getMimeType() ?: 'image/jpeg';
            $dataUri = "data:{$mimeType};base64,{$base64}";

            $gemini = app(GeminiAIService::class);

            if (! $gemini->isAvailable()) {
                return response()->json(['success' => false, 'error' => 'Layanan AI tidak tersedia'], 503);
            }

            $parsed = $gemini->extractReceiptData($dataUri);

            if (! $parsed || empty($parsed['total_amount'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Gambar tidak dikenali sebagai struk. Pastikan foto struk belanja yang jelas.',
                ], 422);
            }

            $total = (int) $parsed['total_amount'];
            $merchant = $parsed['merchant_name'] ?? null;
            $dateRaw = $parsed['date'] ?? null;
            $items = $parsed['items'] ?? [];

            $transactionDate = $this->parseReceiptDate($dateRaw);

            $description = $merchant ? "Belanja di {$merchant}" : 'Belanja dari struk';

            $category = \App\Models\Category::where('tenant_id', $tenantId)
                ->where('type', 'pengeluaran_belanja')
                ->first();

            $balances = \App\Models\Balance::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'account_name as name', 'account_type as type', 'balance', 'currency']);

            return response()->json([
                'success' => true,
                'type' => 'expense',
                'amount' => $total,
                'description' => $description,
                'category_id' => $category?->id,
                'category_type' => 'pengeluaran_belanja',
                'category_name' => $category?->name ?? 'Belanja',
                'category_icon' => $category?->icon ?? '🛒',
                'date' => $transactionDate,
                'confidence' => 0.95,
                'merchant' => $merchant,
                'items' => $items,
                'default_balance_id' => $balances->first()?->id,
                'balances' => $balances->toArray(),
                'alternatives' => [],
                'source' => 'receipt_ocr',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'error' => 'File tidak valid: '.$e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('parseReceipt error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['success' => false, 'error' => 'Gagal memproses struk. Silakan coba lagi.'], 500);
        }
    }

    private function parseReceiptDate(?string $dateRaw): string
    {
        if (! $dateRaw) {
            return date('Y-m-d');
        }

        try {
            if (preg_match('/^(\d{4})[\.\/\-](\d{2})[\.\/\-](\d{2})$/', trim($dateRaw), $m)) {
                $year = (int) $m[1];
                $currentYear = (int) date('Y');
                if ($year >= $currentYear - 2 && $year <= $currentYear + 1) {
                    return "{$m[1]}-{$m[2]}-{$m[3]}";
                }
            }

            if (preg_match('/^(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{2})$/', trim($dateRaw), $m)) {
                $year = "20{$m[3]}";
                $currentYear = (int) date('Y');
                if ((int) $year >= $currentYear - 2 && (int) $year <= $currentYear + 1) {
                    return "{$year}-{$m[2]}-{$m[1]}";
                }
            }

            if (preg_match('/^(\d{2})[\.\/\-](\d{2})[\.\/\-](\d{4})$/', trim($dateRaw), $m)) {
                return "{$m[3]}-{$m[2]}-{$m[1]}";
            }

            $parsed = Carbon::parse($dateRaw);

            return $parsed->format('Y-m-d');
        } catch (\Exception $e) {
            return date('Y-m-d');
        }
    }

    public function storeJson(Request $request)
    {
        $user = $request->user();
        $tenantId = $request->tenant_id ?? session('current_tenant_id') ?? $user->tenant_id;

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|in:income,expense',
            'amount' => 'required|numeric|min:100',
            'description' => 'required|string|max:255',
            'category_id' => 'nullable|integer|exists:categories,id',
            'balance_id' => 'nullable|integer|exists:balances,id',
            'transaction_date' => 'nullable|date',
            'status' => 'nullable|in:confirmed,review',
            'source' => 'nullable|string|max:50',
        ]);

        $limitService = app(SubscriptionLimitService::class);
        $limitCheck = $limitService->canCreateTransaction($tenantId);

        if (! $limitCheck['can_create']) {
            return response()->json([
                'message' => 'Batas transaksi bulanan tercapai',
                'limit' => $limitCheck['limit'],
                'current' => $limitCheck['current'],
                'plan' => $limitCheck['plan'],
            ], 403);
        }

        try {
            DB::beginTransaction();

            $balanceId = $validated['balance_id'] ?? Balance::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('id')
                ->value('id');

            $transaction = Transaction::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'type' => $validated['type'],
                'amount' => $validated['amount'],
                'description' => $validated['description'],
                'category_id' => $validated['category_id'],
                'balance_id' => $balanceId,
                'transaction_date' => $validated['transaction_date'] ?? Carbon::now('Asia/Jakarta')->toDateString(),
                'status' => $validated['status'] ?? 'confirmed',
                'source' => $validated['source'] ?? 'dashboard',
            ]);

            if ($transaction->status === 'confirmed' && $balanceId) {
                $balance = Balance::find($balanceId);
                if ($balance) {
                    if ($transaction->type === 'income') {
                        $balance->increment('balance', $transaction->amount);
                    } else {
                        $balance->decrement('balance', $transaction->amount);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Transaksi berhasil disimpan',
                'transaction' => $transaction->load(['category', 'balance']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Gagal menyimpan transaksi', 'error' => $e->getMessage()], 500);
        }
    }
}
