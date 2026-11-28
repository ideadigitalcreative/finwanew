<?php

/**
 * Filter "Oleh" di /transactions menampilkan anggota dobel: alias LID
 * ("LID - 6285…") muncul di samping nomor aslinya, padahal halaman WhatsApp
 * menyembunyikannya. LID harus digabung ke nomor aslinya.
 */

use App\Models\Category;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserTenant;
use App\Models\UserWhatsAppNumber;
use App\Services\Category\CategoryManagerService;

function lidWorld(): array
{
    $tenant = Tenant::create(['name' => 'Lid', 'slug' => 'lid', 'is_active' => true]);
    $user = User::create([
        'name' => 'Haerul', 'email' => 'lid@example.com', 'password' => bcrypt('x'),
        'whatsapp_number' => '6285242766676', 'tenant_id' => $tenant->id,
    ]);
    $num = fn (array $a) => UserWhatsAppNumber::create($a + ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'is_active' => true, 'is_primary' => false, 'is_lid' => false]);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'haerul' => $num(['whatsapp_number' => '6285242766676', 'name' => 'Haerul', 'is_primary' => true]),
        'meli' => $num(['whatsapp_number' => '6281234567890', 'name' => 'Meli']),
        'lidByName' => $num(['whatsapp_number' => '22248232636471', 'name' => 'LID - 6285242766676', 'is_lid' => true]),
        'lidByMapping' => $num(['whatsapp_number' => '99911122233344', 'name' => 'Hadi', 'is_lid' => true]),
        'lidOrphan' => $num(['whatsapp_number' => '55566677788899', 'name' => 'LID - Device Link', 'is_lid' => true]),
    ];
}

it('memetakan setiap LID ke nomor aslinya', function () {
    $w = lidWorld();
    UserLidMapping::create([
        'user_id' => $w['user']->id, 'tenant_id' => $w['tenant']->id,
        'lid' => '99911122233344', 'phone_number' => '6281234567890', 'verified' => true,
    ]);

    $map = UserWhatsAppNumber::canonicalIdMap($w['tenant']->id);

    expect($map[$w['haerul']->id])->toBe($w['haerul']->id)
        ->and($map[$w['meli']->id])->toBe($w['meli']->id)
        ->and($map[$w['lidByName']->id])->toBe($w['haerul']->id)      // dari nama "LID - 62…"
        ->and($map[$w['lidByMapping']->id])->toBe($w['meli']->id)     // dari user_lid_mappings
        ->and($map[$w['lidOrphan']->id])->toBe($w['haerul']->id);     // fallback: nomor utama user
});

it('filter "Oleh" tanpa LID & memilih nomor asli ikut menampilkan transaksi dari LID-nya', function () {
    $w = lidWorld();
    Subscription::create(['tenant_id' => $w['tenant']->id, 'plan' => 'growth', 'duration_months' => 1, 'price' => 0, 'status' => 'active']);
    UserTenant::create(['user_id' => $w['user']->id, 'tenant_id' => $w['tenant']->id, 'is_active' => true, 'joined_at' => now()]);
    (new CategoryManagerService)->createCategoriesForTenant($w['tenant']->id);
    $categoryId = Category::where('tenant_id', $w['tenant']->id)->where('type', 'like', 'pengeluaran%')->value('id');
    $tx = fn ($numberId, $desc) => Transaction::create([
        'category_id' => $categoryId,
        'tenant_id' => $w['tenant']->id, 'type' => 'expense', 'amount' => 10000, 'description' => $desc,
        'transaction_date' => now(), 'status' => 'confirmed', 'user_whatsapp_number_id' => $numberId,
    ]);
    $tx($w['haerul']->id, 'dari nomor asli');
    $tx($w['lidByName']->id, 'dari lid');
    $tx($w['meli']->id, 'dari meli');

    $this->actingAs($w['user'])
        ->get('/transactions?number_id='.$w['haerul']->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('whatsappNumbers', fn ($n) => collect($n)->pluck('name')->all() === ['Haerul', 'Meli'])
            ->where('transactions.data', fn ($d) => collect($d)->pluck('description')->sort()->values()->all() === ['dari lid', 'dari nomor asli'])
        );
});
