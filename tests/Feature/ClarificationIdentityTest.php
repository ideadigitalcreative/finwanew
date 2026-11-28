<?php

/**
 * Bug produksi 20/10/2026 (Ivan):
 *   "Masuk dana 2jt" → "Konfirmasi Transaksi … Balas 1, 2, atau 3" → "2" → "pesan tidak dikenali".
 *
 * Akar masalah: gateway mengisi sender_id dengan nomor HP bila WhatsApp menyertakannya,
 * selain itu dengan LID mentah. Kontak yang sama bisa datang dengan sender_id berbeda →
 * pertanyaan tertunda (disimpan per sender_id mentah) tidak ditemukan.
 * Sekalian: "Masuk dana 2jt" harus masuk ke dompet DANA, alias tidak boleh "memakan"
 * pesan baru, perintah edit tidak boleh dianggap transaksi baru, dll.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsApp\Clarification;
use App\Services\WhatsAppService;
use App\Support\SenderIdentity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const IVAN_PHONE = '6281300000099';
const IVAN_LID = '94588232532003';

function ivanSend(string $text, string $sender = IVAN_PHONE, array $metadata = []): string
{
    $GLOBALS['ivan_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000111', 'sender_id' => $sender,
        'message_id' => 'ivan-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
        'metadata' => $metadata ?: null,
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['ivan_replies']);
}

beforeEach(function () {
    Cache::flush();
    SenderIdentity::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => true, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['ivan_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['ivan_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Ivan', 'slug' => 'ivan', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000111',
        'name' => 'FinWa', 'config' => ['session_id' => 'wa_ivan'], 'is_active' => true,
    ]);
    $this->user = User::create([
        'name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => IVAN_PHONE, 'tenant_id' => $this->tenant->id,
    ]);
    UserWhatsAppNumber::create([
        'user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => IVAN_PHONE,
        'name' => 'Ivan', 'is_primary' => true, 'is_active' => true,
    ]);
    // LID yang sama milik Ivan (dipetakan gateway saat senderPn pernah terlihat)
    UserLidMapping::linkLidToUser(IVAN_LID, $this->user->id, $this->tenant->id, IVAN_PHONE);

    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $mk = fn ($name, $type, $default) => Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => $name, 'account_type' => $type, 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => $default,
    ]);
    $this->main = $mk('Dompet Utama', 'cash', true);
    $this->dana = $mk('DANA', 'wallet', false);
});

it('identitas kanonik: nomor HP & LID orang yang sama → sama', function () {
    expect(SenderIdentity::canonical($this->tenant->id, IVAN_PHONE))
        ->toBe(SenderIdentity::canonical($this->tenant->id, IVAN_LID))
        ->toStartWith('wa:');
});

it('kasus Ivan: pertanyaan dikirim ke nomor HP, jawaban "2" datang lewat LID → tetap tercatat', function () {
    $ask = ivanSend('Masuk dana 2jt', IVAN_PHONE);
    expect($ask)->toContain('Konfirmasi Transaksi')->toContain('2️⃣');

    $reply = ivanSend('2', IVAN_LID, ['original_sender_id' => IVAN_LID.'@lid']);
    expect($reply)->not->toContain('tidak dikenali');

    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();
    expect($tx->type)->toBe('income')
        ->and((int) $tx->amount)->toBe(2_000_000)
        ->and($tx->balance_id)->toBe($this->dana->id)           // "Masuk dana" → dompet DANA
        ->and((float) $this->dana->fresh()->balance)->toBe(2_000_000.0)
        ->and((float) $this->main->fresh()->balance)->toBe(0.0);
});

it('"Masuk dana 2jt" TANPA dompet DANA → uang masuk ke Dompet Utama, tidak membuat dompet', function () {
    $this->dana->delete();

    $ask = ivanSend('Masuk dana 2jt');
    expect($ask)->toContain('ke *Dompet Utama*')
        ->not->toContain('e-wallet DANA');

    ivanSend('2');
    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();
    expect($tx->type)->toBe('income')
        ->and($tx->balance_id)->toBe($this->main->id)
        ->and((float) $this->main->fresh()->balance)->toBe(2_000_000.0)
        ->and(Balance::where('tenant_id', $this->tenant->id)->count())->toBe(1); // tidak ada dompet DANA baru
});

it('"Masuk dana 2jt" DENGAN dompet DANA → bisa pilih DANA atau "uang masuk biasa" ke Dompet Utama', function () {
    $ask = ivanSend('Masuk dana 2jt');
    expect($ask)->toContain('ke *DANA*')
        ->toContain('3️⃣')->toContain('ke *Dompet Utama*')->toContain('bukan e-wallet DANA')
        ->toContain('4️⃣'); // bukan transaksi

    ivanSend('3');
    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();
    expect($tx->type)->toBe('income')
        ->and($tx->balance_id)->toBe($this->main->id)
        ->and((float) $this->dana->fresh()->balance)->toBe(0.0);
});

it('"Masuk dana 2jt" dengan dompet DANA → "batal" memakai nomor terakhir (bukan transaksi)', function () {
    ivanSend('Masuk dana 2jt');
    expect(ivanSend('batal'))->toContain('tidak dicatat')
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('kebalikannya: pertanyaan lewat LID, jawaban lewat nomor HP', function () {
    ivanSend('50rb', IVAN_LID, ['original_sender_id' => IVAN_LID.'@lid']);
    ivanSend('1', IVAN_PHONE);

    expect(Transaction::where('tenant_id', $this->tenant->id)->sole()->type)->toBe('expense');
});

it('konfirmasi hapus "batal → ya" juga tahan pergantian LID ↔ nomor HP', function () {
    ivanSend('beli kopi 25rb', IVAN_PHONE);
    expect(ivanSend('batal', IVAN_PHONE))->toContain('Hapus transaksi ini?');

    ivanSend('ya', IVAN_LID, ['original_sender_id' => IVAN_LID.'@lid']);
    expect(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('transaksi tertunda "beli bensin" → nominal lewat LID tetap tergabung', function () {
    ivanSend('beli bensin', IVAN_PHONE);
    ivanSend('30rb', IVAN_LID, ['original_sender_id' => IVAN_LID.'@lid']);

    expect((int) Transaction::where('tenant_id', $this->tenant->id)->sole()->amount)->toBe(30000);
});

it('jawaban bernomor menang atas transaksi tertunda lama ("2" bukan Rp 2)', function () {
    ivanSend('beli bensin');           // bot: "Berapa biayanya?" (tertunda, lalu diabaikan user)
    ivanSend('pinjam budi 100rb');     // bot: pertanyaan bernomor (lebih baru)
    ivanSend('2');                     // = Budi pinjam dari saya, BUKAN "beli bensin 2"

    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();
    expect($tx->type)->toBe('expense')
        ->and((int) $tx->amount)->toBe(100000)
        ->and($tx->category->type)->toBe('pengeluaran_piutang');
});

it('balas dengan quote ke pertanyaan bot tetap dijawab alur utama (bukan reply-edit)', function () {
    ivanSend('beli kopi 2jt'); // transaksi lama bernominal sama dengan teks kutipan
    ivanSend('Masuk dana 2jt');

    $GLOBALS['ivan_replies'] = [];
    $message = Message::create([
        'tenant_id' => $this->tenant->id, 'channel_id' => $this->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000111', 'sender_id' => IVAN_PHONE,
        'message_id' => 'ivan-quote', 'type' => 'text', 'content' => '2', 'timestamp' => time(),
        'raw_data' => ['reply_context' => ['messageId' => 'wa-1', 'text' => "🤔 Konfirmasi Transaksi\n\nCatat Rp 2.000.000 — Masuk dana sebagai:"]],
    ]);
    (new ProcessIncomingMessage($message))->handle();

    expect(Transaction::where('tenant_id', $this->tenant->id)->where('type', 'income')->count())->toBe(1)
        ->and((int) Transaction::where('tenant_id', $this->tenant->id)->where('type', 'expense')->value('amount'))->toBe(2_000_000);
});

// ── Penyelesaian jawaban ───────────────────────────────────────────────────

it('jawaban pilihan vs pesan baru', function (string $reply, ?string $expected) {
    $pending = [
        'options' => ['1' => ['label' => 'Pengeluaran', 'action' => []], '2' => ['label' => 'Pemasukan', 'action' => []], '3' => ['label' => 'Batal', 'action' => []]],
        'aliases' => ['ya' => '2', 'masuk' => '2', 'keluar' => '1', 'pemasukan' => '2', 'batal' => '3'],
    ];
    expect(Clarification::resolve($reply, $pending)['no'] ?? null)->toBe($expected);
})->with([
    ['2', '2'],
    ['2️⃣', '2'],
    ['2 aja', '2'],
    ['nomor 1', '1'],
    ['dua', '2'],
    ['ya', '2'],
    ['ya catat', '2'],
    ['masuk', '2'],
    ['pemasukan dong', '2'],
    ['uang masuk 1jt', null],      // pesan baru, bukan jawaban
    ['beli kopi 25rb', null],
    ['2 kopi 30rb', null],
    ['masuk ke rekening bca kemarin sore', null],
]);

// ── Pengenal ambigu tidak menyentuh perintah lain ──────────────────────────

it('perintah edit/kueri tidak ditanya ulang sebagai transaksi baru', function (string $text) {
    $reply = ivanSend($text);
    expect($reply)->not->toContain('maksudnya')->not->toContain('Tarik tunai Tercatat');
})->with([
    'ubah kopi jadi 30',
    'ubah tarik tunai jadi 300rb',
    'berapa pengeluaran bensin 5?',
]);

it('"tarik tunai" saat hanya punya satu dompet → penjelasan, bukan pemasukan', function () {
    $this->dana->update(['is_active' => false]);
    $reply = ivanSend('tarik tunai 500rb');

    expect($reply)->toContain('tambah dompet')
        ->and(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

it('"gaji 5" → pilihan Rp 5.000 / Rp 5.000.000', function () {
    $ask = ivanSend('gaji 5');
    expect($ask)->toContain('Rp 5.000')->toContain('Rp 5.000.000');

    ivanSend('2');
    expect((int) Transaction::where('tenant_id', $this->tenant->id)->sole()->amount)->toBe(5_000_000);
});
