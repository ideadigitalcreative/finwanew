<?php

/**
 * Tahap C — fondasi saldo:
 *  - TransactionLedger: catat/ubah/hapus/pulihkan + saldo dalam satu DB transaction
 *  - transfer = pasangan terkunci (linked_transaction_id), hapus/ubah kena dua sisi
 *  - soft delete + "kembalikan" + audit semua perubahan
 *  - saldo awal / penyesuaian = jenis khusus, bukan pemasukan
 *  - finwa:reconcile
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionAudit;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\Ledger\TransactionLedger;
use App\Services\Transaction\TransactionService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function ledgerSend(string $text): string
{
    $GLOBALS['ledger_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000333', 'sender_id' => '6281800000033',
        'message_id' => 'led-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['ledger_replies']);
}

function ledgerExpense(int $amount, ?Balance $wallet = null, string $desc = 'beli kopi'): Transaction
{
    return app(TransactionLedger::class)->record([
        'tenant_id' => test()->tenant->id,
        'balance_id' => ($wallet ?? test()->cash)->id,
        'category_id' => Category::where('tenant_id', test()->tenant->id)->where('type', 'like', 'pengeluaran%')->value('id'),
        'type' => 'expense',
        'amount' => $amount,
        'description' => $desc,
        'transaction_date' => now()->toDateString(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['ledger_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['ledger_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Ledger', 'slug' => 'ledger', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000333',
        'name' => 'Ledger', 'config' => ['session_id' => 'wa_ledger'], 'is_active' => true,
    ]);
    $this->user = User::create([
        'name' => 'Ledger', 'email' => 'ledger@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000033', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->cash = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
    $this->bca = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'BCA', 'account_type' => 'bank', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);
    $this->ledger = app(TransactionLedger::class);
    $this->ledger->setBalanceTo($this->cash, 1_000_000, Transaction::KIND_OPENING_BALANCE);
    $this->ledger->setBalanceTo($this->bca, 2_000_000, Transaction::KIND_OPENING_BALANCE);
});

// ── Ledger dasar ─────────────────────────────────────────────────────────────

it('catat, ubah nominal/jenis/status, hapus & pulihkan — saldo selalu konsisten', function () {
    $tx = ledgerExpense(50_000);
    expect((float) $this->cash->fresh()->balance)->toBe(950_000.0);

    $this->ledger->update($tx, ['amount' => 80_000]);
    expect((float) $this->cash->fresh()->balance)->toBe(920_000.0);

    $this->ledger->update($tx, ['type' => 'income']);
    expect((float) $this->cash->fresh()->balance)->toBe(1_080_000.0);

    $this->ledger->update($tx, ['status' => 'rejected']);
    expect((float) $this->cash->fresh()->balance)->toBe(1_000_000.0);

    $this->ledger->update($tx, ['status' => 'confirmed', 'balance_id' => $this->bca->id]);
    expect((float) $this->cash->fresh()->balance)->toBe(1_000_000.0)
        ->and((float) $this->bca->fresh()->balance)->toBe(2_080_000.0);

    $this->ledger->delete($tx);
    expect(Transaction::find($tx->id))->toBeNull()
        ->and(Transaction::withTrashed()->find($tx->id)->trashed())->toBeTrue()
        ->and((float) $this->bca->fresh()->balance)->toBe(2_000_000.0);

    $this->ledger->restore(Transaction::withTrashed()->find($tx->id));
    expect((float) $this->bca->fresh()->balance)->toBe(2_080_000.0);

    foreach ([$this->cash, $this->bca] as $wallet) {
        expect(TransactionLedger::computedBalance($wallet))->toBe((float) $wallet->fresh()->balance);
    }
});

it('mencatat audit untuk setiap perubahan', function () {
    $tx = ledgerExpense(50_000);
    $this->ledger->update($tx, ['amount' => 70_000], ['via' => 'dashboard', 'user_id' => $this->user->id]);
    $this->ledger->delete($tx);
    $this->ledger->restore(Transaction::withTrashed()->find($tx->id));

    $audits = TransactionAudit::where('transaction_id', $tx->id)->orderBy('id')->get();
    expect($audits->pluck('action')->all())->toBe(['created', 'updated', 'deleted', 'restored'])
        ->and($audits[1]->via)->toBe('dashboard')
        ->and($audits[1]->user_id)->toBe($this->user->id)
        ->and($audits[1]->changes['amount'])->toEqual([50000, 70000]);
});

it('transfer = pasangan terkunci; hapus/ubah/pulihkan kena dua sisi', function () {
    [$debit, $credit] = $this->ledger->transfer($this->bca, $this->cash, 300_000);

    expect($debit->linked_transaction_id)->toBe($credit->id)
        ->and($credit->linked_transaction_id)->toBe($debit->id)
        ->and((float) $this->bca->fresh()->balance)->toBe(1_700_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_300_000.0);

    $this->ledger->update($credit, ['amount' => 500_000]);
    expect((float) $debit->fresh()->amount)->toBe(500_000.0)
        ->and((float) $this->bca->fresh()->balance)->toBe(1_500_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_500_000.0);

    $this->ledger->delete($credit);
    expect(Transaction::find($debit->id))->toBeNull()
        ->and((float) $this->bca->fresh()->balance)->toBe(2_000_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_000_000.0);

    $this->ledger->restore(Transaction::withTrashed()->find($debit->id));
    expect(Transaction::find($credit->id))->not->toBeNull()
        ->and((float) $this->bca->fresh()->balance)->toBe(1_500_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_500_000.0);
});

it('saldo awal & penyesuaian bukan pemasukan/pengeluaran', function () {
    $tx = $this->ledger->setBalanceTo($this->cash, 750_000);

    expect($tx->type)->toBe('debit_internal')
        ->and((float) $tx->amount)->toBe(250_000.0)
        ->and($tx->isBalanceAdjustment())->toBeTrue()
        ->and((float) $this->cash->fresh()->balance)->toBe(750_000.0)
        ->and(Transaction::where('tenant_id', $this->tenant->id)->whereIn('type', ['income', 'expense'])->count())->toBe(0)
        ->and($this->ledger->setBalanceTo($this->cash, 750_000))->toBeNull(); // sudah sama → tidak ada baris
});

// ── WhatsApp ─────────────────────────────────────────────────────────────────

it('WA: hapus lalu "kembalikan" memulihkan transaksi & saldo', function () {
    ledgerSend('beli kopi 25rb');
    $tx = Transaction::where('tenant_id', $this->tenant->id)->where('type', 'expense')->sole();
    expect((float) $this->cash->fresh()->balance)->toBe(975_000.0);

    ledgerSend('batal');
    $deleted = ledgerSend('ya');
    expect($deleted)->toContain('kembalikan')
        ->and(Transaction::find($tx->id))->toBeNull()
        ->and((float) $this->cash->fresh()->balance)->toBe(1_000_000.0);

    $restored = ledgerSend('kembalikan');
    expect($restored)->toContain('Transaksi Dikembalikan')
        ->and(Transaction::find($tx->id))->not->toBeNull()
        ->and((float) $this->cash->fresh()->balance)->toBe(975_000.0);

    expect(ledgerSend('kembalikan'))->toContain('Tidak ada transaksi terhapus');
});

it('mengenali perintah kembalikan', function (string $text, bool $expected) {
    expect(TransactionService::isRestoreCommand($text))->toBe($expected);
})->with([
    ['kembalikan', true],
    ['kembalikan transaksi yang tadi dihapus', true],
    ['batal hapus', true],
    ['ga jadi hapus', true],
    ['pulihkan', true],
    ['dapat kembalian 5rb', false],
    ['balikin pinjaman ke budi 100rb', false],
]);

it('WA: "saldo awal 3jt" → baris saldo awal, bukan pemasukan', function () {
    ledgerSend('saldo awal 3jt');

    $opening = Transaction::where('tenant_id', $this->tenant->id)->latest('id')->first();
    expect((float) $this->cash->fresh()->balance)->toBe(3_000_000.0)
        ->and($opening->kind())->toBe(Transaction::KIND_OPENING_BALANCE)
        ->and($opening->type)->toBe('kredit_internal')
        ->and(Transaction::where('tenant_id', $this->tenant->id)->where('type', 'income')->count())->toBe(0)
        ->and(TransactionLedger::computedBalance($this->cash))->toBe(3_000_000.0);
});

it('WA: transfer antar dompet tercatat berpasangan & audit via whatsapp', function () {
    ledgerSend('transfer 100rb dari BCA ke Dompet Utama');

    $pair = Transaction::where('tenant_id', $this->tenant->id)->whereNotNull('linked_transaction_id')->get();
    expect($pair)->toHaveCount(2)
        ->and((float) $this->bca->fresh()->balance)->toBe(1_900_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_100_000.0)
        ->and(TransactionAudit::whereIn('transaction_id', $pair->pluck('id'))->where('action', 'created')->pluck('via')->unique()->all())->toBe(['whatsapp']);

    // Hapus satu sisi (transaksi terakhir) → dua sisi terhapus, saldo kembali
    ledgerSend('batal');
    ledgerSend('ya');
    expect(Transaction::where('tenant_id', $this->tenant->id)->whereNotNull('linked_transaction_id')->count())->toBe(0)
        ->and((float) $this->bca->fresh()->balance)->toBe(2_000_000.0)
        ->and((float) $this->cash->fresh()->balance)->toBe(1_000_000.0);
});

it('WA: set saldo dompet tercatat sebagai penyesuaian & tetap cocok dengan riwayat', function () {
    ledgerSend('beli kopi 25rb');
    ledgerSend('set saldo BCA 1,5jt');

    expect((float) $this->bca->fresh()->balance)->toBe(1_500_000.0)
        ->and(TransactionLedger::computedBalance($this->bca->fresh()))->toBe(1_500_000.0)
        ->and(TransactionLedger::computedBalance($this->cash->fresh()))->toBe((float) $this->cash->fresh()->balance);
});

// ── Dashboard ────────────────────────────────────────────────────────────────

it('dashboard: hapus = soft delete + saldo kembali + audit via dashboard', function () {
    $this->user->forceFill(['email_verified_at' => now()])->save();
    $this->user->tenants()->attach($this->tenant->id, ['is_active' => true]);
    $tx = ledgerExpense(40_000);

    $this->actingAs($this->user)->withSession(['current_tenant_id' => $this->tenant->id])
        ->delete(route('transactions.destroy', $tx))
        ->assertRedirect();

    expect(Transaction::withTrashed()->find($tx->id)->trashed())->toBeTrue()
        ->and((float) $this->cash->fresh()->balance)->toBe(1_000_000.0)
        ->and(TransactionAudit::where('transaction_id', $tx->id)->where('action', 'deleted')->value('via'))->toBe('dashboard');
});

// ── Rekonsiliasi ─────────────────────────────────────────────────────────────

it('finwa:reconcile menemukan selisih lama dan --fix mencatat saldo dasar tanpa mengubah saldo', function () {
    // Data lama: saldo diubah langsung tanpa baris transaksi
    Balance::whereKey($this->bca->id)->update(['balance' => 2_500_000]);

    $this->artisan('finwa:reconcile', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('1 dompet tidak cocok')
        ->assertSuccessful();

    $this->artisan('finwa:reconcile', ['--tenant' => $this->tenant->id, '--fix' => true])->assertSuccessful();

    expect((float) $this->bca->fresh()->balance)->toBe(2_500_000.0)
        ->and(TransactionLedger::computedBalance($this->bca))->toBe(2_500_000.0);

    $this->artisan('finwa:reconcile', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('Semua saldo cocok')
        ->assertSuccessful();

    // Baris saldo dasar ada di AWAL riwayat & terkunci: "batal" tidak menyentuhnya
    $baseline = Transaction::where('source', Transaction::KIND_RECONCILE_BASELINE)->sole();
    expect($baseline->created_at->lt(Transaction::where('balance_id', $this->bca->id)->where('id', '!=', $baseline->id)->min('created_at')))->toBeTrue();
    expect(fn () => $this->ledger->delete($baseline))->toThrow(\DomainException::class);

    $kopi = ledgerExpense(10_000); // transaksi user terbaru
    ledgerSend('batal');
    ledgerSend('ya');
    expect(Transaction::find($kopi->id))->toBeNull()
        ->and(Transaction::find($baseline->id))->not->toBeNull()
        ->and((float) $this->bca->fresh()->balance)->toBe(2_500_000.0);
});
