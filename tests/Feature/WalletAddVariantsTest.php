<?php

/**
 * Bug produksi 23/10/2026 (rizalsw): "Tambahkan dompet seabank" → dibalas sapaan.
 * Router dulu hanya mengenali awalan persis "tambah dompet"; "tambahkan/tambahin/bikin…" lolos.
 */

use App\Helpers\KeywordNormalizer;
use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function wavSend(string $text): string
{
    $GLOBALS['wav_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000125', 'sender_id' => '6281300000044',
        'message_id' => 'wav-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['wav_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['wav_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['wav_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Rizal', 'slug' => 'rizal', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000125',
        'name' => 'FinWa', 'config' => ['session_id' => 'wa_rizal'], 'is_active' => true,
    ]);
    User::create(['name' => 'Rizal', 'email' => 'rizal@example.com', 'password' => bcrypt('x'), 'whatsapp_number' => '6281300000044', 'tenant_id' => $this->tenant->id]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
});

it('menyeragamkan variasi kata kerja tambah dompet', function (string $input, string $expected) {
    expect(KeywordNormalizer::normalize($input))->toBe($expected);
})->with([
    ['Tambahkan dompet seabank', 'tambah dompet seabank'],
    ['tambahin rekening BCA', 'tambah rekening BCA'],
    ['bikin e-wallet Gopay', 'tambah dompet Gopay'],
    ['tolong buatkan dompet baru Jago', 'tambah dompet baru Jago'],
    ['mau tambah dompet OVO saldo 50rb', 'tambah dompet OVO saldo 50rb'],
    ['daftarkan akun bank Mandiri', 'tambah bank Mandiri'],
    ['tambah dompet BCA', 'tambah dompet BCA'],
    ['beli dompet kulit 200rb', 'beli dompet kulit 200rb'], // pembelian, bukan perintah
]);

it('kasus rizalsw: "Tambahkan dompet seabank" → dompet Seabank dibuat', function (string $text, string $name) {
    $reply = wavSend($text);

    // "BCA" disimpan sebagai "Bank BCA" (normalisasi nama bank yang sudah ada)
    $wallet = Balance::where('tenant_id', $this->tenant->id)->whereRaw('LOWER(account_name) LIKE ?', ['%'.mb_strtolower($name).'%'])->first();
    expect($wallet)->not->toBeNull()
        ->and($wallet->account_type)->toBe('bank')
        ->and($reply)->not->toContain('Selamat');
})->with([
    ['Tambahkan dompet seabank', 'seabank'],
    ['tambahin rekening BCA', 'bca'],
    ['bikin rekening Jago', 'jago'],
]);

it('saldo awal ikut terbaca: "tambahkan dompet seabank saldo 500rb"', function () {
    wavSend('tambahkan dompet seabank saldo 500rb');

    $wallet = Balance::where('tenant_id', $this->tenant->id)->whereRaw('LOWER(account_name) = ?', ['seabank'])->sole();
    expect((float) $wallet->balance)->toBe(500_000.0);
});
