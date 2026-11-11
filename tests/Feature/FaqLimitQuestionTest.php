<?php

/**
 * Pertanyaan soal batas pemakaian ("Pengeluaran hanya di batasi 50 kali/bulan ya?")
 * dulu jatuh ke "Format Tidak Dikenali" karena:
 *  - pola 'batas' dicocokkan sebagai kata utuh, sehingga "di batasi" lolos; dan
 *  - jawabannya sendiri salah: menjanjikan transaksi unlimited padahal paket
 *    Gratis dibatasi 50/bulan oleh SubscriptionLimitService.
 */

use App\Jobs\ProcessIncomingMessage;
use App\Models\Balance;
use App\Models\Category;
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

function faqSend(string $text): string
{
    $GLOBALS['faq_replies'] = [];
    $message = Message::create([
        'tenant_id' => test()->tenant->id, 'channel_id' => test()->channel->id, 'channel' => 'whatsapp',
        'channel_account' => '628999000333', 'sender_id' => '6281800000033',
        'message_id' => 'faq-'.uniqid('', true), 'type' => 'text', 'content' => $text, 'timestamp' => time(),
    ]);
    (new ProcessIncomingMessage($message))->handle();

    return implode("\n---\n", $GLOBALS['faq_replies']);
}

beforeEach(function () {
    Cache::flush();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($s, $to, $text) {
        $GLOBALS['faq_replies'][] = $text;

        return ['success' => true, 'data' => ['messageId' => 'wa-'.count($GLOBALS['faq_replies'])]];
    });

    $this->tenant = Tenant::create(['name' => 'Faq', 'slug' => 'faq', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $this->tenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000333',
        'name' => 'Faq', 'config' => ['session_id' => 'wa_faq'], 'is_active' => true,
    ]);
    User::create([
        'name' => 'Faq', 'email' => 'faq@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281800000033', 'tenant_id' => $this->tenant->id,
    ]);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);
    Balance::create([
        'tenant_id' => $this->tenant->id, 'account_name' => 'Dompet Utama', 'account_type' => 'cash',
        'currency' => 'IDR', 'balance' => 1_000_000, 'balance_date' => now()->toDateString(),
        'is_active' => true, 'is_default' => true,
    ]);
});

it('menjawab pertanyaan batas transaksi, bukan "Format Tidak Dikenali"', function () {
    // Tanpa subscription aktif → paket Gratis (50 transaksi/bulan).
    $reply = faqSend('Pengeluaran hanya di batasi 50 kali/bulan ya ?');

    expect($reply)->not->toContain('Format Tidak Dikenali')
        ->and($reply)->toContain('Batasan & Limit')
        ->and($reply)->toContain('50 per bulan')
        ->and($reply)->toContain('Gratis');
});

it('menyebut pemakaian bulan berjalan pada paket Gratis', function () {
    $categoryId = Category::where('tenant_id', $this->tenant->id)->value('id');

    foreach (range(1, 3) as $i) {
        Transaction::create([
            'tenant_id' => $this->tenant->id,
            'category_id' => $categoryId,
            'type' => 'expense',
            'amount' => 10000 * $i,
            'transaction_date' => now()->toDateString(),
            'description' => "dummy {$i}",
        ]);
    }

    expect(faqSend('ada limitnya ga?'))->toContain('3/50');
});

it('paket berbayar diberi tahu transaksi tanpa batas', function () {
    Subscription::create([
        'tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1,
        'price' => 0, 'status' => 'active',
    ]);

    $reply = faqSend('transaksi dibatasi berapa?');

    expect($reply)->toContain('tanpa batas')
        ->and($reply)->not->toContain('50 per bulan');
});

it('varian pertanyaan batas lain ikut terjawab', function (string $text) {
    expect(faqSend($text))->toContain('Batasan & Limit');
})->with([
    'maksimal berapa transaksi sebulan?',
    'apakah unlimited?',
    'ada batasnya ga',
    'limitnya berapa',
    'berapa kali per bulan bisa catat',
]);

it('tidak membajak transaksi biasa yang kebetulan menyebut kuota', function () {
    $reply = faqSend('beli kuota 50rb');

    expect($reply)->not->toContain('Batasan & Limit');
    expect(Transaction::where('tenant_id', $this->tenant->id)->where('amount', 50000)->exists())->toBeTrue();
});
