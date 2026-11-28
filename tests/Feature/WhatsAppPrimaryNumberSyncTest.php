<?php

/**
 * Bug produksi 23/11/2026: user menambah nomor baru lalu menghapus nomor utama lama.
 * Daftar nomor otomatis menjadikan nomor baru sebagai utama, tetapi
 * users.whatsapp_number tetap menunjuk nomor lama yang sudah dihapus.
 */

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppService;

beforeEach(function () {
    $this->mock(WhatsAppService::class)->shouldIgnoreMissing();

    $this->tenant = Tenant::create(['name' => 'Sinkron', 'slug' => 'sinkron', 'is_active' => true]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    $this->user = User::create([
        'name' => 'Pemilik', 'email' => 'sinkron@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6281111111111', 'tenant_id' => $this->tenant->id,
    ]);
    // Keanggotaan tenant (dicek middleware "tenant")
    $this->user->tenants()->attach($this->tenant->id, ['is_active' => true, 'joined_at' => now()]);
    $this->old = UserWhatsAppNumber::create([
        'user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => '6281111111111',
        'name' => 'Lama', 'is_primary' => true, 'is_active' => true,
    ]);
    $this->new = UserWhatsAppNumber::create([
        'user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => '6282222222222',
        'name' => 'Baru', 'is_primary' => false, 'is_active' => true,
    ]);
});

function primarySyncAsUser()
{
    return test()->actingAs(test()->user)
        ->withSession(['current_tenant_id' => test()->tenant->id])
        ->withoutMiddleware([
            \App\Http\Middleware\CheckSubscription::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
}

it('menghapus nomor utama → nomor lain jadi utama DAN data akun ikut pindah', function () {
    primarySyncAsUser()->delete(route('whatsapp.numbers.destroy', $this->old))->assertRedirect();

    expect(UserWhatsAppNumber::find($this->old->id))->toBeNull()
        ->and($this->new->fresh()->is_primary)->toBeTrue()
        ->and($this->user->fresh()->whatsapp_number)->toBe('6282222222222');
});

it('menghapus nomor bukan-utama tidak mengubah nomor akun', function () {
    primarySyncAsUser()->delete(route('whatsapp.numbers.destroy', $this->new))->assertRedirect();

    expect($this->user->fresh()->whatsapp_number)->toBe('6281111111111')
        ->and($this->old->fresh()->is_primary)->toBeTrue();
});
