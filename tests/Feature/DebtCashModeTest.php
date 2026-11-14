<?php

/**
 * Hutang/piutang "hanya dicatat":
 *  - hutang lama / paylater / kartu kredit TIDAK menambah saldo dompet
 *  - pinjaman yang benar-benar diterima tetap masuk dompet
 *  - kalimat ambigu ("hutang budi 2jt") → tanya bernomor
 *  - "hanya dicatat" mengubah hutang yang terlanjur masuk dompet
 *  - baris hanya-dicatat tetap dihitung di daftar hutang/piutang
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\DebtReceivable\CreditFacility;
use App\Services\DebtReceivable\DebtCashMode;
use App\Services\DebtReceivable\DebtReceivableLedgerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function dcmSend(string $text): string
{
    $GLOBALS['dcm_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000333', 'sender_id' => '6281800000033',
        'message_id' => 'dcm-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['dcm_replies']);
}

function dcmTx()
{
    return Transaction::where('tenant_id', test()->tenant->id)
        ->where(fn ($q) => $q->whereNull('source')->orWhereNotIn('source', ['opening_balance', 'balance_adjustment']))
        ->get();
}

/** Login tanpa middleware keanggotaan tenant/langganan — yang diuji logika controller. */
function dcmAsUser()
{
    return test()->actingAs(test()->user)->withoutMiddleware([
        \App\Http\Middleware\EnsureTenantAccess::class,
        \App\Http\Middleware\CheckSubscription::class,
        \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ]);
}

function dcmWallet(): float
{
    return (float) test()->cash->fresh()->balance;
}

function dcmOutstanding(string $kind): float
{
    $summary = app(DebtReceivableLedgerService::class)->summarize(test()->tenant->id);

    return (float) array_sum(array_column($summary[$kind], 'outstanding'));
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['dcm_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['dcm_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Hutang', 'slug' => 'hutang', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000333',
        'name' => 'Hutang', 'config' => ['session_id' => 'wa_hutang'], 'is_active' => true,
    ]);
    $this->user = User::create([
        'name' => 'Hutang', 'email' => 'hutang@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000033', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->cash = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1_000_000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
});

// ── Detektor ────────────────────────────────────────────────────────────────

it('keputusan tegas: hanya kalimat eksplisit yang tidak ditanya', function (string $text, string $expected) {
    expect(DebtCashMode::detect($text))->toBe($expected);
})->with([
    // menyebut hutang/piutang tanpa pernyataan eksplisit → tanya
    ['hutang SpayLater 2jt', DebtCashMode::AMBIGUOUS],
    ['punya hutang SpayPinjam 3 juta', DebtCashMode::AMBIGUOUS],
    ['Hutang Spay Pinjam 3jt', DebtCashMode::AMBIGUOUS],
    ['utang kartu kredit BCA 3jt', DebtCashMode::AMBIGUOUS],
    ['punya hutang ke budi 2jt', DebtCashMode::AMBIGUOUS],
    ['masih utang sama andi 300rb', DebtCashMode::AMBIGUOUS],
    ['catat hutang budi 1jt', DebtCashMode::AMBIGUOUS],
    // eksplisit hanya dicatat
    ['hutang lama ke budi 2jt', DebtCashMode::RECORD_ONLY],
    ['hutang lama SpayLater 2jt', DebtCashMode::RECORD_ONLY],
    ['piutang lama noki 5jt', DebtCashMode::RECORD_ONLY],
    ['hutang budi 2jt jangan masuk saldo', DebtCashMode::RECORD_ONLY],
    ['hutang budi 2jt hanya dicatat', DebtCashMode::RECORD_ONLY],
    // eksplisit uang bergerak
    ['hutang, pinjam dari budi 2jt', DebtCashMode::CASH],
    ['pinjam dari budi 2jt', DebtCashMode::CASH],
    ['dapat pinjaman dari bank 10jt', DebtCashMode::CASH],
    ['pinjaman kredivo cair 2jt', DebtCashMode::CASH],
    ['kasih pinjam noki 500rb', DebtCashMode::CASH],
    ['budi pinjam 100rb', DebtCashMode::CASH],
    ['hutang budi 2jt', DebtCashMode::AMBIGUOUS],
    ['piutang noki 20jt', DebtCashMode::AMBIGUOUS],
]);

