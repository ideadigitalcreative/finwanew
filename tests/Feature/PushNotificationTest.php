<?php

/**
 * Push notification Android (FCM HTTP v1) untuk transaksi baru — Opsi A.
 */

use App\Jobs\SendTransactionPush;
use App\Models\Category;
use App\Models\DeviceToken;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;
use App\Services\Push\TransactionPushNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function pushServiceAccountJson(): string
{
    static $json = null;
    if ($json === null) {
        // Kunci RSA KHUSUS TEST (bukan kredensial asli) — openssl_pkey_new butuh
        // openssl.cnf yang sering tidak ada di PHP Windows.
        $pem = file_get_contents(base_path('tests/fixtures/fcm-test-private-key.pem'));
        $json = json_encode([
            'type' => 'service_account',
            'project_id' => 'finwa-test',
            'client_email' => 'push@finwa-test.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]);
    }

    return $json;
}

/** @param  int  $fcmStatus  status balasan FCM untuk send */
function pushFakeGoogle(int $fcmStatus = 200, array $fcmBody = ['name' => 'projects/finwa-test/messages/1']): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response($fcmBody, $fcmStatus),
    ]);
}

function pushTx(Tenant $tenant, array $attrs = []): Transaction
{
    return Transaction::create(array_merge([
        'tenant_id' => $tenant->id,
        'category_id' => Category::where('tenant_id', $tenant->id)->where('type', 'like', 'pengeluaran_%')->value('id'),
        'type' => 'expense',
        'amount' => 91000,
        'transaction_date' => now()->toDateString(),
        'description' => 'beli beras',
        'source' => 'local_extraction',
        'status' => 'confirmed',
    ], $attrs));
}

/** Kirim pesan FCM yang tercatat: [token => body] */
function pushSentMessages(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))
        ->mapWithKeys(fn ($pair) => [$pair[0]['message']['token'] => $pair[0]['message']['notification']])
        ->all();
}

beforeEach(function () {
    Cache::flush();
    config([
        'finwa_push.enabled' => true,
        'finwa_push.credentials' => pushServiceAccountJson(),
        'finwa_push.batch_window_seconds' => 0,
        'finwa_push.quiet_hours' => '',
        'finwa_push.notify_actor' => true,
    ]);

    $this->tenant = Tenant::create(['name' => 'Keluarga', 'slug' => 'keluarga', 'is_active' => true]);
    (new CategoryManagerService)->createCategoriesForTenant($this->tenant->id);

    $this->owner = User::factory()->create(['name' => 'Haerul', 'tenant_id' => $this->tenant->id]);
    $this->member = User::factory()->create(['name' => 'Ivan', 'tenant_id' => $this->tenant->id]);
    $this->outsider = User::factory()->create(['name' => 'Orang Lain']);

    DeviceToken::create(['user_id' => $this->owner->id, 'token' => str_repeat('o', 40)]);
    DeviceToken::create(['user_id' => $this->member->id, 'token' => str_repeat('m', 40)]);
    DeviceToken::create(['user_id' => $this->outsider->id, 'token' => str_repeat('x', 40)]);

    $this->memberWa = UserWhatsAppNumber::create([
        'user_id' => $this->member->id, 'tenant_id' => $this->tenant->id,
        'whatsapp_number' => '6281500000002', 'name' => 'Ivan', 'is_active' => true,
    ]);
});

it('transaksi via WhatsApp → semua anggota tenant mendapat popup, tenant lain tidak', function () {
    pushFakeGoogle();

    pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);

    $sent = pushSentMessages();
    expect(array_keys($sent))->toEqualCanonicalizing([str_repeat('o', 40), str_repeat('m', 40)])
        ->and($sent[str_repeat('o', 40)]['title'])->toBe('💸 Pengeluaran baru')
        ->and($sent[str_repeat('o', 40)]['body'])->toContain('Ivan: beli beras — Rp 91.000');

    // OAuth: JWT bertanda tangan RS256 dikirim ke Google
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')
        && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && count(explode('.', $r['assertion'])) === 3);
});

it('transaksi dari dashboard → pencatat tidak dikirimi, anggota lain dikirimi', function () {
    pushFakeGoogle();
    $this->actingAs($this->owner);

    pushTx($this->tenant, ['source' => 'dashboard']);

    expect(array_keys(pushSentMessages()))->toBe([str_repeat('m', 40)]);
});

