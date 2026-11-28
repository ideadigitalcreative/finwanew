<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\Category\CategoryManagerService;
use App\Services\DebtReceivable\CreditFacility;
use App\Services\DebtReceivable\DebtCashMode;
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
            // false = hutang/piutang lama / paylater: hanya dicatat, saldo dompet tidak berubah
            'affects_balance' => 'nullable|boolean',
        ]);

        $flow = $validated['kind'] === 'hutang' ? 'catat_hutang' : 'catat_piutang';

        return $this->persistDebtTransaction($request, $flow, $validated, 'debt_manual');
    }

    /**
     * Ubah hutang/piutang yang sudah tercatat: ikut menggerakkan saldo dompet
     * atau hanya dicatat. Saldo dikoreksi otomatis lewat TransactionLedger.
     */
    public function cashMode(Request $request, Transaction $transaction): RedirectResponse
    {
        $tenantId = (int) ($request->tenant_id ?? session('current_tenant_id') ?? $request->user()->tenant_id);
        if ((int) $transaction->tenant_id !== $tenantId) {
            abort(403);
        }

        $validated = $request->validate(['affects_balance' => 'required|boolean']);
        $affectsBalance = (bool) $validated['affects_balance'];

        $transaction->load('category');
        $balance = $affectsBalance ? app(BalanceService::class)->getDefaultBalance($tenantId) : null;
        if ($affectsBalance && ! $balance) {
            return redirect()->back()->with('error', 'Belum ada dompet aktif.');
        }

        $changed = DebtCashMode::convert($transaction, $affectsBalance, $balance, ['via' => 'dashboard', 'user_id' => $request->user()?->id]);
        if (! $changed) {
            return redirect()->back()->with('error', 'Transaksi ini tidak bisa diubah.');
        }

        return redirect()->back()->with('success', $affectsBalance
            ? "Dicatat ke dompet {$balance->account_name}. Saldo disesuaikan."
            : 'Diubah menjadi hanya dicatat. Saldo dompet dikembalikan.');
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

        // Hanya pencatatan hutang/piutang BARU yang boleh "hanya dicatat"; pelunasan selalu lewat dompet
        $recordOnly = in_array($flow, ['catat_hutang', 'catat_piutang'], true)
            && array_key_exists('affects_balance', $validated)
            && $validated['affects_balance'] !== null
            && ! (bool) $validated['affects_balance'];

        $balance = $recordOnly ? null : app(BalanceService::class)->getDefaultBalance($tenantId);

        $counterparty = trim($validated['counterparty']);
        $counterpartyNormalized = trim($validated['counterparty_normalized'] ?? '');
        if ($counterpartyNormalized === '') {
            $counterpartyNormalized = mb_strtolower(preg_replace('/\s+/u', ' ', $counterparty));
        }

        // Bayar tagihan paylater/kartu kredit yang belanjanya sudah tercatat sebagai
        // pengeluaran: dompet berkurang, tapi tidak dihitung pengeluaran lagi.
        $creditRepayment = $flow === 'bayar_hutang'
            && CreditFacility::purchaseOutstanding($tenantId, $counterpartyNormalized) > 0;

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

        // Catat + saldo dalam satu DB transaction (TransactionLedger)
        $transaction = app(\App\Services\Ledger\TransactionLedger::class)->record([
            'tenant_id' => $tenantId,
            'category_id' => $category->id,
            'balance_id' => $balance?->id,
            'type' => $recordOnly
                ? DebtCashMode::recordOnlyType($cfg['category_type'])
                : ($creditRepayment ? 'debit_internal' : $cfg['type']),
            'amount' => $validated['amount'],
            'transaction_date' => $validated['transaction_date'] ?? now()->toDateString(),
            'source' => $source,
            'description' => $description,
            'status' => 'confirmed',
            'confidence_score' => 1.0,
            'metadata' => array_merge([
                'counterparty' => $counterparty,
                'counterparty_normalized' => $counterpartyNormalized,
                'debt_flow' => $flow,
            ], $recordOnly ? ['kind' => Transaction::KIND_DEBT_RECORD_ONLY] : [],
                $creditRepayment ? ['kind' => Transaction::KIND_CREDIT_REPAYMENT] : []),
        ]);

        return redirect()->back()->with('success', $cfg['msg'].($recordOnly ? ' Saldo dompet tidak berubah.' : ''));
    }
}