it('tebakan terbaik saat tidak bisa bertanya (grup)', function (string $text, string $expected) {
    expect(DebtCashMode::guess($text))->toBe($expected);
})->with([
    ['hutang SpayLater 2jt', DebtCashMode::RECORD_ONLY],
    ['utang kartu kredit BCA 3jt', DebtCashMode::RECORD_ONLY],
    ['punya hutang ke budi 2jt', DebtCashMode::RECORD_ONLY],
    ['pinjam dari budi 2jt', DebtCashMode::CASH],
    ['hutang budi 2jt', DebtCashMode::AMBIGUOUS],
]);

// ── WhatsApp ────────────────────────────────────────────────────────────────

it('WA: hutang baru tidak langsung dicatat — tanya 1/2 dulu', function (string $text, int $amount, string $party) {
    $ask = dcmSend($text);
    expect($ask)->toContain('uangnya gimana')->toContain('1️⃣')->toContain('2️⃣')->toContain($party)
        ->and(dcmTx())->toHaveCount(0)
        ->and(dcmWallet())->toBe(1_000_000.0);

    $reply = dcmSend('2');
    $tx = dcmTx()->sole();
    expect($tx->category->type)->toBe('pendapatan_hutang')
        ->and($tx->isDebtRecordOnly())->toBeTrue()
        ->and((int) $tx->amount)->toBe($amount)
        ->and(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe((float) $amount)
        ->and($reply)->toContain('tidak berubah');
})->with([
    ['punya hutang SpayPinjam 3 juta', 3_000_000, 'Spaypinjam'],
    ['Hutang Spay Pinjam 3jt', 3_000_000, 'Spay Pinjam'],
    ['hutang SpayLater 2jt', 2_000_000, 'SPayLater'],
    ['punya hutang ke budi 2jt', 2_000_000, 'Budi'],
]);

it('WA: "Hutang Spay Pinjam 3jt" → jawab 1 = uang masuk dompet sebagai hutang (bukan piutang)', function () {
    dcmSend('Hutang Spay Pinjam 3jt');
    dcmSend('1');

    $tx = dcmTx()->sole();
    expect($tx->type)->toBe('income')
        ->and($tx->category->type)->toBe('pendapatan_hutang')
        ->and(dcmWallet())->toBe(4_000_000.0);
});

it('WA: "hutang lama …" langsung hanya dicatat — saldo dompet tidak bertambah', function (string $text) {
    $reply = dcmSend($text);

    $tx = dcmTx()->sole();
    expect($tx->category->type)->toBe('pendapatan_hutang')
        ->and($tx->type)->toBe('kredit_internal')
        ->and($tx->balance_id)->toBeNull()
        ->and($tx->isDebtRecordOnly())->toBeTrue()
        ->and((int) $tx->amount)->toBe(2_000_000)
        ->and(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(2_000_000.0)
        ->and($reply)->toContain('hanya dicatat')->toContain('tidak berubah');
})->with([
    ['hutang lama SpayLater 2jt'],
    ['hutang lama ke budi 2jt'],
    ['hutang lama budi 2jt'],
]);

it('WA: hutang hanya-dicatat tidak terhitung sebagai pemasukan', function () {
    dcmSend('hutang lama SpayLater 2jt');

    expect(Transaction::where('tenant_id', $this->tenant->id)->where('type', 'income')->count())->toBe(0);
});

it('WA: "pinjam dari budi 2jt" tetap masuk dompet', function () {
    $reply = dcmSend('pinjam dari budi 2jt');

    $tx = dcmTx()->sole();
    expect($tx->type)->toBe('income')
        ->and($tx->category->type)->toBe('pendapatan_hutang')
        ->and($tx->balance_id)->toBe($this->cash->id)
        ->and(dcmWallet())->toBe(3_000_000.0)
        ->and($reply)->toContain('hanya dicatat'); // petunjuk cara mengubah
});

it('WA: "hutang budi 2jt" → tanya; jawaban menentukan saldo', function (string $answer, string $type, float $wallet) {
    $ask = dcmSend('hutang budi 2jt');
    expect($ask)->toContain('uangnya gimana')->toContain('Rp 2.000.000')
        ->and(dcmTx())->toHaveCount(0);

    dcmSend($answer);
    $tx = dcmTx()->sole();
    expect($tx->type)->toBe($type)
        ->and($tx->category->type)->toBe('pendapatan_hutang')
        ->and((int) $tx->amount)->toBe(2_000_000)
        ->and(dcmWallet())->toBe($wallet)
        ->and(dcmOutstanding('hutang'))->toBe(2_000_000.0);
})->with([
    ['1', 'income', 3_000_000.0],
    ['2', 'kredit_internal', 1_000_000.0],
    ['lama', 'kredit_internal', 1_000_000.0],
    ['hanya dicatat', 'kredit_internal', 1_000_000.0],
]);

it('WA: "piutang noki 500rb" → tanya; piutang lama tidak mengurangi saldo', function (string $answer, string $type, float $wallet) {
    expect(dcmSend('piutang noki 500rb'))->toContain('uangnya gimana');

    dcmSend($answer);
    $tx = dcmTx()->sole();
    expect($tx->type)->toBe($type)
        ->and($tx->category->type)->toBe('pengeluaran_piutang')
        ->and(dcmWallet())->toBe($wallet)
        ->and(dcmOutstanding('piutang'))->toBe(500_000.0);
})->with([
    ['1', 'expense', 500_000.0],
    ['2', 'debit_internal', 1_000_000.0],
]);

it('WA: "hanya dicatat" mengubah hutang yang terlanjur masuk dompet', function () {
    dcmSend('pinjam dari budi 2jt');
    expect(dcmWallet())->toBe(3_000_000.0);

    $reply = dcmSend('hanya dicatat');

    $tx = dcmTx()->sole();
    expect($reply)->toContain('sekarang hanya dicatat')
        ->and($tx->type)->toBe('kredit_internal')
        ->and($tx->balance_id)->toBeNull()
        ->and($tx->isDebtRecordOnly())->toBeTrue()
        ->and(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(2_000_000.0);
});

it('WA: "hanya dicatat" tanpa hutang terakhir tidak mengubah apa pun', function () {
    dcmSend('beli kopi 25rb');
    $reply = dcmSend('hanya dicatat');

    expect($reply)->toContain('bukan pencatatan hutang')
        ->and(dcmTx()->sole()->type)->toBe('expense')
        ->and(dcmWallet())->toBe(975_000.0);
});

it('WA: bayar hutang yang hanya dicatat tetap mengurangi dompet & outstanding', function () {
    dcmSend('hutang lama ke budi 2jt');
    dcmSend('bayar hutang ke budi 500rb');

    expect(dcmWallet())->toBe(500_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(1_500_000.0);
});

it('WA: menghapus hutang hanya-dicatat tidak menggeser saldo', function () {
    dcmSend('hutang lama SpayLater 2jt');
    app(\App\Services\Ledger\TransactionLedger::class)->delete(dcmTx()->sole());

    expect(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(0.0);
});

// ── Paylater / kartu kredit (tahap 2) ───────────────────────────────────────

it('menyeragamkan nama fasilitas kredit', function (string $text, ?string $expected) {
    expect(CreditFacility::canonical($text))->toBe($expected);
})->with([
    ['spaylater', 'SPayLater'],
    ['Shopee PayLater', 'SPayLater'],
    ['s paylater', 'SPayLater'],
    ['gopay later', 'GoPayLater'],
    ['kartu kredit bca', 'Kartu Kredit BCA'],
    ['cc mandiri', 'Kartu Kredit MANDIRI'],
    ['kredivo', 'Kredivo'],
    ['paylater', 'Paylater'],
    ['budi', null],
    ['beli kopi 25rb', null],
]);

it('membedakan belanja pakai fasilitas dari bayar tagihan', function (string $text, ?string $mode) {
    expect(CreditFacility::detectUse($text)['mode'] ?? null)->toBe($mode);
})->with([
    ['beli sepatu pakai spaylater 500rb', 'purchase'],
    ['bayar listrik pakai kartu kredit 300rb', 'purchase'],
    ['belanja shopee spaylater 200rb', 'purchase'],
    ['bayar spaylater 500rb', 'repayment'],
    ['bayar tagihan kartu kredit bca 1jt', 'repayment'],
    ['cicilan kredivo 250rb', 'repayment'],
    ['beli kopi 25rb', null],
]);

it('WA: belanja pakai paylater → pengeluaran + hutang naik, dompet tidak berubah', function () {
    $reply = dcmSend('beli sepatu pakai spaylater 500rb');

    $tx = dcmTx()->sole();
    expect($tx->type)->toBe('expense')
        ->and($tx->balance_id)->toBeNull()
        ->and($tx->isCreditPurchase())->toBeTrue()
        ->and($tx->metadata['counterparty'])->toBe('SPayLater')
        ->and($tx->category->type)->not->toBe('pendapatan_hutang')
        ->and(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(500_000.0)
        ->and($reply)->toContain('SPayLater')->toContain('tidak berubah')->toContain('Rp 500.000');
});

it('WA: bayar paylater setelah belanja → dompet & hutang turun, tidak dobel pengeluaran', function () {
    dcmSend('beli sepatu pakai spaylater 500rb');
    $reply = dcmSend('bayar spaylater 500rb');

    $pay = dcmTx()->sortByDesc('id')->first();
    expect($pay->category->type)->toBe('pengeluaran_bayar_hutang')
        ->and($pay->type)->toBe('debit_internal')
        ->and($pay->isCreditRepayment())->toBeTrue()
        ->and($pay->balance_id)->toBe($this->cash->id)
        ->and(dcmWallet())->toBe(500_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(0.0)
        // hanya belanja sepatu yang terhitung pengeluaran
        ->and((float) Transaction::where('tenant_id', $this->tenant->id)->where('type', 'expense')->sum('amount'))->toBe(500_000.0)
        ->and($reply)->toContain('Bayar Hutang')->toContain('Sisa hutang SPayLater: Rp 0');
});

it('WA: bayar paylater untuk hutang lama (tanpa belanja tercatat) tetap pengeluaran', function () {
    dcmSend('hutang lama SpayLater 2jt');
    dcmSend('bayar spaylater 500rb');

    $pay = dcmTx()->sortByDesc('id')->first();
    expect($pay->category->type)->toBe('pengeluaran_bayar_hutang')
        ->and($pay->type)->toBe('expense')
        ->and(dcmWallet())->toBe(500_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(1_500_000.0);
});

it('WA: hutang lama + belanja paylater teragregasi ke satu pihak', function () {
    dcmSend('hutang lama shopee paylater 2jt');
    dcmSend('beli sepatu pakai spaylater 500rb');

    $rows = app(DebtReceivableLedgerService::class)->summarize($this->tenant->id)['hutang'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['counterparty'])->toBe('SPayLater')
        ->and((float) $rows[0]['outstanding'])->toBe(2_500_000.0);
});

it('WA: "bayar spaylater" tanpa hutang tercatat tidak dipaksa jadi bayar hutang', function () {
    dcmSend('bayar spaylater 500rb');

    $tx = dcmTx()->sole();
    expect($tx->type)->toBe('expense')
        ->and($tx->category->type)->not->toBe('pengeluaran_bayar_hutang')
        ->and(dcmWallet())->toBe(500_000.0);
});

it('WA: punya dompet bernama paylater → belanja memakai dompet itu, bukan hutang', function () {
    $wallet = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'SPayLater', 'account_type' => 'wallet', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);
    dcmSend('beli sepatu pakai spaylater 500rb');

    $tx = dcmTx()->sole();
    expect($tx->isCreditPurchase())->toBeFalse()
        ->and($tx->balance_id)->toBe($wallet->id)
        ->and(dcmOutstanding('hutang'))->toBe(0.0);
});

it('WA: menghapus belanja paylater menghapus hutangnya, dompet tetap', function () {
    dcmSend('beli sepatu pakai spaylater 500rb');
    app(\App\Services\Ledger\TransactionLedger::class)->delete(dcmTx()->sole());

    expect(dcmWallet())->toBe(1_000_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(0.0);
});

it('dashboard: bayar hutang paylater hasil belanja tidak dihitung pengeluaran lagi', function () {
    dcmSend('beli sepatu pakai spaylater 500rb');

    dcmAsUser()->post('/hutang-piutang/settle', [
        'kind' => 'hutang', 'counterparty' => 'SPayLater', 'counterparty_normalized' => 'spaylater', 'amount' => 500_000,
    ])->assertRedirect();

    $pay = dcmTx()->sortByDesc('id')->first();
    expect($pay->type)->toBe('debit_internal')
        ->and($pay->isCreditRepayment())->toBeTrue()
        ->and(dcmWallet())->toBe(500_000.0)
        ->and(dcmOutstanding('hutang'))->toBe(0.0);
});

// ── Konversi & dashboard ────────────────────────────────────────────────────

it('konversi bolak-balik menjaga saldo', function () {
    dcmSend('pinjam dari budi 2jt');
    $tx = dcmTx()->sole()->load('category');

    expect(DebtCashMode::convert($tx, false))->toBeTrue()
        ->and(dcmWallet())->toBe(1_000_000.0)
        ->and(DebtCashMode::convert($tx->fresh()->load('category'), false))->toBeFalse();

    expect(DebtCashMode::convert($tx->fresh()->load('category'), true, $this->cash))->toBeTrue()
        ->and(dcmWallet())->toBe(3_000_000.0)
        ->and($tx->fresh()->type)->toBe('income')
        ->and($tx->fresh()->isDebtRecordOnly())->toBeFalse();
});

it('dashboard: catat hutang dengan/ tanpa menggerakkan saldo', function (bool $affects, string $type, float $wallet) {
    dcmAsUser()
        ->post('/hutang-piutang/store', [
            'kind' => 'hutang', 'counterparty' => 'SpayLater', 'amount' => 2_000_000, 'affects_balance' => $affects,
        ])->assertRedirect();

    $tx = dcmTx()->sole();
    expect($tx->type)->toBe($type)
        ->and($tx->isDebtRecordOnly())->toBe(! $affects)
        ->and(dcmWallet())->toBe($wallet)
        ->and(dcmOutstanding('hutang'))->toBe(2_000_000.0);
})->with([
    [true, 'income', 3_000_000.0],
    [false, 'kredit_internal', 1_000_000.0],
]);

it('dashboard: ubah hutang yang sudah tercatat jadi hanya dicatat', function () {
    dcmSend('pinjam dari budi 2jt');
    $tx = dcmTx()->sole();

    dcmAsUser()
        ->post("/hutang-piutang/{$tx->id}/cash-mode", ['affects_balance' => false])
        ->assertRedirect();

    expect($tx->fresh()->isDebtRecordOnly())->toBeTrue()
        ->and(dcmWallet())->toBe(1_000_000.0);
});
