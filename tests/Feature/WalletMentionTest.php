<?php

/**
 * Dompet yang disebut di pesan dicocokkan ke daftar dompet MILIK USER, bukan ditebak
 * dari kata setelah "pakai/via":
 *  - "lewat rek mandiri" dulu tidak terbaca → masuk dompet utama tanpa pemberitahuan
 *  - "25rb makan siang" dulu menebak dompet "makan siang"
 *  - dompet yang belum terdaftar ("pakai ovo") dulu diam-diam memakai dompet utama
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
use App\Services\Wallet\WalletResolver;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function mentionSend(string $text): string
{
    $GLOBALS['mention_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000777', 'sender_id' => '6281400000077',
        'message_id' => 'mention-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['mention_replies']);
}

function mentionWallet(string $name, string $type, bool $default = false): Balance
{
    return Balance::create([
        'tenant_id' => test()->tenant->id, 'account_name' => $name, 'account_type' => $type, 'currency' => 'IDR',
        'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => $default,
    ]);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['mention_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['mention_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Mention', 'slug' => 'mention', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000777',
        'name' => 'Mention', 'config' => ['session_id' => 'wa_mention'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Arif', 'email' => 'mention@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281400000077', 'tenant_id' => $this->tenant->id,
    ]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    $this->utama = mentionWallet('Dompet Utama', 'cash', true);
    $this->mandiri = mentionWallet('Bank Mandiri', 'bank');
    $this->gopay = mentionWallet('Gopay', 'wallet');
});

it('memakai dompet user yang disebut di pesan', function (string $text, string $wallet) {
    $reply = mentionSend($text);

    $txs = Transaction::with('balance')->where('tenant_id', $this->tenant->id)->get();
    expect($txs)->toHaveCount(1)
        ->and($txs[0]->balance->account_name)->toBe($wallet)
        ->and($reply)->toContain($wallet)
        ->and($reply)->not->toContain('belum terdaftar');
})->with([
    ['makan siang 15rb lewat rek mandiri', 'Bank Mandiri'],
    ['makan siang 15rb lewat mandiri', 'Bank Mandiri'],
    ['makan siang 15rb pakai bank mandiri', 'Bank Mandiri'],
    ['makan siang 15rb debit mandiri', 'Bank Mandiri'],
    ['makan siang 15rb mandiri', 'Bank Mandiri'],
    ['makan siang 15rb pake gopay', 'Gopay'],
    ['makan siang 15rb qris gopay', 'Gopay'],
    ['makan siang 15rb pakai gopay ya', 'Gopay'],
]);

it('kata biasa tidak dianggap dompet dan tidak memunculkan peringatan', function (string $text) {
    $reply = mentionSend($text);

    $txs = Transaction::with('balance')->where('tenant_id', $this->tenant->id)->get();
    expect($txs)->toHaveCount(1)
        ->and($txs[0]->balance->account_name)->toBe('Dompet Utama')
        ->and($reply)->not->toContain('belum terdaftar');
})->with([
    '25rb makan siang',
    'bayar kos 1,5jt bulan lalu',
    'makan di warung mandiri 15rb',
    'beli sendok 15rb',
]);

it('dompet yang belum terdaftar diberi tahu, bukan diam-diam memakai dompet utama', function () {
    $reply = mentionSend('makan siang 15rb pakai ovo');

    $tx = Transaction::with('balance')->where('tenant_id', $this->tenant->id)->first();
    expect($tx->balance->account_name)->toBe('Dompet Utama')
        ->and($reply)->toContain('Dompet *Ovo* belum terdaftar')
        ->and($reply)->toContain('tambah dompet Ovo')
        ->and(Balance::where('tenant_id', $this->tenant->id)->count())->toBe(3);
});

it('WalletResolver: penanda cara bayar atau tepat setelah nominal di akhir', function (string $text, ?string $wallet) {
    expect((new WalletResolver)->match($this->tenant->id, $text)?->account_name)->toBe($wallet);
})->with([
    ['makan 15rb lewat rek mandiri', 'Bank Mandiri'],
    ['makan 15rb via qris mandiri', 'Bank Mandiri'],
    ['gaji 5jt masuk ke rekening mandiri', 'Bank Mandiri'],
    ['makan 15rb dari dompet utama', 'Dompet Utama'],
    ['makan 15rb gopay', 'Gopay'],
    ['makan di warung mandiri 15rb', null],
    ['makan 15rb di warung mandiri', null],
    ['beli buku mandiri belajar 50rb', null],
    ['makan pakai sendok 15rb', null],
]);

it('WalletResolver: mode anywhere untuk keterangan pendek', function () {
    $resolver = new WalletResolver;

    expect($resolver->match($this->tenant->id, 'masuk mandiri', true)?->account_name)->toBe('Bank Mandiri')
        ->and($resolver->match($this->tenant->id, 'makan siang', true))->toBeNull()
        ->and($resolver->looksLikeWalletName('ovo'))->toBeTrue()
        ->and($resolver->looksLikeWalletName('makan siang'))->toBeFalse()
        ->and($resolver->looksLikeWalletName('liburan', 'makan 15rb pakai dompet liburan'))->toBeTrue();
});

it('angka kecil tanpa satuan ditanyakan dulu, tidak langsung dicatat', function () {
    $reply = mentionSend('beli bensin 50');

    expect(Transaction::where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and($reply)->toContain('Rp 50.000');
});
