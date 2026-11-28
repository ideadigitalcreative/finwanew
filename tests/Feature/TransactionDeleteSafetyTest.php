<?php

/**
 * Tahap A — keamanan alur transaksi:
 *  A1 hapus hanya untuk perintah eksplisit + konfirmasi Ya/Tidak
 *  A2 hapus/edit hanya transaksi milik pengirim (akun keluarga)
 *  A3 dashboard storeJson tidak menerima dompet/kategori tenant lain
 *  A4 pembaca nominal: nomor HP, "3x 1jt", jawaban angka polos
 */

use App\Helpers\BatchTransactionHelper;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;
use App\Services\Transaction\TransactionService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const DEL_OWNER = '6281800000011';
const DEL_PARTNER = '6281800000012';

function delSend(string $text, string $from = DEL_OWNER): string
{
    $GLOBALS['del_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000555', 'sender_id' => $from,
        'message_id' => 'del-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['del_replies']);
}

function delTx(string $desc, int $amount, ?int $numberId = null, string $type = 'expense'): Transaction
{
    $tenant = test()->tenant;
    $categoryId = \App\Models\Category::where('tenant_id', $tenant->id)
        ->where('type', 'like', ($type === 'income' ? 'pendapatan' : 'pengeluaran').'%')->value('id');
    $tx = Transaction::create([
        'tenant_id' => $tenant->id, 'type' => $type, 'amount' => $amount, 'description' => $desc,
        'category_id' => $categoryId, 'transaction_date' => now(), 'status' => 'confirmed',
        'balance_id' => test()->wallet->id, 'user_whatsapp_number_id' => $numberId,
    ]);
    test()->wallet->adjustBalance($type === 'income' ? $amount : -$amount);

    return $tx;
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['del_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['del_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Hapus', 'slug' => 'hapus', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000555',
        'name' => 'Hapus', 'config' => ['session_id' => 'wa_hapus'], 'is_active' => true,
    ]);
    $this->owner = User::create([
        'name' => 'Pemilik', 'email' => 'hapus@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => DEL_OWNER, 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->wallet = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
});

// ── A1: perintah batal yang ketat ────────────────────────────────────────────

it('mengenali perintah batal eksplisit saja', function (string $text, bool $expected) {
    expect(TransactionService::isUndoCommand($text))->toBe($expected);
})->with([
    ['batal', true],
    ['Undo', true],
    ['ga jadi', true],
    ['gak jadi deh', true],
    ['hapus yang tadi', true],
    ['batalin aja', true],
    ['hapus transaksi terakhir', true],
    ['yang tadi salah', true],
    ['salah catat', true],
    ['ga jadi beli baju, beli celana 150rb', false],
    ['batalkan pesanan, kena fee 5rb', false],
    ['ga jadi nonton, beli makan 50rb', false],
    ['beli kopi 25rb', false],
    ['hapus beli kopi', false],
]);

it('"ga jadi beli baju, beli celana 150rb" tidak menghapus transaksi sebelumnya', function () {
    $old = delTx('beli kopi', 25000);

    delSend('ga jadi beli baju, beli celana 150rb');

    expect(Transaction::find($old->id))->not->toBeNull()
        ->and(Cache::get('pending_delete:'.$this->tenant->id.':'.DEL_OWNER))->toBeNull();
});

it('"batalkan pesanan, kena fee 5rb" tidak menghapus transaksi', function () {
    $old = delTx('beli kopi', 25000);

    delSend('batalkan pesanan, kena fee 5rb');

    expect(Transaction::find($old->id))->not->toBeNull();
});

it('"batal" meminta konfirmasi, "ya" menghapus & mengembalikan saldo', function () {
    $tx = delTx('beli kopi', 25000);
    expect((float) $this->wallet->fresh()->balance)->toBe(975000.0);

    $ask = delSend('batal');
    expect($ask)->toContain('Hapus transaksi ini?')
        ->and($ask)->toContain('beli kopi')
        ->and(Transaction::find($tx->id))->not->toBeNull();

    $done = delSend('ya');
    expect($done)->toContain('Transaksi Dihapus')
        ->and(Transaction::find($tx->id))->toBeNull()
        ->and((float) $this->wallet->fresh()->balance)->toBe(1000000.0);
});

it('"tidak" membatalkan penghapusan', function () {
    $tx = delTx('beli kopi', 25000);

    delSend('undo');
    $reply = delSend('tidak');

    expect($reply)->toContain('tidak jadi')
        ->and(Transaction::find($tx->id))->not->toBeNull()
        ->and((float) $this->wallet->fresh()->balance)->toBe(975000.0);
});

it('pesan lain setelah konfirmasi → konfirmasi batal & pesan diproses biasa', function () {
    $tx = delTx('beli kopi', 25000);

    delSend('batal');
    delSend('beli roti 15rb');

    expect(Transaction::find($tx->id))->not->toBeNull()
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(Cache::get('pending_delete:'.$this->tenant->id.':'.DEL_OWNER))->toBeNull();

    delSend('ya'); // tidak ada konfirmasi tertunda → tidak menghapus apa pun
    expect(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('"hapus beli kopi" juga meminta konfirmasi', function () {
    $tx = delTx('beli kopi', 25000);
    delTx('beli roti', 15000);

    expect(delSend('hapus beli kopi'))->toContain('Hapus transaksi ini?')
        ->and(Transaction::find($tx->id))->not->toBeNull();

    delSend('iya');
    expect(Transaction::find($tx->id))->toBeNull()
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(1);
});

it('"hapus <keyword>" menemukan transaksi bertanggal mundur yang dicatat hari ini', function () {
    // Bug produksi 26/10/2026: "Pengeluaran 22 Oktober 2026 - laundry baju 45.000" dicatat
    // hari ini, lalu "hapus laundry baju" → "tidak ada transaksi ... hari ini".
    $tx = delTx('laundry baju', 45000);
    $tx->update(['transaction_date' => now()->subDays(4)->toDateString()]);

    expect(delSend('hapus laundry baju'))->toContain('Hapus transaksi ini?');

    delSend('ya');
    expect(Transaction::find($tx->id))->toBeNull();
});

it('"hapus <keyword>" tetap tidak menyentuh transaksi lama yang dicatat hari lain', function () {
    $tx = delTx('laundry baju', 45000);
    Transaction::whereKey($tx->id)->update(['transaction_date' => now()->subDays(4)->toDateString(), 'created_at' => now()->subDays(4)]);

    expect(delSend('hapus laundry baju'))->toContain('Transaksi tidak ditemukan')
        ->and(Transaction::find($tx->id))->not->toBeNull();
});

// ── A2: akun keluarga ────────────────────────────────────────────────────────

it('akun keluarga: "batal" dari istri tidak menyentuh transaksi suami', function () {
    $ownerId = UserWhatsAppNumber::create([
        'user_id' => $this->owner->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => DEL_OWNER,
        'name' => 'Suami', 'is_primary' => true, 'is_active' => true,
    ])->id;
    $partnerId = UserWhatsAppNumber::create([
        'user_id' => $this->owner->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => DEL_PARTNER,
        'name' => 'Istri', 'is_primary' => false, 'is_active' => true,
    ])->id;

    $wifeTx = delTx('beli sayur', 50000, $partnerId);
    $husbandTx = delTx('beli bensin', 30000, $ownerId); // paling baru

    $ask = delSend('batal', DEL_PARTNER);
    expect($ask)->toContain('beli sayur')->and($ask)->not->toContain('bensin');

    delSend('ya', DEL_PARTNER);
    expect(Transaction::find($wifeTx->id))->toBeNull()
        ->and(Transaction::find($husbandTx->id))->not->toBeNull();
});

// ── A3: dashboard storeJson ──────────────────────────────────────────────────

it('storeJson menolak dompet & kategori milik tenant lain', function () {
    $other = Tenant::create(['name' => 'Lain', 'slug' => 'lain', 'is_active' => true]);
    $otherWallet = Balance::create([
        'tenant_id' => $other->id, 'account_name' => 'Rahasia', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 5000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($other->id);
    $otherCategory = \App\Models\Category::where('tenant_id', $other->id)->where('type', 'like', 'pengeluaran%')->value('id');

    $this->owner->forceFill(['email_verified_at' => now()])->save();
    $this->owner->tenants()->attach($this->tenant->id, ['is_active' => true]);
    $response = $this->actingAs($this->owner)
        ->withSession(['current_tenant_id' => $this->tenant->id])
        ->postJson(route('transactions.store-json'), [
            'type' => 'expense', 'amount' => 10000, 'description' => 'tes',
            'balance_id' => $otherWallet->id, 'category_id' => $otherCategory,
        ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['balance_id', 'category_id']);
    expect((float) $otherWallet->fresh()->balance)->toBe(5000000.0)
        ->and(Transaction::count())->toBe(0);
});

// ── A4: pembaca nominal ──────────────────────────────────────────────────────

it('membaca nominal dengan benar', function (string $text, int $expected) {
    expect(BatchTransactionHelper::extractAmount($text))->toBe($expected);
})->with([
    ['pulsa 50000 ke 0812345678', 50000],
    ['transfer 100rb ke 081234567890', 100000],
    ['beli kursi 3x 1jt', 3000000],
    ['beli es 2 x 12.500', 25000],
    ['beli kopi 3 kali 15rb', 45000],
    ['50rb x 2', 100000],
    ['33000 dikali 3', 99000],
    ['bayar 250,000', 250000],
    ['beli bensin 30rb', 30000],
]);

it('jawaban nominal singkat (tanya balik / edit)', function (string $text, ?int $expected) {
    expect(TransactionService::parseAnswerAmount($text))->toBe($expected);
})->with([
    ['150000', 150000],
    ['150.000', 150000],
    ['1.500.000', 1500000],
    ['50', 50000],
    ['50rb', 50000],
    ['1,5jt', 1500000],
    ['2 juta', 2000000],
    ['abc', null],
]);