it('banyak transaksi (batch/struk) digabung jadi satu notifikasi ringkasan', function () {
    Queue::fake();
    pushFakeGoogle();

    foreach ([300000, 95000, 87000] as $amount) {
        pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id, 'amount' => $amount]);
    }

    Queue::assertPushed(SendTransactionPush::class, 1);

    $job = Queue::pushed(SendTransactionPush::class)->first();
    app(TransactionPushNotifier::class)->flush($job->key);

    $sent = pushSentMessages();
    expect($sent)->toHaveCount(2)
        ->and($sent[str_repeat('o', 40)]['title'])->toBe('🧾 3 transaksi baru')
        ->and($sent[str_repeat('o', 40)]['body'])->toBe('Ivan mencatat 3 transaksi · keluar Rp 482.000');
});

it('opsi sembunyikan nominal & nonaktifkan notifikasi dihormati', function () {
    pushFakeGoogle();
    $this->owner->forceFill(['push_hide_amount' => true])->save();
    $this->member->forceFill(['push_enabled' => false])->save();

    pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);

    $sent = pushSentMessages();
    expect(array_keys($sent))->toBe([str_repeat('o', 40)])
        ->and($sent[str_repeat('o', 40)]['body'])->not->toContain('Rp');
});

it('token yang sudah tidak berlaku (UNREGISTERED) dihapus otomatis', function () {
    pushFakeGoogle(404, ['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]]);

    pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);

    expect(DeviceToken::where('user_id', $this->owner->id)->exists())->toBeFalse()
        ->and(DeviceToken::where('user_id', $this->outsider->id)->exists())->toBeTrue();
});

it('tidak mengirim: push dimatikan, pindah saldo internal, transaksi pending', function () {
    Queue::fake();

    config(['finwa_push.enabled' => false]);
    pushTx($this->tenant);
    config(['finwa_push.enabled' => true]);
    pushTx($this->tenant, ['type' => 'debit_internal']);
    pushTx($this->tenant, ['status' => 'pending']);

    Queue::assertNothingPushed();
});

it('jam tenang: notifikasi tidak dikirim', function () {
    pushFakeGoogle();
    config(['finwa_push.quiet_hours' => '00:00-23:59']);

    pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);

    expect(pushSentMessages())->toBe([]);
});

it('gagal kirim push tidak menggagalkan pencatatan transaksi', function () {
    Http::fake(['*' => fn () => throw new \RuntimeException('network down')]);

    $tx = pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);

    expect(Transaction::find($tx->id))->not->toBeNull();
});

// ── Registrasi token dari WebView ──

it('WebView mendaftarkan token lewat sesi login; token pindah bila HP dipakai akun lain', function () {
    $token = 'fcm-'.str_repeat('a', 60);

    $this->actingAs($this->owner)->postJson('/device-tokens', ['token' => $token, 'device_name' => 'Samsung A54'])
        ->assertOk()->assertJsonPath('success', true);
    expect(DeviceToken::where('token', $token)->value('user_id'))->toBe($this->owner->id);

    $this->actingAs($this->member)->postJson('/device-tokens', ['token' => $token])->assertOk();
    expect(DeviceToken::where('token', $token)->count())->toBe(1)
        ->and(DeviceToken::where('token', $token)->value('user_id'))->toBe($this->member->id);

    $this->actingAs($this->member)->deleteJson('/device-tokens', ['token' => $token])->assertOk();
    expect(DeviceToken::where('token', $token)->exists())->toBeFalse();
});

it('registrasi token wajib login', function () {
    $this->postJson('/device-tokens', ['token' => str_repeat('z', 40)])->assertUnauthorized();
});

it('halaman pengaturan notifikasi menyimpan preferensi', function () {
    $this->actingAs($this->owner)
        ->put('/settings/notifications', ['push_enabled' => false, 'push_hide_amount' => true])
        ->assertRedirect();

    $this->owner->refresh();
    expect($this->owner->push_enabled)->toBeFalse()
        ->and($this->owner->push_hide_amount)->toBeTrue();
});

