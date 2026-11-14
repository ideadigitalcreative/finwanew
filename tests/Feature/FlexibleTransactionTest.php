<?php

/**
 * Kelonggaran cara mencatat & mengoreksi transaksi (hasil audit 14/11/2026):
 *  - nominal: "lima belas ribu", "15ribuan", "1,2 miliar"
 *  - tanggal: nama hari, bilangan kata, tanggal angka di awal/akhir, awal/akhir bulan
 *  - "budi transfer 100rb" ditanyakan arahnya (dulu selalu pengeluaran)
 *  - nama bank sebagai potongan kata tidak lagi dianggap dompet
 *  - pindah dompet transaksi lewat chat; ubah nominal tidak terbatas hari ini
 *  - dokumen & video dibalas dengan jelas
 *  - struk belanja campuran bisa dipecah per kategori
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
use App\Services\Category\CategoryManagerService;
use App\Services\Transaction\TransactionExtractorService;
use App\Services\Transaction\TransactionService;
use App\Services\WhatsAppService;
use App\Support\AmountParser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const FLEX_SENDER = '6281400000088';

function flexSend(string $text, string $type = 'text', array $metadata = []): string
{
    $GLOBALS['flex_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000888', 'sender_id' => FLEX_SENDER,
        'message_id' => 'flex-'.uniqid('', true), 'type' => $type, 'content' => $text,
        'metadata' => $metadata ?: null, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['flex_replies']);
}

function flexReceipt(array $gemini): string
{
    $GLOBALS['flex_gemini'] = ['candidates' => [['content' => ['parts' => [['text' => json_encode($gemini)]]], 'finishReason' => 'STOP']]];
    $rel = 'whatsapp/'.test()->tenant->id.'/_test/flex-'.uniqid().'.jpg';
    $abs = storage_path('app/public/'.$rel);
    @mkdir(dirname($abs), 0777, true);
    $img = imagecreatetruecolor(20, 20);
    imagejpeg($img, $abs);
    imagedestroy($img);
    $GLOBALS['flex_files'][] = $abs;

    return flexSend('/storage/'.$rel, 'image', ['mimetype' => 'image/jpeg', 'media_url' => '/storage/'.$rel]);
}

function flexTxs()
{
    return Transaction::with(['balance', 'category'])->where('tenant_id', test()->tenant->id)->orderBy('id')->get();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 14, 10, 0, 0)); // Sabtu
    Cache::flush();
    $GLOBALS['flex_files'] = [];
    $GLOBALS['flex_gemini'] = null;
    config([
        'services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false,
        'services.gemini.api_key' => 'gemini-test-key', 'services.gemini.model' => 'gemini-2.5-flash',
    ]);
    Http::fake(fn ($request) => str_contains($request->url(), 'generativelanguage') && $GLOBALS['flex_gemini']
        ? Http::response($GLOBALS['flex_gemini'])
        : Http::response(['error' => 'offline'], 500));
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['flex_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['flex_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Flex', 'slug' => 'flex', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000888',
        'name' => 'Flex', 'config' => ['session_id' => 'wa_flex'], 'is_active' => true,
    ]);
    $user = User::create([
        'name' => 'Arif', 'email' => 'flex@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => FLEX_SENDER, 'tenant_id' => $this->tenant->id,
    ]);
    UserWhatsAppNumber::create([
        'user_id' => $user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => FLEX_SENDER,
        'is_primary' => true, 'is_active' => true,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    foreach ([['Dompet Utama', 'cash', true], ['Bank Mandiri', 'bank', false], ['Gopay', 'wallet', false]] as [$name, $type, $default]) {
        Balance::create([
            'tenant_id' => $this->tenant->id, 'account_name' => $name, 'account_type' => $type, 'currency' => 'IDR',
            'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => $default,
        ]);
    }
});

afterEach(function () {
    foreach ($GLOBALS['flex_files'] ?? [] as $f) {
        @unlink($f);
    }
    Carbon::setTestNow();
});

// ── Nominal ──

it('membaca nominal dalam kata, kisaran, dan miliar', function (string $text, int $amount) {
    expect(AmountParser::extract($text))->toBe($amount);
})->with([
    ['makan lima belas ribu', 15000],
    ['makan dua belas ribu lima ratus', 12500],
    ['makan 15ribuan', 15000],
    ['servis 2 jutaan', 2000000],
    ['beli rumah 1,2 miliar', 1200000000],
    ['beli tanah 2 milyar', 2000000000],
    ['makan 15000 05-11-2026', 15000],
    ['makan 15000 2026-11-10', 15000],
]);

// ── Tanggal (hari ini Sabtu 14 Nov 2026) ──

it('membaca tanggal dari berbagai cara menyebut', function (string $text, ?string $date) {
    expect((new TransactionExtractorService)->extractDateFromText($text))->toBe($date);
})->with([
    ['makan 15rb senin', '2026-11-09'],
    ['makan 15rb hari minggu', '2026-11-08'],
    ['makan 15rb jumat kemarin', '2026-11-13'],
    ['makan 15rb rabu lalu', '2026-11-11'],
    ['makan 15rb sabtu lalu', '2026-11-07'],
    ['makan 15rb sabtu', '2026-11-14'],
    ["sedekah jum'at 50rb", '2026-11-13'],
    ['makan 15rb dua hari lalu', '2026-11-12'],
    ['makan 15rb 3 hr lalu', '2026-11-11'],
    ['makan 15rb seminggu lalu', '2026-11-07'],
    ['makan 15rb sebulan lalu', '2026-10-14'],
    ['makan 15rb 5/11', '2026-11-05'],
    ['12/11 makan 15rb', '2026-11-12'],
    ['makan 15rb 05-11-2026', '2026-11-05'],
    ['makan 15rb 2026-11-10', '2026-11-10'],
    ['makan 15rb 28/12', '2025-12-28'],
    ['makan 15rb awal bulan', '2026-11-01'],
    ['makan 15rb akhir bulan lalu', '2026-10-31'],
    ['makan 15rb tgl 31', '2026-10-31'],
    // bukan tanggal transaksi
    ['belanja di pasar minggu 50rb', null],
    ['les tiap senin 100rb', null],
    ['bayar kos senin depan 500rb', null],
    ['tiket KNO-PEN 22/09/2026 1.5jt', null],
    ['makan 15rb 20/12/2026', null],
    ['beli beras 3-5 kg 60rb', null],
    ['makan 15rb minggu lalu', '2026-11-07'],
    ['makan 15rb kemarin', '2026-11-13'],
]);

it('tanggal dari pesan terpakai saat transaksi dicatat', function () {
    flexSend('makan siang 15rb senin lalu');

    $tx = flexTxs()->first();
    expect($tx->transaction_date->toDateString())->toBe('2026-11-09')
        ->and((int) $tx->amount)->toBe(15000);
});

// ── Arah transfer ──

it('"budi transfer 100rb" ditanyakan arahnya, lalu dicatat sesuai jawaban', function () {
    $reply = flexSend('budi transfer 100rb');
    expect(flexTxs())->toHaveCount(0)
        ->and($reply)->toContain('Budi transfer ke saya');

    flexSend('1');
    $tx = flexTxs()->first();
    expect($tx->type)->toBe('income')
        ->and((int) $tx->amount)->toBe(100000);
});

it('"transfer ke budi 100rb" tetap langsung pengeluaran', function () {
    flexSend('transfer ke budi 100rb');

    expect(flexTxs())->toHaveCount(1)
        ->and(flexTxs()->first()->type)->toBe('expense');
});

// ── Dompet: nama bank sebagai potongan kata ──

it('nama bank di tengah kalimat tidak dianggap dompet', function (string $text) {
    flexSend($text);

    expect(flexTxs())->toHaveCount(1)
        ->and(flexTxs()->first()->balance->account_name)->toBe('Dompet Utama');
})->with([
    'beli buku mandiri belajar 50rb',
    'beli lampu neon 20rb',
    'bayar pabrik 500rb',
]);

it('nama bank menempel nominal tetap dipakai sebagai dompet', function (string $text, string $wallet) {
    flexSend($text);

    expect(flexTxs()->first()->balance->account_name)->toBe($wallet);
})->with([
    ['beli pulsa 50rb gopay', 'Gopay'],
    ['bayar listrik mandiri 200rb', 'Bank Mandiri'],
]);

// ── Pindah dompet transaksi ──

it('mengenali perintah pindah dompet', function (string $text, ?array $expected) {
    expect(TransactionService::parseWalletChange($text))->toBe($expected);
})->with([
    ['ubah dompet jadi mandiri', ['keyword' => null, 'wallet' => 'mandiri', 'explicit' => true]],
    ['ganti dompetnya ke gopay', ['keyword' => null, 'wallet' => 'gopay', 'explicit' => true]],
    ['ganti dompet kopi ke gopay', ['keyword' => 'kopi', 'wallet' => 'gopay', 'explicit' => true]],
    ['pindahkan ke dompet bank mandiri', ['keyword' => null, 'wallet' => 'bank mandiri', 'explicit' => true]],
    ['salah dompet, harusnya pakai gopay', ['keyword' => null, 'wallet' => 'gopay', 'explicit' => true]],
    ['harusnya pakai gopay', ['keyword' => null, 'wallet' => 'gopay', 'explicit' => false]],
    ['pindah 500rb ke dompet gopay', null],
    ['ubah kopi jadi 25rb', null],
    ['makan siang 15rb', null],
]);

it('pindah dompet transaksi terakhir mengoreksi saldo kedua dompet', function () {
    flexSend('makan siang 15rb');
    $reply = flexSend('ubah dompet jadi mandiri');

    $balances = Balance::where('tenant_id', $this->tenant->id)->pluck('balance', 'account_name');
    expect(flexTxs())->toHaveCount(1)
        ->and(flexTxs()->first()->balance->account_name)->toBe('Bank Mandiri')
        ->and((int) $balances['Dompet Utama'])->toBe(1000000)
        ->and((int) $balances['Bank Mandiri'])->toBe(985000)
        ->and($reply)->toContain('Dompet Utama → *Bank Mandiri*');
});

it('pindah dompet berdasarkan kata kunci transaksi', function () {
    flexSend('beli kopi 20rb');
    flexSend('makan siang 15rb');
    flexSend('ganti dompet kopi ke gopay');

    $txs = flexTxs();
    expect($txs[0]->balance->account_name)->toBe('Gopay')
        ->and($txs[1]->balance->account_name)->toBe('Dompet Utama');
});

it('"salah dompet, harusnya pakai gopay" memindahkan transaksi terakhir', function () {
    flexSend('makan siang 15rb');
    flexSend('salah dompet, harusnya pakai gopay');

    expect(flexTxs()->first()->balance->account_name)->toBe('Gopay');
});

it('pindah ke dompet yang belum terdaftar ditolak dengan daftar dompet', function () {
    flexSend('makan siang 15rb');
    $reply = flexSend('ubah dompet jadi ovo');

    expect(flexTxs()->first()->balance->account_name)->toBe('Dompet Utama')
        ->and($reply)->toContain('belum terdaftar')
        ->and($reply)->toContain('Bank Mandiri');
});

// ── Ubah nominal tidak terbatas hari ini ──

it('ubah nominal menemukan transaksi kemarin dan memakai pembaca nominal bersama', function () {
    flexSend('beli kopi 20rb');
    Transaction::where('tenant_id', $this->tenant->id)->update([
        'created_at' => now()->subDays(2), 'transaction_date' => now()->subDays(2)->toDateString(),
    ]);

    $reply = flexSend('ubah kopi jadi 25 rebu');

    expect((int) flexTxs()->first()->amount)->toBe(25000)
        ->and($reply)->toContain('Rp 20.000 → *Rp 25.000*')
        ->and((int) Balance::where('tenant_id', $this->tenant->id)->where('account_name', 'Dompet Utama')->value('balance'))->toBe(975000);
});

// ── Dokumen & video ──

it('dokumen dibalas dengan petunjuk, bukan didiamkan', function () {
    $reply = flexSend('/storage/whatsapp/x/mutasi.pdf', 'doc');

    expect($reply)->toContain('Dokumen belum bisa dibaca')
        ->and(flexTxs())->toHaveCount(0);
});

it('video tanpa keterangan dibalas jelas; dengan keterangan diproses sebagai teks', function () {
    $reply = flexSend('', 'image', ['wa_type' => 'video']);
    expect($reply)->toContain('Video belum bisa dibaca')
        ->and(flexTxs())->toHaveCount(0);

    flexSend('beli bensin 50rb', 'image', ['wa_type' => 'video', 'caption' => 'beli bensin 50rb']);
    expect(flexTxs())->toHaveCount(1)
        ->and((int) flexTxs()->first()->amount)->toBe(50000);
});

// ── Pecah struk ──

it('struk belanja campuran ditawarkan untuk dipecah, total dan dompet tetap', function () {
    $reply = flexReceipt([
        'document_type' => 'receipt', 'merchant_name' => 'Toko Serba Ada', 'date' => now()->toDateString(),
        'items' => [
            ['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 25000],
            ['name' => 'Es Teh', 'qty' => 2, 'price' => 5000],
            ['name' => 'Bensin Pertalite', 'qty' => 1, 'price' => 50000],
        ],
        'tax' => 0, 'total_amount' => 86000,
    ]);

    expect(flexTxs())->toHaveCount(1)
        ->and($reply)->toContain('Mau dipecah per kategori?');

    $reply = flexSend('1');
    $txs = flexTxs();
    expect($txs)->toHaveCount(2)
        ->and((int) $txs->sum('amount'))->toBe(86000)
        ->and($txs->pluck('category.type')->unique()->count())->toBe(2)
        ->and($txs->pluck('balance.account_name')->unique()->all())->toBe(['Dompet Utama'])
        ->and((int) Balance::where('tenant_id', $this->tenant->id)->where('account_name', 'Dompet Utama')->value('balance'))->toBe(914000)
        ->and($reply)->toContain('Struk dipecah jadi 2 transaksi');
});

it('menolak pecah struk → tetap satu transaksi', function () {
    flexReceipt([
        'document_type' => 'receipt', 'merchant_name' => 'Toko Serba Ada', 'date' => now()->toDateString(),
        'items' => [['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 25000], ['name' => 'Bensin Pertalite', 'qty' => 1, 'price' => 50000]],
        'tax' => 0, 'total_amount' => 75000,
    ]);
    $reply = flexSend('2');

    expect(flexTxs())->toHaveCount(1)
        ->and($reply)->toContain('tetap satu transaksi');
});

it('struk satu jenis belanja tidak ditawarkan pecah', function () {
    $reply = flexReceipt([
        'document_type' => 'receipt', 'merchant_name' => 'Warung Bu Siti', 'date' => now()->toDateString(),
        'items' => [['name' => 'Nasi Goreng', 'qty' => 1, 'price' => 25000], ['name' => 'Es Teh', 'qty' => 1, 'price' => 5000]],
        'tax' => 0, 'total_amount' => 30000,
    ]);

    expect($reply)->not->toContain('dipecah');
});

// ── Tingkat webhook (payload gateway) ──

it('webhook: video berketerangan diproses sebagai teks, tanpa media tempelan', function () {
    app(\App\Services\WhatsAppWebhookService::class)->processIncomingMessage([
        'id' => 'flex-video-'.uniqid(), 'from' => FLEX_SENDER, 'originalFrom' => FLEX_SENDER.'@s.whatsapp.net',
        'body' => 'beli bensin 50rb', 'type' => 'video', 'timestamp' => time(), 'isGroup' => false,
    ], 'wa_'.$this->tenant->id.'_628999000888');

    $message = Message::where('tenant_id', $this->tenant->id)->latest('id')->first();
    // Webhook mengantre job ke koneksi 'database'; jalankan langsung di tes
    $GLOBALS['flex_replies'] = [];
    (new ProcessIncomingMessage($message))->handle();

    expect($message->metadata['wa_type'] ?? null)->toBe('video')
        ->and($message->metadata['media_url'] ?? null)->toBeNull()
        ->and(flexTxs())->toHaveCount(1)
        ->and((int) flexTxs()->first()->amount)->toBe(50000);
});

it('webhook: keterangan foto tersimpan di metadata pesan', function () {
    $rel = 'whatsapp/'.$this->tenant->id.'/_test/flex-cap-'.uniqid().'.jpg';
    $abs = storage_path('app/public/'.$rel);
    @mkdir(dirname($abs), 0777, true);
    $img = imagecreatetruecolor(20, 20);
    imagejpeg($img, $abs);
    imagedestroy($img);
    $GLOBALS['flex_files'][] = $abs;

    app(\App\Services\WhatsAppWebhookService::class)->processIncomingMessage([
        'id' => 'flex-cap-'.uniqid(), 'from' => FLEX_SENDER, 'originalFrom' => FLEX_SENDER.'@s.whatsapp.net',
        'body' => '/storage/'.$rel, 'caption' => 'Beli minum lewat rek mandiri', 'type' => 'image',
        'timestamp' => time(), 'isGroup' => false,
    ], 'wa_'.$this->tenant->id.'_628999000888');

    $message = Message::where('tenant_id', $this->tenant->id)->where('type', 'image')->latest('id')->first();
    expect($message)->not->toBeNull()
        ->and($message->metadata['caption'] ?? null)->toBe('Beli minum lewat rek mandiri');
});
