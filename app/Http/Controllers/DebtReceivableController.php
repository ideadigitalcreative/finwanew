<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\Category\CategoryManagerService;
use App\Services\DebtReceivable\DebtReceivableLedgerService;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DebtReceivableController extends Controller
{
    public function index(Request $request, DebtReceivableLedgerService $ledger): Response
    {
        $tenantId = (int) ($request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id);

        $summary = $ledger->summarize($tenantId);

        return Inertia::render('DebtReceivable/Index', [
            'summary' => $summary,
        ]);
    }

    /**
     * Catat hutang/piutang baru manual dari UI.
     * kind=hutang  → uang masuk (dapat pinjaman)  → pendapatan_hutang
     * kind=piutang → uang keluar (kasih pinjaman) → pengeluaran_piutang
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => 'required|in:hutang,piutang',
            'counterparty' => 'required|string|max:80',
            'amount' => 'required|numeric|min:1',
            'transaction_date' => 'nullable|date',
            'description' => 'nullable|string|max:255',
        ]);

        $flow = $validated['kind'] === 'hutang' ? 'catat_hutang' : 'catat_piutang';

        return $this->persistDebtTransaction($request, $flow, $validated, 'debt_manual');
    }

    /**
     * Bayar hutang / terima pelunasan piutang dari UI.
     */
    public function settle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => 'required|in:hutang,piutang',
            'counterparty' => 'required|string|max:80',
            'counterparty_normalized' => 'nullable|string|max:80',
            'amount' => 'required|numeric|min:1',
            'transaction_date' => 'nullable|date',
            'description' => 'nullable|string|max:255',
        ]);

        $flow = $validated['kind'] === 'hutang' ? 'bayar_hutang' : 'terima_piutang';

        return $this->persistDebtTransaction($request, $flow, $validated, 'debt_settlement');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function persistDebtTransaction(Request $request, string $flow, array $validated, string $source): RedirectResponse
    {
        $tenantId = (int) ($request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id);

        $limitService = app(SubscriptionLimitService::class);
        if (! $limitService->canCreateTransaction($tenantId)['can_create']) {
            return redirect()->back()->with('error', 'Batas transaksi bulanan tercapai.');
        }

        $map = [
            'catat_hutang' => ['category_type' => 'pendapatan_hutang', 'type' => 'income', 'msg' => 'Hutang dicatat.'],
            'catat_piutang' => ['category_type' => 'pengeluaran_piutang', 'type' => 'expense', 'msg' => 'Piutang dicatat.'],
            'bayar_hutang' => ['category_type' => 'pengeluaran_bayar_hutang', 'type' => 'expense', 'msg' => 'Pembayaran hutang dicatat.'],
            'terima_piutang' => ['category_type' => 'pendapatan_terima_piutang', 'type' => 'income', 'msg' => 'Pelunasan piutang dicatat.'],
        ];
        $cfg = $map[$flow];

        $category = Category::where('tenant_id', $tenantId)->where('type', $cfg['category_type'])->first();
        if (! $category) {
            app(CategoryManagerService::class)->createCategoriesForTenant($tenantId);
            $category = Category::where('tenant_id', $tenantId)->where('type', $cfg['category_type'])->first();
        }
        if (! $category) {
            return redirect()->back()->with('error', 'Kategori hutang/piutang tidak ditemukan.');
        }

        $balance = app(BalanceService::class)->getDefaultBalance($tenantId);

        $counterparty = trim($validated['counterparty']);
        $counterpartyNormalized = trim($validated['counterparty_normalized'] ?? '');
        if ($counterpartyNormalized === '') {
            $counterpartyNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', $counterparty));
        }

        $description = trim($validated['description'] ?? '');
        if ($description === '') {
            $desc = [
                'catat_hutang' => "Hutang ke {$counterparty}",
                'catat_piutang' => "Piutang dari {$counterparty}",
                'bayar_hutang' => "Bayar hutang ke {$counterparty}",
                'terima_piutang' => "Terima pelunasan piutang dari {$counterparty}",
            ];
            $description = $desc[$flow];
        }

        $transaction = Transaction::create([
            'tenant_id' => $tenantId,
            'category_id' => $category->id,
            'balance_id' => $balance?->id,
            'type' => $cfg['type'],
            'amount' => $validated['amount'],
            'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
            'source' => $source,
            'description' => $description,
            'status' => 'confirmed',
            'confidence_score' => 1.0,
            'metadata' => [
                'counterparty' => $counterparty,
                'counterparty_normalized' => $counterpartyNormalized,
                'debt_flow' => $flow,
            ],
        ]);

        if ($balance) {
            if ($cfg['type'] === 'income') {
                $balance->increment('balance', $transaction->amount);
            } else {
                $balance->decrement('balance', $transaction->amount);
            }
        }

        return redirect()->back()->with('success', $cfg['msg']);
    }
}
