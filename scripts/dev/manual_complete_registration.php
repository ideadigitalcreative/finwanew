<?php

/**
 * MANUAL COMPLETE REGISTRATION
 * Complete registration for stuck user
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWhatsAppNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

echo "=== MANUAL COMPLETE REGISTRATION ===\n\n";

$lid = '6285762000079'; // LID Rafada
$name = 'Rafada';
$email = 'Rafada32@gmail.com';

// Check if email already exists
if (User::where('email', $email)->exists()) {
    echo "❌ Email already exists in database!\n";
    $user = User::where('email', $email)->first();
    echo "User ID: {$user->id}\n";
    echo "User Name: {$user->name}\n";
    echo "Email: {$user->email}\n";
    exit;
}

echo "Creating account for:\n";
echo "  Name: {$name}\n";
echo "  Email: {$email}\n";
echo "  LID: {$lid}\n\n";

try {
    // Generate password
    $password = strtoupper(Str::random(3)).rand(100, 999);
    echo "Generated Password: {$password}\n\n";

    // Generate slug
    $slug = Str::slug($name).'-'.Str::random(6);
    echo "Generated Slug: {$slug}\n\n";

    // Create tenant
    $tenant = Tenant::create([
        'name' => $name."'s Business",
        'slug' => $slug,
        'is_active' => true,
        'trial_ends_at' => Carbon::now()->addDays(30),
    ]);
    echo "✅ Tenant created (ID: {$tenant->id})\n";

    // Create user
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
        'whatsapp_number' => $lid,
        'tenant_id' => $tenant->id,
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    echo "✅ User created (ID: {$user->id})\n";

    // Create WhatsApp number mapping for LID
    UserWhatsAppNumber::create([
        'user_id' => $user->id,
        'tenant_id' => $tenant->id,
        'whatsapp_number' => $lid,
        'name' => 'LID - Registered',
        'is_primary' => false,
        'is_active' => true,
        'is_lid' => true,
    ]);
    echo "✅ WhatsApp LID mapping created\n";

    // Create trial subscription
    Subscription::create([
        'tenant_id' => $tenant->id,
        'plan' => 'trial',
        'duration_months' => 1,
        'price' => 0,
        'status' => 'active',
        'starts_at' => Carbon::now(),
        'ends_at' => Carbon::now()->addDays(30),
        'payment_provider' => 'free_trial',
        'metadata' => [
            'registered_via' => 'whatsapp_manual',
            'registered_at' => Carbon::now()->toIso8601String(),
        ],
    ]);
    echo "✅ Trial subscription created\n\n";

    // Clear registration flow
    RegHelper::clearFlow($lid);
    echo "✅ Registration flow cleared\n\n";

    echo "=== SUCCESS! ===\n\n";
    echo "Account Details:\n";
    echo "  📧 Email: {$email}\n";
    echo "  🔑 Password: {$password}\n";
    echo "  🌐 Login: https://finwa.web.id\n";
    echo "  ✨ Trial: 30 days\n\n";

    echo "Send this message to user via WhatsApp:\n";
    echo "---\n";
    echo "🎉 *Akun Berhasil Dibuat!*\n\n";
    echo "📧 Email: *{$email}*\n";
    echo "🔑 Password: *{$password}*\n\n";
    echo "🌐 Login di: https://finwa.web.id\n\n";
    echo "✨ Trial 30 hari sudah aktif!\n\n";
    echo "Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:\n";
    echo "• _beli makan 25rb_\n";
    echo "• _terima gaji 5jt_\n\n";
    echo "Selamat mencoba! 🚀\n";
    echo "---\n";

} catch (\Exception $e) {
    echo '❌ ERROR: '.$e->getMessage()."\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString()."\n";
}
