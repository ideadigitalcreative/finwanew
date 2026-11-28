<?php

/**
 * Bug produksi 19/10/2026: "dompet utama 100 rb" → "Pengeluaran Tercatat Rp 100,
 * keterangan: rb". Nominal dengan spasi sebelum satuan ("100 rb") salah dibaca.
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
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function walletWorld(): array
{
    $tenant = Tenant::create(['name' => 'Wallet', 'slug' => 'wallet', 'is_active' => true]);
    $channel = Channel::create([
        'tenant_id' => $tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000555',
        'name' => 'Wallet', 'config' => ['session_id' => 'wa_wallet'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Tyyz', 'email' => 'tyyz@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281600000001', 'tenant_id' => $tenant->id,
    ]);
    Subscription::create([
        'tenant_id' => $tenant->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active',
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    $wallet = Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Utama', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 0, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);

    return [$tenant, $channel, $wallet];
}

function walletSend(string $text, array $world): string
{
    [$tenant, $channel] = $world;
    $GLOBALS['wallet_replies'] = [];
    $message = Message::create([
        'tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000555', 'sender_id' => '6281600000001',
        'message_id' => 'wallet-'.uniqid(), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['wallet_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['wallet_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['wallet_replies'])]];
    });
    $this->world = walletWorld();
});

it('"dompet utama 100 rb" tidak lagi dicatat Rp 100 — ditanya balik dulu', function () {
    $reply = walletSend('dompet utama 100 rb', $this->world);

    expect(Transaction::where('tenant_id', $this->world[0]->id)->count())->toBe(0)
        ->and((float) $this->world[2]->fresh()->balance)->toBe(0.0)
        ->and($reply)->toContain('Dompet Utama')
        ->and($reply)->toContain('Rp 100.000')
        ->and($reply)->toContain('Mau diapakan');
});

it('jawaban "set" → saldo dompet jadi 100.000', function () {
    walletSend('dompet utama 100 rb', $this->world);
    $reply = walletSend('set', $this->world);

    // Tahap C: bukan pemasukan/pengeluaran, tapi 1 baris "Penyesuaian saldo" yang menjelaskan saldo
    expect((float) $this->world[2]->fresh()->balance)->toBe(100000.0)
        ->and(Transaction::where('tenant_id', $this->world[0]->id)->whereIn('type', ['income', 'expense'])->count())->toBe(0)
        ->and(Transaction::where('tenant_id', $this->world[0]->id)->sole()->kind())->toBe(Transaction::KIND_BALANCE_ADJUSTMENT)
        ->and($reply)->toContain('Saldo Berhasil Diubah');
});

it('jawaban "tambah" → saldo bertambah 100.000', function () {
    $this->world[2]->forceFill(['balance' => 50000])->save();
    walletSend('dompet utama 100 rb', $this->world);
    walletSend('tambah', $this->world);

    expect((float) $this->world[2]->fresh()->balance)->toBe(150000.0);
});

it('jawaban "keluar" → pengeluaran Rp 100.000 dari dompet Utama', function () {
    walletSend('dompet utama 100 rb', $this->world);
    walletSend('keluar', $this->world);

    $tx = Transaction::where('tenant_id', $this->world[0]->id)->first();
    expect($tx)->not->toBeNull()
        ->and($tx->type)->toBe('expense')
        ->and((int) $tx->amount)->toBe(100000)
        ->and($tx->balance_id)->toBe($this->world[2]->id);
});

it('nominal bersatuan dengan spasi tetap utuh bila ada keterangan', function (string $text, int $amount, string $desc) {
    walletSend($text, $this->world);

    $tx = Transaction::where('tenant_id', $this->world[0]->id)->first();
    expect((int) $tx->amount)->toBe($amount)
        ->and($tx->description)->toContain($desc)
        ->and($tx->type)->toBe('expense');
})->with([
    ['dompet utama 100 rb bensin', 100000, 'bensin'],
    ['dompet utama 100rb belanja', 100000, 'belanja'],
    ['bayar dari utama 25 rb parkir', 25000, 'parkir'],
]);

it('set saldo diterapkan ke dompet yang disebut, bukan dompet utama', function () {
    $dana = Balance::create([
        'tenant_id' => $this->world[0]->id, 'account_name' => 'Dana', 'account_type' => 'wallet', 'currency' => 'IDR',
        'balance' => 10000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => false,
    ]);

    walletSend('dompet dana 250 rb', $this->world);
    walletSend('set', $this->world);

    expect((float) $dana->fresh()->balance)->toBe(250000.0)
        ->and((float) $this->world[2]->fresh()->balance)->toBe(0.0);
});
