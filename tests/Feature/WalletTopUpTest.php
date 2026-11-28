<?php

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Category\CategoryManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function topupWorld(): array
{
    $tenant = Tenant::create(['name' => 'Topup', 'slug' => 'topup', 'is_active' => true]);
    $channel = Channel::create([
        'tenant_id' => $tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000777',
        'name' => 'Topup', 'config' => ['session_id' => 'wa_topup'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Syaifudin', 'email' => 'syaif@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000001', 'tenant_id' => $tenant->id,
    ]);
    Subscription::create(['tenant_id' => $tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    $main = Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    return [$tenant, $channel, $main];
}

function topupSend(string $text, array $world): string
{
    [$tenant, $channel] = $world;
    $GLOBALS['topup_replies'] = [];
    $message = Message::create([
        'tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000777', 'sender_id' => '6281800000001',
        'message_id' => 'topup-'.uniqid(), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['topup_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['topup_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['topup_replies'])]];
    });
    $this->world = topupWorld();
});

/**
 * Bug produksi 20/10/2026: "Tambahan tabungan brimo 1 juta" (dompet BRImo belum ada)
 * → tercatat PENGELUARAN Rp 1.000.000 & Dompet Utama jadi Rp -1.000.000.
 * Maksud user: saldo MASUK ke dompet/tabungan BRImo.
 */
it('setor tabungan ke dompet yang belum ada → dompet dibuat & saldo masuk, bukan pengeluaran', function () {
    $reply = topupSend('Tambahan tabungan brimo 1 juta', $this->world);

    $bri = Balance::where('tenant_id', $this->world[0]->id)->whereRaw('LOWER(account_name) LIKE ?', ['%bri%'])->first();

    expect(Transaction::where('type', 'expense')->count())->toBe(0)
        ->and((float) $this->world[2]->fresh()->balance)->toBe(0.0)
        ->and($bri)->not->toBeNull()
        ->and((float) $bri->balance)->toBe(1000000.0)
        ->and($reply)->toContain('Setor tabungan')
        ->and($reply)->not->toContain('Pengeluaran');
});

it('setor tabungan ke dompet yang sudah ada → saldonya bertambah', function () {
    $brimo = Balance::create([
        'tenant_id' => $this->world[0]->id, 'account_name' => 'BRImo', 'account_type' => 'bank', 'currency' => 'IDR',
        'balance' => 500000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);

    topupSend('tambahan tabungan BRImo 1 juta', $this->world);

    expect((float) $brimo->fresh()->balance)->toBe(1500000.0)
        ->and(Balance::where('tenant_id', $this->world[0]->id)->count())->toBe(2)
        ->and(Transaction::where('type', 'expense')->count())->toBe(0);
});

it('variasi kalimat setor tabungan', function (string $text, string $walletLike, float $amount) {
    topupSend($text, $this->world);

    $wallet = Balance::where('tenant_id', $this->world[0]->id)->whereRaw('LOWER(account_name) LIKE ?', ['%'.$walletLike.'%'])->first();
    expect($wallet)->not->toBeNull()
        ->and((float) $wallet->balance)->toBe($amount)
        ->and(Transaction::where('type', 'expense')->count())->toBe(0);
})->with([
    ['isi tabungan BCA 500rb', 'bca', 500000.0],
    ['nabung di jago 200rb', 'jago', 200000.0],
    ['setor tabungan ke mandiri 750rb', 'mandiri', 750000.0],
]);

it('mengenali pola setor tabungan', function (string $text, ?string $wallet) {
    expect(\App\Services\Wallet\WalletCommandService::matchSavingsDeposit($text))->toBe($wallet);
})->with([
    ['Tambahan tabungan brimo 1 juta', 'brimo'],
    ['tambah tabungan di BCA 500rb', 'BCA'],
    ['nabung ke Jago Hadi 100rb', 'Jago Hadi'],
    ['beli celengan 50rb', null],          // pembelian, bukan setor
    ['tambahan tabungan saya 1 juta', null], // "saya" bukan nama dompet
    ['nabung 100rb', null],                 // target tabungan → alur lama
]);
