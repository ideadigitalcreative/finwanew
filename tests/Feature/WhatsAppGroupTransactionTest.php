<?php

/**
 * Transaksi di grup WhatsApp.
 * Keputusan produk: pemicu "#" / mention · hanya nomor terdaftar · Premium · 1 grup = 1 akun.
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

const GRP_JID = '120363000000000001@g.us';
const OWNER_PHONE = '6281700000001';
const PARTNER_PHONE = '6281700000002';
const STRANGER_PHONE = '6281799999999';

function grpTenant(string $slug, string $plan, array $numbers): array
{
    $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'is_active' => true]);
    if ($plan !== 'free') { // akun gratis = tanpa langganan
        Subscription::create(['tenant_id' => $tenant->id, 'plan' => $plan, 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    }
    (new CategoryManagerService)->createCategoriesForTenant($tenant->id);
    Balance::create([
        'tenant_id' => $tenant->id, 'account_name' => 'Cash', 'account_type' => 'cash', 'currency' => 'IDR',
        'balance' => 1000000, 'balance_date' => now()->toDateString(), 'is_active' => true, 'is_default' => true,
    ]);
    $owner = User::create([
        'name' => "Pemilik {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'),
        'whatsapp_number' => array_key_first($numbers), 'tenant_id' => $tenant->id,
    ]);
    $ids = [];
    foreach ($numbers as $phone => $label) {
        $ids[$phone] = UserWhatsAppNumber::create([
            'user_id' => $owner->id, 'tenant_id' => $tenant->id, 'whatsapp_number' => $phone,
            'name' => $label, 'is_primary' => $phone === array_key_first($numbers), 'is_active' => true,
        ])->id;
    }

    return [$tenant, $owner, $ids];
}

/** Kirim pesan grup seperti payload gateway → webhook. */
function grpIncoming(string $body, string $authorPhone, string $authorName = 'Anggota', bool $mentionsBot = false): array
{
    static $n = 0;
    $n++;
    $result = app(WhatsAppWebhookService::class)->processIncomingMessage([
        'id' => "grp-msg-{$n}-".uniqid(),
        'from' => explode('@', GRP_JID)[0],
        'originalFrom' => GRP_JID,
        'body' => $body,
        'type' => 'chat',
        'timestamp' => time(),
        'isGroup' => true,
        'group' => [
            'jid' => GRP_JID,
            'authorPhone' => $authorPhone,
            'authorLid' => null,
            'authorName' => $authorName,
            'mentionedJids' => $mentionsBot ? ['628999000666@s.whatsapp.net'] : [],
            'mentionsBot' => $mentionsBot,
        ],
    ], test()->sessionId);

    // Jalankan job yang di-queue (antrean database di-fake)
    foreach (Message::where('status', 'received')->get() as $m) {
        (new ProcessIncomingMessage($m))->handle();
        $m->forceFill(['status' => 'processed'])->save();
    }

    return $result;
}

function grpSent(): array
{
    return $GLOBALS['grp_sent'];
}

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    config(['services.finwa_ai.enabled' => false, 'finwa_reply_edit.enabled' => false, 'finwa_push.enabled' => false]);
    Http::fake(['*' => Http::response(['error' => 'offline'], 500)]);

    $GLOBALS['grp_sent'] = [];
    $mock = $this->mock(WhatsAppService::class);
    $mock->shouldIgnoreMissing();
    $mock->shouldReceive('sendMessage')->andReturnUsing(function ($session, $to, $text, $type = 'text', $originalLid = null) {
        $GLOBALS['grp_sent'][] = ['to' => $to, 'text' => $text, 'lid' => $originalLid];

        return ['success' => true, 'data' => ['messageId' => 'out-'.count($GLOBALS['grp_sent'])]];
    });

    // Channel bot (sesi WhatsApp FinWa)
    $botTenant = Tenant::create(['name' => 'Bot', 'slug' => 'bot', 'is_active' => true]);
    $this->channel = Channel::create([
        'tenant_id' => $botTenant->id, 'type' => 'whatsapp', 'channel_account' => '628999000666',
        'name' => 'FinWa', 'config' => ['session_id' => "wa_{$botTenant->id}_628999000666"], 'is_active' => true,
    ]);
    $this->sessionId = "wa_{$botTenant->id}_628999000666";

    [$this->tenant, $this->owner, $this->numbers] = grpTenant('keluarga', 'pro', [
        OWNER_PHONE => 'Suami',
        PARTNER_PHONE => 'Istri',
    ]);
});

it('obrolan biasa tanpa pemicu diabaikan tanpa balasan', function () {
    $r = grpIncoming('halo semua, nanti ketemu jam 5 ya', OWNER_PHONE);

    expect($r['reason'])->toBe('group_no_trigger')
        ->and(grpSent())->toBe([])
        ->and(Message::count())->toBe(0);
});

