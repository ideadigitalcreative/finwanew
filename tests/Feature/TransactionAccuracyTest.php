<?php

/**
 * Tahap B — ketepatan pencatatan:
 *  - satu pembaca nominal (AmountParser): angka-kata, kembalian/harusnya/potong, "3jt 400rb"
 *  - batch satu baris hanya bila tiap potongan punya keterangan
 *  - pola dompet hanya untuk dompet sungguhan (tidak membuat dompet "Ibu"/"Kantor")
 *  - kata penentu jenis: gajian, dari [orang], pembeli, pesanan masuk, tiket masuk, terima kasih
 *  - tanggal: "5 oktober", "tadi malam", "kemarin lusa", "N minggu/bulan lalu"
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
use App\Services\Transaction\TransactionExtractorService;
use App\Services\Transaction\TransactionTypeDetector;
use App\Services\WhatsAppService;
use App\Support\AmountParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function accSend(string $text): string
{
    $GLOBALS['acc_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000444', 'sender_id' => '6281800000044',
        'message_id' => 'acc-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['acc_replies']);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 20, 10, 0));
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['acc_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['acc_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Akurat', 'slug' => 'akurat', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000444',
        'name' => 'Akurat', 'config' => ['session_id' => 'wa_akurat'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Akurat', 'email' => 'akurat@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000044', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->main = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
    $this->bca = Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'BCA', 'account_type' => 'bank', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

// ── Pencatatan end-to-end ────────────────────────────────────────────────────

it('mencatat dengan jenis, nominal, jumlah & tanggal yang benar', function (string $text, string $type, int $amount, int $count, string $date) {
    accSend($text);

    $txs = Transaction::where('tenant_id', $this->tenant->id)->get();
    expect($txs)->toHaveCount($count)
        ->and((int) $txs->sum('amount'))->toBe($amount)
        ->and($txs->pluck('type')->unique()->all())->toBe([$type])
        ->and($txs->first()->transaction_date->toDateString())->toBe($date)
        ->and(Balance::where('tenant_id', $this->tenant->id)->count())->toBe(2); // tidak ada dompet baru
})->with([
    // nominal gabungan — satu transaksi
    ['bayar 100rb kembalian 20rb', 'expense', 80000, 1, '2026-10-20'],
    ['gaji 3jt 400rb', 'income', 3400000, 1, '2026-10-20'],
    ['gaji 5jt potong bpjs 150rb', 'income', 4850000, 1, '2026-10-20'],
    ['beli sepatu 300rb harusnya 400rb', 'expense', 400000, 1, '2026-10-20'],
    // batch satu baris yang sah tetap dipecah
    ['beli bakso 20rb dan es teh 5rb', 'expense', 25000, 2, '2026-10-20'],
    ['makan 25rb, parkir 5rb', 'expense', 30000, 2, '2026-10-20'],
    // jenis
    ['dapat uang dari ibu 500rb buat jajan', 'income', 500000, 1, '2026-10-20'],
    ['terima 2jt di kantor', 'income', 2000000, 1, '2026-10-20'],
    ['tiket masuk 50rb', 'expense', 50000, 1, '2026-10-20'],
    ['fee admin 6500', 'expense', 6500, 1, '2026-10-20'],
    ['transfer dari istri 500rb', 'income', 500000, 1, '2026-10-20'],
    ['uang dari pembeli 200rb', 'income', 200000, 1, '2026-10-20'],
    ['gajian 5jt', 'income', 5000000, 1, '2026-10-20'],
    ['pesanan masuk 300rb', 'income', 300000, 1, '2026-10-20'],
    ['terima kasih sudah bayar 100rb', 'expense', 100000, 1, '2026-10-20'],
    ['bayar tagihan bulanan wifi 350rb', 'expense', 350000, 1, '2026-10-20'],
    // angka dalam kata
    ['beli beras seratus ribu', 'expense', 100000, 1, '2026-10-20'],
    ['beli hp satu setengah juta', 'expense', 1500000, 1, '2026-10-20'],
    ['makan dua puluh lima ribu', 'expense', 25000, 1, '2026-10-20'],
    // tanggal
    ['makan tadi malam 50rb', 'expense', 50000, 1, '2026-10-19'],
    ['beli pulsa 5 oktober 50rb', 'expense', 50000, 1, '2026-10-05'],
    ['kemarin lusa beli bensin 30rb', 'expense', 30000, 1, '2026-10-18'],
    ['2 minggu lalu beli sepatu 200rb', 'expense', 200000, 1, '2026-10-06'],
]);

it('"transfer 1jt dari klien ke BCA" → pemasukan ke dompet BCA', function () {
    accSend('transfer 1jt dari klien ke BCA');

    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();
    expect($tx->type)->toBe('income')
        ->and((int) $tx->amount)->toBe(1000000)
        ->and($tx->balance_id)->toBe($this->bca->id)
        ->and((float) $this->bca->fresh()->balance)->toBe(1000000.0)
        ->and(Balance::where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('"…harusnya…" mengoreksi transaksi baru-baru ini bila nominal lamanya cocok', function () {
    accSend('beli sepatu 300rb');
    $tx = Transaction::where('tenant_id', $this->tenant->id)->sole();

    $reply = accSend('beli sepatu 300rb harusnya 400rb');

    expect(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and((int) $tx->fresh()->amount)->toBe(400000)
        ->and($reply)->toContain('Nominal Diperbarui');
});

it('dompet yang disebut eksplisit tetap dipakai', function () {
    accSend('bayar listrik pakai BCA 300rb');

    expect(Transaction::where('tenant_id', $this->tenant->id)->sole()->balance_id)->toBe($this->bca->id);
});

// ── Unit ─────────────────────────────────────────────────────────────────────

it('angka dalam kata', function (string $text, string $expected) {
    expect(AmountParser::wordsToDigits($text))->toBe($expected);
})->with([
    ['seratus ribu', '100000'],
    ['dua ribu', '2000'],
    ['dua puluh lima ribu', '25000'],
    ['satu setengah juta', '1500000'],
    ['dua ratus lima puluh ribu', '250000'],
    ['sejuta', '1000000'],
    ['setengah juta', '500000'],
    ['1 juta', '1 juta'],          // sudah angka — biarkan
    ['lima orang', 'lima orang'],  // tanpa skala — bukan nominal
]);

it('nominal gabungan', function (string $text, ?int $expected) {
    expect(AmountParser::composite($text))->toBe($expected);
})->with([
    ['bayar 100rb kembalian 20rb', 80000],
    ['beli sepatu 300rb harusnya 400rb', 400000],
    ['gaji 5jt potong bpjs 150rb', 4850000],
    ['gaji 3jt 400rb', 3400000],
    ['1 juta 250 ribu', 1250000],
    ['beli bakso 20rb dan es teh 5rb', null],
    ['beli kopi 25rb', null],
]);

it('kata penentu jenis', function (string $text, string $type) {
    expect(app(TransactionTypeDetector::class)->detect($text))->toBe($type);
})->with([
    ['gajian 5jt', 'income'],
    ['bayar gajian karyawan 2jt', 'expense'],
    ['transfer dari istri 500rb', 'income'],
    ['transfer ke istri 500rb', 'expense'],
    ['uang dari pembeli 200rb', 'income'],
    ['pesanan masuk 300rb', 'income'],
    ['tiket masuk 50rb', 'expense'],
    ['biaya admin 6500', 'expense'],
    ['terima kasih sudah bayar 100rb', 'expense'],
    ['terima gaji 5jt', 'income'],
]);

it('tanggal relatif & nama bulan', function (string $text, string $date) {
    expect((new TransactionExtractorService)->extractDateFromText($text))->toBe($date);
})->with([
    ['beli pulsa 5 oktober', '2026-10-05'],
    ['tgl 5 okt 2025 beli baju', '2025-10-05'],
    ['28 desember beli kado', '2025-12-28'],
    ['makan tadi malam', '2026-10-19'],
    ['makan semalam', '2026-10-19'],
    ['kemarin lusa beli bensin', '2026-10-18'],
    ['kemarin beli bensin', '2026-10-19'],
    ['tadi pagi sarapan', '2026-10-20'],
    ['lusa bayar kos', '2026-10-22'],
    ['3 hari lalu', '2026-10-17'],
    ['2 minggu lalu', '2026-10-06'],
    ['3 bulan lalu beli tv', '2026-07-20'],
    ['bulan lalu bayar kos', '2026-09-20'],
]);
