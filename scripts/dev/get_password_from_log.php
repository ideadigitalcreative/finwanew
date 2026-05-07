<?php

/**
 * GET PASSWORD FROM LOG
 * Extract password for registered user from log
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$email = $argv[1] ?? 'Rafada32@gmail.com';

echo "=== SEARCHING PASSWORD FOR: {$email} ===\n\n";

// Check if user exists
$user = \App\Models\User::where('email', $email)->first();

if (!$user) {
    echo "❌ User not found with email: {$email}\n";
    exit;
}

echo "✅ User found:\n";
echo "  ID: {$user->id}\n";
echo "  Name: {$user->name}\n";
echo "  Email: {$user->email}\n";
echo "  Created: {$user->created_at}\n\n";

// Search log for password
$logFile = storage_path('logs/laravel.log');
if (!file_exists($logFile)) {
    echo "❌ Log file not found\n";
    exit;
}

echo "Searching log file for password...\n";

$lines = file($logFile);
$found = false;

// Search from bottom (most recent)
for ($i = count($lines) - 1; $i >= 0; $i--) {
    $line = $lines[$i];
    
    // Look for registration success with this email
    if (stripos($line, 'Registration Success') !== false && stripos($line, $email) !== false) {
        // Try to extract password from next few lines
        for ($j = $i; $j < min($i + 10, count($lines)); $j++) {
            if (preg_match('/"password":"([^"]+)"/', $lines[$j], $matches)) {
                echo "\n✅ PASSWORD FOUND: {$matches[1]}\n\n";
                echo "Send this to user:\n";
                echo "---\n";
                echo "🎉 Akun Anda sudah aktif!\n\n";
                echo "📧 Email: {$email}\n";
                echo "🔑 Password: {$matches[1]}\n\n";
                echo "🌐 Login di: https://finwa.web.id\n";
                echo "✨ Trial 30 hari aktif!\n";
                echo "---\n";
                $found = true;
                break 2;
            }
        }
    }
}

if (!$found) {
    echo "\n❌ Password not found in log\n";
    echo "\nOptions:\n";
    echo "1. Reset password via 'Lupa Password' di website\n";
    echo "2. Or run: php artisan tinker\n";
    echo "   Then: \$user = User::find({$user->id});\n";
    echo "         \$user->password = Hash::make('newpassword123');\n";
    echo "         \$user->save();\n";
}
