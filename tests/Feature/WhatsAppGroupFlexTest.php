<?php

/**
 * Grup WhatsApp — kelonggaran pencatatan (audit 14/11/2026):
 *  1. Pertanyaan klarifikasi juga berlaku di grup (dulu dilewati → salah catat).
 *  2. Jawaban tanpa "#" diterima bila anggota itu sedang ditanya.
 *  3. Foto struk dengan keterangan ber-"#" diproses.
 *  4. Daftar dua baris tercatat sebagai dua transaksi (bukan dijumlah).
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Models\WhatsappGroup;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use App\Services\WhatsAppWebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const GF_JID = '120363000000000077@g.us';
const GF_SUAMI = '6281700000071';
const GF_ISTRI = '6281700000072';
const GF_TAMU = '6281799999977';

function gfSend(string $body, string $author = GF_SUAMI, array $over = []): array
{
    static $n = 0;
    $n++;
    $GLOBALS['gf_sent'] = [];
    $result = app(WhatsAppWebhookService::class)->processIncomingMessage(array_merge([
        'id' => "gf-{$n}-".uniqid(),
        'from' => explode('@', GF_JID)[0],
        'originalFrom' => GF_JID,
        'body' => $body,
        'type' => 'chat',
        'timestamp' => time(),
        'isGroup' => true,
        'group' => [
            'jid' => GF_JID, 'authorPhone' => $author, 'authorLid' => null,
            'authorName' => $author === GF_SUAMI ? 'Suami' : 'Istri',
            'mentionedJids' => [], 'mentionsBot' => false,
        ],
    ], $over), test()->sessionId);

    foreach (Message::where('status', 'received')->get() as $m) {
        (new ProcessIncomingMessage($m))->handle();
        $m->forceFill(['status' => 'processed'])->save();
    }

    return $result;
}

function gfReplies(): string
{
    return implode("\n---\n", array_column($GLOBALS['gf_sent'], 'text'));
}

function gfTxs()
{
    return Transaction::with(['balance', 'category'])->where('tenant_id', test()->tenant->id)->orderBy('id')->get();
}

function gfBalance(string $name): int
{
    return (int) Balance::where('tenant_id', test()->tenant->id)->where('account_name', $name)->value('balance');
}

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $GLOBALS['gf_sent'] = [];
    $GLOBALS['gf_gemini'] = null;
    $GLOBALS['gf_files'] = [];
    config([
        'services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false,
        'services.gemini.api_key' => 'gemini-test-key', 'services.gemini.model' => 'gemini-2.5-flash',
    ]);
    Http::fake(fn ($request) => str_contains($request->url(), 'generativelanguage') && $GLOBALS['gf_gemini']
        ? Http::response($GLOBALS['gf_gemini'])
        : Http::response(['error' => 'offline'], 500));

    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($session, $to, $text) {
        $GLOBALS['gf_sent'][] = ['to' => $to, 'text' => $text];

        return ['success' => true, 'data' => ['messageId' => 'out-'.uniqid()]];
    });

    $bot = Tenant::create(['name' => 'Bot', 'slug' => 'gf-bot', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $bot->id, 'type' => 'whatsapp', 'channel_account' => '628999000677',
        'name' => 'FinWa', 'config' => ['session_id' => "wa_{$bot->id}_628999000677"], 'is_active' => true,
    ]);
    $this->sessionId = "wa_{$bot->id}_628999000677";

    $this->tenant = Tenant::create(['name' => 'Keluarga', 'slug' => 'gf-keluarga', 'is_active' => true]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    foreach ([['Cash', 'cash', true], ['Bank Mandiri', 'bank', false], ['Gopay', 'wallet', false]] as [$name, $type, $default]) {
        Balance::create([
            'tenant_id' => $this->tenant->id, 'account_name' => $name, 'account_type' => $type, 'currency' => 'IDR',
            'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => $default,
        ]);
    }
    $owner = User::create([
        'name' => 'Suami', 'email' => 'gf@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => GF_SUAMI, 'tenant_id' => $this->tenant->id,
    ]);
    foreach ([GF_SUAMI => 'Suami', GF_ISTRI => 'Istri'] as $phone => $label) {
        UserWhatsAppNumber::create([
            'user_id' => $owner->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => (string) $phone,
            'name' => $label, 'is_primary' => (string) $phone === GF_SUAMI, 'is_active' => true,
        ]);
    }
    WhatsappGroup::create(['group_jid' => GF_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
});

afterEach(function () {
    foreach ($GLOBALS['gf_files'] ?? [] as $f) {
        @unlink($f);
    }
});

// ── 1. Klarifikasi di grup ──

it('"#tarik tunai" di grup ditanya dompetnya, lalu jadi pindah saldo (bukan pengeluaran)', function () {
    gfSend('#tarik tunai 500rb');

    expect(gfTxs())->toHaveCount(0)
        ->and(gfReplies())->toContain('Uangnya dari dompet mana?')
        ->and(gfReplies())->not->toContain('saldo Rp');

    gfSend('1'); // tanpa "#": Dari Bank Mandiri
    expect(gfTxs()->pluck('type')->sort()->values()->all())->toBe(['debit_internal', 'kredit_internal'])
        ->and(gfBalance('Bank Mandiri'))->toBe(500000)
        ->and(gfBalance('Cash'))->toBe(1500000);
});

it('"#pinjam budi 100rb" di grup ditanya arahnya', function () {
    gfSend('#pinjam budi 100rb');
    expect(gfTxs())->toHaveCount(0)
        ->and(gfReplies())->toContain('siapa yang meminjam');

    gfSend('#2');
    expect(gfTxs())->toHaveCount(1)
        ->and(gfTxs()->first()->type)->toBe('expense')
        ->and(gfTxs()->first()->category->type)->toBe('pengeluaran_piutang');
});

it('"#budi transfer 100rb" di grup ditanya masuk/keluar', function () {
    gfSend('#budi transfer 100rb');
    expect(gfTxs())->toHaveCount(0);

    gfSend('masuk');
    expect(gfTxs()->first()->type)->toBe('income');
});

it('"#bensin 50" di grup ditanya nominalnya dan jawaban nominal tanpa "#" diterima', function () {
    gfSend('#bensin 50');
    expect(gfReplies())->toContain('Rp 50.000');

    gfSend('1');
    expect(gfTxs())->toHaveCount(1)
        ->and((int) gfTxs()->first()->amount)->toBe(50000);
});

// ── 2. Jawaban tanpa "#" ──

it('"Berapa biayanya?" dijawab nominal tanpa "#"', function () {
    gfSend('#beli bensin');
    expect(gfReplies())->toContain('Berapa biayanya');

    gfSend('50rb');
    expect(gfTxs())->toHaveCount(1)
        ->and((int) gfTxs()->first()->amount)->toBe(50000);
});

it('konfirmasi hapus dijawab "ya" tanpa "#"', function () {
    gfSend('#makan siang 15rb');
    gfSend('#batal');
    expect(gfReplies())->toContain('Hapus transaksi ini?');

    gfSend('ya');
    expect(gfTxs())->toHaveCount(0);
});

it('jawaban tanpa "#" dari anggota LAIN tidak menjawab pertanyaan itu', function () {
    gfSend('#beli bensin', GF_SUAMI);

    $result = gfSend('50rb', GF_ISTRI);
    expect(gfTxs())->toHaveCount(0)
        ->and($GLOBALS['gf_sent'])->toBe([]);

    gfSend('50rb', GF_SUAMI);
    expect(gfTxs())->toHaveCount(1);
});

it('obrolan biasa tanpa "#" tetap diabaikan tanpa balasan', function (string $chat) {
    gfSend('#makan siang 15rb'); // bot baru saja menulis di grup
    $before = gfTxs()->count();

    gfSend($chat);
    expect($GLOBALS['gf_sent'])->toBe([])
        ->and(gfTxs()->count())->toBe($before);
})->with(['ya', 'oke', '50rb', 'makan 20rb', 'nanti malam makan di mana?', '1']);

it('tanpa aktivitas bot, pesan tanpa "#" tidak diteruskan sama sekali', function () {
    $result = gfSend('50rb');

    expect($result['reason'] ?? null)->toBe('group_no_trigger')
        ->and(Message::count())->toBe(0);
});

it('nomor tak terdaftar yang menjawab tanpa "#" diabaikan diam-diam', function () {
    gfSend('#beli bensin');

    gfSend('50rb', GF_TAMU);
    expect($GLOBALS['gf_sent'])->toBe([])
        ->and(gfTxs())->toHaveCount(0);
});

// ── 3. Foto struk di grup ──

function gfPhoto(?string $caption, array $gemini): array
{
    $GLOBALS['gf_gemini'] = ['candidates' => [['content' => ['parts' => [['text' => json_encode($gemini)]]], 'finishReason' => 'STOP']]];
    $rel = 'whatsapp/'.test()->tenant->id.'/_test/gf-'.uniqid().'.jpg';
    $abs = storage_path('app/public/'.$rel);
    @mkdir(dirname($abs), 0777, true);
    $img = imagecreatetruecolor(20, 20);
    imagejpeg($img, $abs);
    imagedestroy($img);
    $GLOBALS['gf_files'][] = $abs;

    return gfSend('/storage/'.$rel, GF_SUAMI, ['type' => 'image', 'caption' => $caption]);
}

it('foto struk dengan keterangan "#" dicatat, dompet & kategori dari keterangan', function () {
    gfPhoto('#beli minum lewat rek mandiri', [
        'document_type' => 'receipt', 'merchant_name' => 'Warung', 'date' => now()->toDateString(),
        'items' => [], 'tax' => 0, 'total_amount' => 7200,
    ]);

    $tx = gfTxs()->first();
    expect($tx)->not->toBeNull()
        ->and((int) $tx->amount)->toBe(7200)
        ->and($tx->balance->account_name)->toBe('Bank Mandiri')
        ->and($tx->description)->toContain('beli minum')
        ->and(gfReplies())->toContain('Struk Tercatat')
        ->and(gfReplies())->not->toContain('Sisa saldo');
});

it('foto dengan keterangan "#" saja tetap dicatat', function () {
    gfPhoto('#', [
        'document_type' => 'receipt', 'merchant_name' => 'Indomaret', 'date' => now()->toDateString(),
        'items' => [], 'tax' => 0, 'total_amount' => 15000,
    ]);

    expect(gfTxs())->toHaveCount(1)
        ->and(gfTxs()->first()->description)->toBe('Belanja di Indomaret');
});

it('foto tanpa keterangan "#" diabaikan tanpa balasan', function (?string $caption) {
    $result = gfPhoto($caption, ['document_type' => 'receipt', 'merchant_name' => 'X', 'items' => [], 'total_amount' => 15000]);

    expect($result['reason'] ?? null)->toBe('group_non_text')
        ->and(gfTxs())->toHaveCount(0)
        ->and($GLOBALS['gf_sent'])->toBe([]);
})->with([null, 'foto liburan kemarin']);

// ── 4. Daftar dua baris ──

it('daftar dua baris tercatat sebagai dua transaksi', function (string $body) {
    gfSend($body);

    $txs = gfTxs();
    expect($txs)->toHaveCount(2)
        ->and($txs->pluck('amount')->map(fn ($a) => (int) $a)->all())->toBe([15000, 50000]);
})->with([
    "#makan 15rb\n#bensin 50rb",
    "#makan 15rb\nbensin 50rb",
]);

it('pesan dua baris yang bukan daftar tidak dipecah', function () {
    gfSend("#makan siang 15rb\ndi warung bu siti");

    expect(gfTxs())->toHaveCount(1)
        ->and((int) gfTxs()->first()->amount)->toBe(15000);
});

// ── 5. Perintah tidak dialihkan ke chat pribadi ──

function gfToPrivate(): array
{
    return array_values(array_filter($GLOBALS['gf_sent'], fn ($s) => ! str_ends_with((string) $s['to'], '@g.us')));
}

it('perintah hapus/ubah dompet dijawab di grup, tanpa saldo', function () {
    gfSend('#makan siang 15rb');

    gfSend('#ubah dompet jadi mandiri');
    expect(gfToPrivate())->toBe([])
        ->and(gfReplies())->toContain('Dompet Transaksi Diubah')
        ->and(gfReplies())->not->toContain('Saldo')
        ->and(gfTxs()->first()->balance->account_name)->toBe('Bank Mandiri');

    gfSend('#hapus transaksi makan siang');
    expect(gfToPrivate())->toBe([])
        ->and(gfReplies())->toContain('Hapus transaksi ini?');
});

it('"#bayar hutang budi" tidak dibalas daftar hutang lewat chat pribadi', function () {
    gfSend('#bayar hutang budi');

    expect(gfToPrivate())->toBe([])
        ->and(gfReplies())->not->toContain('chat pribadi');
});

it('permintaan info tetap dialihkan ke chat pribadi', function () {
    gfSend('#cek saldo');

    expect(gfToPrivate())->not->toBe([])
        ->and(collect($GLOBALS['gf_sent'])->firstWhere('to', GF_JID)['text'])->toContain('chat pribadi');
});

// ── 6. Transaksi anggota lain ──

it('anggota biasa diberi tahu bahwa transaksi itu milik anggota lain', function () {
    gfSend('#makan siang 15rb', GF_SUAMI);

    gfSend('#ubah makan siang jadi 20rb', GF_ISTRI);
    expect(gfReplies())->toContain('Itu transaksi Suami')
        ->and((int) gfTxs()->first()->amount)->toBe(15000);

    gfSend('#hapus makan siang', GF_ISTRI);
    expect(gfReplies())->toContain('Itu transaksi Suami')
        ->and(gfTxs())->toHaveCount(1);
});

it('pemilik akun boleh mengoreksi transaksi anggota lain', function () {
    gfSend('#beli sayur 20rb', GF_ISTRI);

    gfSend('#ubah beli sayur jadi 25rb', GF_SUAMI);
    expect((int) gfTxs()->first()->amount)->toBe(25000)
        ->and(gfBalance('Cash'))->toBe(975000);
});

// ── 7. Dompet bawaan per anggota ──

it('transaksi tanpa dompet memakai dompet bawaan anggota', function () {
    $gopay = Balance::where('tenant_id', $this->tenant->id)->where('account_name', 'Gopay')->first();
    UserWhatsAppNumber::where('whatsapp_number', GF_ISTRI)->update(['default_balance_id' => $gopay->id]);

    gfSend('#beli sayur 20rb', GF_ISTRI);
    gfSend('#makan siang 15rb', GF_SUAMI);
    gfSend('#beli buah 10rb lewat rek mandiri', GF_ISTRI);

    expect(gfTxs()->pluck('balance.account_name')->all())->toBe(['Gopay', 'Cash', 'Bank Mandiri']);
});

it('dompet bawaan yang sudah nonaktif diabaikan', function () {
    $gopay = Balance::where('tenant_id', $this->tenant->id)->where('account_name', 'Gopay')->first();
    UserWhatsAppNumber::where('whatsapp_number', GF_ISTRI)->update(['default_balance_id' => $gopay->id]);
    $gopay->update(['is_active' => false]);

    gfSend('#beli sayur 20rb', GF_ISTRI);
    expect(gfTxs()->first()->balance->account_name)->toBe('Cash');
});

// ── 8. Balasan grup tidak ramai ──

it('balasan grup tanpa achievement, total bulanan, dan sapaan panjang', function () {
    gfSend('#makan siang 15rb'); // transaksi pertama → dulu memunculkan achievement
    expect(gfReplies())->toContain('Rp 15.000')
        ->and(gfReplies())->not->toContain('Achievement')
        ->and(gfReplies())->not->toContain('poin')
        ->and(gfReplies())->not->toContain('Total pengeluaran');

    gfSend('#gaji 5jt');
    expect(gfReplies())->toContain('Pemasukan')
        ->and(gfReplies())->not->toContain('Mantap')
        ->and(gfReplies())->not->toContain('Keren')
        ->and(gfReplies())->not->toContain('berkah');

    gfSend('#halo');
    expect(mb_strlen(gfReplies()))->toBeLessThan(160)
        ->and(gfReplies())->not->toContain('asisten keuangan pribadi');
});
