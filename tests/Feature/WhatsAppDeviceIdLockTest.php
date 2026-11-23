<?php

/**
 * Bug produksi 23/11/2026 (akun Juanito): pendaftaran lewat WhatsApp menyimpan ID
 * perangkat (LID) sebagai "Primary Number" dengan is_lid=false. Halaman WhatsApp
 * menampilkannya seperti nomor HP biasa — bisa diedit & dihapus — dan ia ikut
 * menghabiskan slot nomor paket. User menghapusnya agar bisa menambah nomor, lalu
 * pencatatan "atas nama" jadi kacau.
 */

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use App\Services\SubscriptionLimitService;
use App\Services\WhatsAppService;
use Inertia\Testing\AssertableInertia as Assert;

const DEV_LID = '233783743795376';

beforeEach(function () {
    $this->mock(WhatsAppService::class)->shouldIgnoreMissing();

    $this->tenant = Tenant::create(['name' => 'Kunci', 'slug' => 'kunci', 'is_active' => true]);
    Subscription::create(['tenant_id' => $this->tenant->id, 'plan' => 'pro', 'duration_months' => 1, 'price' => 0, 'status' => 'active', 'ends_at' => now()->addMonth()]);
    $this->user = User::create([
        'name' => 'Pemilik', 'email' => 'kunci@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => DEV_LID, 'tenant_id' => $this->tenant->id,
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['is_active' => true, 'joined_at' => now()]);

    // Persis seperti WhatsAppRegistrationHelper: LID disimpan sebagai nomor utama, is_lid=false
    $this->device = UserWhatsAppNumber::create([
        'user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'whatsapp_number' => DEV_LID,
        'name' => 'Primary Number', 'is_primary' => true, 'is_active' => true, 'is_lid' => false,
    ]);
});

function devAsUser()
{
    return test()->actingAs(test()->user)
        ->withSession(['current_tenant_id' => test()->tenant->id])
        ->withoutMiddleware([
            \App\Http\Middleware\CheckSubscription::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
}

function devAddPhone(string $number, bool $primary = false): UserWhatsAppNumber
{
    return UserWhatsAppNumber::create([
        'user_id' => test()->user->id, 'tenant_id' => test()->tenant->id, 'whatsapp_number' => $number,
        'name' => 'HP', 'is_primary' => $primary, 'is_active' => true, 'is_lid' => false,
    ]);
}

it('mengenali ID perangkat walau tersimpan is_lid=false', function (string $number, bool $expected) {
    expect(UserWhatsAppNumber::looksLikeDeviceId($number))->toBe($expected);
})->with([
    [DEV_LID, true],
    ['77554392272898', true],
    ['6282133186715', false],
    ['628123456789', false],
]);

it('halaman WhatsApp memberi tanda ID perangkat pada nomor utama yang berupa LID', function () {
    devAddPhone('6282133186715');

    devAsUser()->get(route('whatsapp.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('WhatsApp/Index')
            ->where('userWhatsAppNumbers.0.whatsapp_number', DEV_LID)
            ->where('userWhatsAppNumbers.0.is_device_id', true)
            ->where('userWhatsAppNumbers.1.whatsapp_number', '6282133186715')
            ->where('userWhatsAppNumbers.1.is_device_id', false));
});

it('ID perangkat yang bukan nomor utama disembunyikan', function () {
    devAddPhone('6282133186715', true);
    $this->device->update(['is_primary' => false]);

    devAsUser()->get(route('whatsapp.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('userWhatsAppNumbers', 1)
            ->where('userWhatsAppNumbers.0.whatsapp_number', '6282133186715'));
});

it('ID perangkat tidak bisa dihapus (walau tanpa lewat tampilan)', function () {
    devAddPhone('6282133186715');

    devAsUser()->delete(route('whatsapp.numbers.destroy', $this->device))
        ->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'tidak bisa dihapus'));

    expect(UserWhatsAppNumber::find($this->device->id))->not->toBeNull()
        ->and($this->device->fresh()->is_primary)->toBeTrue()
        ->and($this->user->fresh()->whatsapp_number)->toBe(DEV_LID);
});

it('nomor ID perangkat tidak bisa diubah, tapi nama tetap boleh', function () {
    devAsUser()->put(route('whatsapp.numbers.update', $this->device), [
        'whatsapp_number' => '6289689511785', 'name' => 'Diubah', 'is_primary' => true,
    ])->assertRedirect()->assertSessionHas('error', fn ($m) => str_contains($m, 'ID perangkat WhatsApp tidak bisa diubah'));

    expect($this->device->fresh()->whatsapp_number)->toBe(DEV_LID)
        ->and($this->device->fresh()->name)->toBe('Primary Number');

    devAsUser()->put(route('whatsapp.numbers.update', $this->device), [
        'whatsapp_number' => DEV_LID, 'name' => 'HP Juanito', 'is_primary' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($this->device->fresh()->whatsapp_number)->toBe(DEV_LID)   // tanpa awalan 62
        ->and($this->device->fresh()->name)->toBe('HP Juanito');
});

it('ID perangkat tidak menghabiskan slot nomor paket', function () {
    $svc = new SubscriptionLimitService;
    expect($svc->getCurrentWhatsAppNumberCount($this->user->id, $this->tenant->id))->toBe(0);

    devAddPhone('6282133186715');
    expect($svc->getCurrentWhatsAppNumberCount($this->user->id, $this->tenant->id))->toBe(1);
});

it('hapus nomor utama HP → yang jadi utama nomor HP lain, bukan ID perangkat', function () {
    $this->device->update(['is_primary' => false]);
    $main = devAddPhone('6282133186715', true);
    $other = devAddPhone('6289689511785');

    devAsUser()->delete(route('whatsapp.numbers.destroy', $main))->assertRedirect();

    expect($other->fresh()->is_primary)->toBeTrue()
        ->and($this->device->fresh()->is_primary)->toBeFalse()
        ->and($this->user->fresh()->whatsapp_number)->toBe('6289689511785');
});
