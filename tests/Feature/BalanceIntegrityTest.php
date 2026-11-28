<?php

/**
 * Prioritas 2 — integritas saldo & isolasi tenant.
 */

use App\Models\Balance;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function makeTenantWithWallet(string $slug, float $balance = 100000): array
{
    $tenant = Tenant::create(['name' => strtoupper($slug), 'slug' => $slug, 'is_active' => true]);
    Subscription::create([
        'tenant_id' => $tenant->id,
        'plan' => 'growth',
        'duration_months' => 1,
        'price' => 0,
        'status' => 'active',
    ]);
    $wallet = Balance::create([
        'tenant_id' => $tenant->id,
        'account_name' => "Dompet {$slug}",
        'account_type' => 'cash',
        'currency' => 'IDR',
        'balance' => $balance,
        'balance_date' => now()->toDateString(),
        'is_active' => true,
        'is_default' => true,
    ]);

    return [$tenant, $wallet];
}

// ── adjustBalance: atomik, tidak kehilangan update ──

it('dua proses bersamaan tidak saling menimpa saldo', function () {
    [, $wallet] = makeTenantWithWallet('a');

    // Dua worker memuat dompet yang sama SEBELUM salah satunya menyimpan
    // (skenario lost update pada pola lama `balance += x; save()`).
    $workerA = Balance::find($wallet->id);
    $workerB = Balance::find($wallet->id);

    $workerA->adjustBalance(-25000);
    $workerB->adjustBalance(-10000);

    expect((float) $wallet->fresh()->balance)->toBe(65000.0);
});

it('save() setelah adjustBalance tidak menimpa saldo terbaru', function () {
    [, $wallet] = makeTenantWithWallet('b');

    $stale = Balance::find($wallet->id);
    Balance::find($wallet->id)->adjustBalance(50000); // proses lain menambah saldo

    $stale->adjustBalance(-20000);
    $stale->balance_date = now()->addDay()->toDateString();
    $stale->save();

    expect((float) $wallet->fresh()->balance)->toBe(130000.0)
        ->and((float) $stale->balance)->toBe(130000.0);
});

it('adjustBalance nol tidak melakukan apa-apa', function () {
    [, $wallet] = makeTenantWithWallet('c');
    $wallet->adjustBalance(0);

    expect((float) $wallet->fresh()->balance)->toBe(100000.0);
});

// ── Isolasi tenant di API user login ──

it('user login tidak bisa membaca dompet tenant lain lewat tenant_id', function () {
    [$tenantA] = makeTenantWithWallet('mine');
    [$tenantB] = makeTenantWithWallet('other');

    $user = User::factory()->create(['tenant_id' => $tenantA->id]);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/auth/balances?tenant_id='.$tenantB->id)->assertOk();

    $names = collect($response->json('data'))->pluck('account_name')->all();
    expect($names)->toBe(['Dompet mine']);
});

it('API transaksi menolak balance_id milik tenant lain', function () {
    [$tenantA] = makeTenantWithWallet('mine2');
    [, $walletB] = makeTenantWithWallet('other2');

    $user = User::factory()->create(['tenant_id' => $tenantA->id]);
    Sanctum::actingAs($user);

    (new \App\Services\Category\CategoryManagerService)->createCategoriesForTenant($tenantA->id);
    $category = \App\Models\Category::where('tenant_id', $tenantA->id)->firstOrFail();

    $this->postJson('/api/auth/transactions', [
        'amount' => 5000,
        'type' => 'expense',
        'category_id' => $category->id,
        'balance_id' => $walletB->id,
        'transaction_date' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors('balance_id');

    expect((float) $walletB->fresh()->balance)->toBe(100000.0);
});

it('is_super_admin tidak bisa diisi lewat mass assignment', function () {
    $user = User::factory()->create();
    $user->fill(['is_super_admin' => true])->save();

    expect((bool) $user->fresh()->is_super_admin)->toBeFalse();
});