it('grup belum aktif → info singkat sekali saja', function () {
    grpIncoming('#beli kopi 25rb', OWNER_PHONE);
    grpIncoming('#beli teh 5rb', OWNER_PHONE);

    expect(grpSent())->toHaveCount(1)
        ->and(grpSent()[0]['to'])->toBe(GRP_JID)
        ->and(grpSent()[0]['text'])->toContain('belum aktif')
        ->and(Transaction::count())->toBe(0);
});

it('pemilik Premium mengaktifkan grup, lalu pasangan mencatat transaksi', function () {
    grpIncoming('#aktifkan finwa', OWNER_PHONE, 'Suami');

    expect(WhatsappGroup::where('group_jid', GRP_JID)->value('tenant_id'))->toBe($this->tenant->id)
        ->and(grpSent()[0]['text'])->toContain('FinWa aktif di grup ini');

    $GLOBALS['grp_sent'] = [];
    grpIncoming('#beli sayur 50rb', PARTNER_PHONE, 'Istri');

    $tx = Transaction::where('tenant_id', $this->tenant->id)->first();
    expect($tx)->not->toBeNull()
        ->and((int) $tx->amount)->toBe(50000)
        ->and($tx->user_whatsapp_number_id)->toBe($this->numbers[PARTNER_PHONE]);

    // Balasan ke GRUP, bertanda pencatat, tanpa info saldo
    $reply = collect(grpSent())->firstWhere('to', GRP_JID);
    expect($reply)->not->toBeNull()
        ->and($reply['lid'])->toBeNull()
        ->and($reply['text'])->toContain('👤 *Istri*')
        ->and($reply['text'])->not->toMatch('/saldo/i');
});

it('mention bot juga menjadi pemicu', function () {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);

    grpIncoming('@628999000666 beli bensin 30rb', OWNER_PHONE, 'Suami', mentionsBot: true);

    expect((int) Transaction::where('tenant_id', $this->tenant->id)->value('amount'))->toBe(30000);
});

it('anggota yang nomornya tidak terdaftar di akun tidak bisa mencatat', function () {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);

    $r = grpIncoming('#beli rokok 30rb', STRANGER_PHONE, 'Tamu');

    expect($r['reason'])->toBe('group_member_not_registered')
        ->and(Transaction::count())->toBe(0)
        ->and(grpSent()[0]['text'])->toContain('belum terdaftar');
});

it('akun non-Premium tidak bisa mengaktifkan grup', function () {
    [$free] = grpTenant('gratisan', 'free', ['6281700000077' => 'Saya']);

    grpIncoming('#aktifkan finwa', '6281700000077');

    expect(WhatsappGroup::count())->toBe(0)
        ->and(grpSent()[0]['text'])->toContain('Paket Pro');
});

it('1 grup = 1 akun: akun lain tidak bisa mengambil alih', function () {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);
    grpTenant('lain', 'pro', ['6281700000088' => 'Lain']);

    grpIncoming('#aktifkan finwa', '6281700000088');

    expect(WhatsappGroup::where('group_jid', GRP_JID)->value('tenant_id'))->toBe($this->tenant->id)
        ->and(grpSent()[0]['text'])->toContain('sudah terhubung ke akun FinWa lain');
});

it('perintah berbahaya diblokir di grup', function (string $cmd) {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);

    grpIncoming($cmd, OWNER_PHONE);

    expect(collect(grpSent())->pluck('text')->implode("\n"))->toContain('tidak bisa dipakai di grup')
        ->and((float) Balance::where('tenant_id', $this->tenant->id)->value('balance'))->toBe(1000000.0);
})->with(['#reset akun', '#hapus semua transaksi', '#set saldo Cash menjadi 5jt']);

it('cek saldo dari grup dijawab lewat chat pribadi', function () {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);

    grpIncoming('#cek saldo', PARTNER_PHONE, 'Istri');

    $toGroup = collect(grpSent())->where('to', GRP_JID);
    $toPrivate = collect(grpSent())->where('to', PARTNER_PHONE);
    expect($toGroup)->toHaveCount(1)
        ->and($toGroup->first()['text'])->toContain('chat pribadi')
        ->and($toPrivate)->not->toBeEmpty()
        ->and($toPrivate->pluck('text')->implode("\n"))->toMatch('/saldo|Rp/i');
});

it('nonaktifkan grup oleh anggota akun', function () {
    WhatsappGroup::create(['group_jid' => GRP_JID, 'tenant_id' => $this->tenant->id, 'is_active' => true]);

    grpIncoming('#nonaktifkan finwa', PARTNER_PHONE);

    expect(WhatsappGroup::where('group_jid', GRP_JID)->value('is_active'))->toBeFalse();
});

it('#bantuan menampilkan panduan grup', function () {
    grpIncoming('#bantuan', STRANGER_PHONE);

    expect(grpSent()[0]['text'])->toContain('FinWa di Grup');
});

it('Paket Lite tidak bisa mengaktifkan grup (fitur khusus Pro)', function () {
    grpTenant('lite', 'growth', ['6281700000055' => 'Lite']);

    grpIncoming('#aktifkan finwa', '6281700000055');

    expect(WhatsappGroup::count())->toBe(0)
        ->and(grpSent()[0]['text'])->toContain('Paket Pro');
});