// ── Diagnosa kredensial (finwa:push-test) ──

it('diagnosa menjelaskan kenapa kredensial FCM belum siap', function (string $credentials, string $expected) {
    config(['finwa_push.credentials' => $credentials]);

    expect((new \App\Services\Push\FcmClient)->diagnose())->toContain($expected);
})->with([
    'file tidak ada' => ['storage/app/tidak-ada.json', 'File tidak ditemukan'],
    'bukan json' => ['{bukan json', 'bukan JSON yang valid'],
    'google-services.json' => ['{"project_info":{"project_id":"x"},"client":[]}', 'google-services.json'],
    'bukan service account' => ['{"type":"user"}', 'bukan service account'],
]);

it('diagnosa null & perintah uji jalan bila kredensial valid', function () {
    pushFakeGoogle();

    expect((new \App\Services\Push\FcmClient)->diagnose())->toBeNull();

    $this->artisan('finwa:push-test', ['user' => $this->owner->email])
        ->expectsOutputToContain('finwa-test')
        ->assertSuccessful();
});

it('perintah uji bisa mencari user berdasarkan nama', function () {
    pushFakeGoogle();

    $this->artisan('finwa:push-test', ['user' => 'haeru'])
        ->expectsOutputToContain('Ditemukan berdasarkan pencarian: Haerul')
        ->assertSuccessful();

    $this->artisan('finwa:push-test', ['user' => 'Haerul_hadi_email@domain.com'])
        ->expectsOutputToContain('Ditemukan berdasarkan pencarian: Haerul')
        ->assertSuccessful();
});

it('finwa:push-check menelusuri transaksi terakhir & --send mengirim tanpa antrean', function () {
    Queue::fake(); // seolah worker tidak jalan
    pushFakeGoogle();
    pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id]);
    expect(pushSentMessages())->toBe([]);

    $this->artisan('finwa:push-check', ['user' => $this->owner->email, '--send' => true])
        ->expectsOutputToContain('Observer transaksi terdaftar')
        ->expectsOutputToContain('terkirim ke 2 perangkat')
        ->assertSuccessful();

    expect(pushSentMessages())->toHaveCount(2);
});

it('finwa:push-check menjelaskan alasan tidak dikirim', function () {
    Queue::fake();
    $this->owner->forceFill(['push_enabled' => false])->save();
    $this->member->forceFill(['push_enabled' => false])->save();
    pushTx($this->tenant);

    $this->artisan('finwa:push-check', ['user' => $this->owner->email])
        ->expectsOutputToContain('tidak ada penerima')
        ->assertSuccessful();
});

// ── Jalur cepat: kirim begitu job pesan selesai (tanpa antre job baru) ──

it('notifikasi terkirim begitu job pencatatan selesai, batch tetap 1 notifikasi', function () {
    Queue::fake(); // job cadangan tidak dijalankan → membuktikan jalur cepat yang mengirim
    pushFakeGoogle();

    // Di dalam job ProcessIncomingMessage: 3 baris batch tercatat
    foreach ([30000, 20000, 10000] as $amount) {
        pushTx($this->tenant, ['user_whatsapp_number_id' => $this->memberWa->id, 'amount' => $amount]);
    }
    expect(pushSentMessages())->toBe([]); // belum dikirim selama job masih berjalan

    // Worker menyelesaikan job
    event(new \Illuminate\Queue\Events\JobProcessed('database', Mockery::mock(\Illuminate\Contracts\Queue\Job::class)));

    $sent = pushSentMessages();
    expect($sent)->toHaveCount(2)
        ->and($sent[str_repeat('o', 40)]['title'])->toBe('🧾 3 transaksi baru');

    // Job cadangan yang menyusul tidak mengirim ulang
    app(TransactionPushNotifier::class)->flush(Queue::pushed(SendTransactionPush::class)->first()->key);
    expect(pushSentMessages())->toHaveCount(2);
});

it('transaksi dari request web dikirim setelah respons selesai', function () {
    Queue::fake();
    pushFakeGoogle();
    $this->actingAs($this->owner);

    pushTx($this->tenant, ['source' => 'dashboard']);
    app()->terminate();

    expect(array_keys(pushSentMessages()))->toBe([str_repeat('m', 40)]);
});
