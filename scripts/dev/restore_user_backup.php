<?php

/**
 * RESTORE USER FROM BACKUP - FIXED VERSION
 * Supports both old and new backup formats
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$backupFile = $argv[1] ?? null;

if (!$backupFile) {
    echo "Usage: php restore_user_backup.php <backup_file.json>\n";
    echo "Example: php restore_user_backup.php backup_user_id_12_2025-12-19_064358.json\n";
    exit;
}

if (!file_exists($backupFile)) {
    echo "❌ Backup file not found: {$backupFile}\n";
    exit;
}

echo "=== RESTORE USER FROM BACKUP ===\n\n";
echo "Backup file: {$backupFile}\n\n";

// Load backup
$backup = json_decode(file_get_contents($backupFile), true);

if (!$backup) {
    echo "❌ Invalid backup file\n";
    exit;
}

echo "Backup date: {$backup['backup_date']}\n";
if (isset($backup['phone_number'])) {
    echo "Phone number: {$backup['phone_number']}\n";
}
if (isset($backup['user_id'])) {
    echo "User ID: {$backup['user_id']}\n";
}
echo "\n";

// Detect format
$isNewFormat = isset($backup['user']);

// Summary
echo "=== BACKUP CONTAINS ===\n";
if ($isNewFormat) {
    echo "User: {$backup['user']['email']}\n";
    echo "Tenant: " . ($backup['tenant']['name'] ?? 'N/A') . "\n";
} else {
    echo "Users: " . count($backup['users'] ?? []) . "\n";
    echo "Tenants: " . count($backup['tenants'] ?? []) . "\n";
}
echo "WhatsApp Mappings: " . count($backup['whatsapp_mappings']) . "\n";
echo "Transactions: " . count($backup['transactions']) . "\n";
echo "Subscriptions: " . count($backup['subscriptions'] ?? []) . "\n";
if (isset($backup['categories'])) {
    echo "Categories: " . count($backup['categories']) . "\n";
}
if (isset($backup['balances'])) {
    echo "Balances: " . count($backup['balances']) . "\n";
}
echo "User-Tenant Pivots: " . count($backup['user_tenants'] ?? []) . "\n\n";

echo "⚠️  WARNING: This will restore all data from backup!\n";
echo "Continue? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if (strtolower($line) !== 'yes') {
    echo "❌ Cancelled\n";
    exit;
}

echo "\nRestoring...\n\n";

// Helper function to convert datetime format
function convertDatetime($value) {
    if ($value === null) return null;
    if (is_string($value) && strpos($value, 'T') !== false) {
        // Convert ISO 8601 to MySQL datetime
        return date('Y-m-d H:i:s', strtotime($value));
    }
    return $value;
}

// Helper function to fix datetime fields in array
function fixDatetimeFields($data, $fields) {
    foreach ($fields as $field) {
        if (isset($data[$field])) {
            $data[$field] = convertDatetime($data[$field]);
        }
    }
    return $data;
}

DB::beginTransaction();

try {
    // Prepare data arrays
    $tenantsToRestore = [];
    $usersToRestore = [];
    
    if ($isNewFormat) {
        if ($backup['tenant']) {
            $tenantsToRestore[] = $backup['tenant'];
        }
        $usersToRestore[] = $backup['user'];
    } else {
        $tenantsToRestore = $backup['tenants'] ?? [];
        $usersToRestore = $backup['users'] ?? [];
    }
    
    // 1. Restore tenants
    foreach ($tenantsToRestore as $tenant) {
        $exists = DB::table('tenants')->where('id', $tenant['id'])->exists();
        
        if (!$exists) {
            DB::table('tenants')->insert($tenant);
            echo "✅ Restored tenant: {$tenant['name']}\n";
        } else {
            echo "⏭️  Tenant already exists: {$tenant['name']}\n";
        }
    }
    
    // 2. Restore users
    foreach ($usersToRestore as $user) {
        $exists = DB::table('users')->where('id', $user['id'])->exists();
        
        if (!$exists) {
            // Fix datetime fields
            $user = fixDatetimeFields($user, ['email_verified_at', 'two_factor_confirmed_at', 'created_at', 'updated_at']);
            
            // Add password if not exists (for Google OAuth users)
            if (!isset($user['password']) || empty($user['password'])) {
                $user['password'] = bcrypt('temporary_password_' . time());
            }
            
            DB::table('users')->insert($user);
            echo "✅ Restored user: {$user['email']}\n";
        } else {
            echo "⏭️  User already exists: {$user['email']}\n";
        }
    }
    
    // 3. Restore categories (if exists)
    if (isset($backup['categories'])) {
        foreach ($backup['categories'] as $cat) {
            $exists = DB::table('categories')->where('id', $cat['id'])->exists();
            
            if (!$exists) {
                DB::table('categories')->insert($cat);
            }
        }
        echo "✅ Restored " . count($backup['categories']) . " categories\n";
    }
    
    // 4. Restore balances (if exists)
    if (isset($backup['balances'])) {
        foreach ($backup['balances'] as $balance) {
            $exists = DB::table('balances')->where('id', $balance['id'])->exists();
            
            if (!$exists) {
                DB::table('balances')->insert($balance);
            }
        }
        echo "✅ Restored " . count($backup['balances']) . " balances\n";
    }
    
    // 5. Restore WhatsApp mappings
    foreach ($backup['whatsapp_mappings'] as $mapping) {
        $exists = DB::table('user_whatsapp_numbers')->where('id', $mapping['id'])->exists();
        
        if (!$exists) {
            DB::table('user_whatsapp_numbers')->insert($mapping);
        }
    }
    echo "✅ Restored " . count($backup['whatsapp_mappings']) . " WhatsApp mappings\n";
    
    // 6. Restore subscriptions
    if (isset($backup['subscriptions'])) {
        foreach ($backup['subscriptions'] as $sub) {
            $exists = DB::table('subscriptions')->where('id', $sub['id'])->exists();
            
            if (!$exists) {
                DB::table('subscriptions')->insert($sub);
            }
        }
        echo "✅ Restored " . count($backup['subscriptions']) . " subscriptions\n";
    }
    
    // 7. Restore user_tenants pivot
    if (isset($backup['user_tenants'])) {
        foreach ($backup['user_tenants'] as $pivot) {
            $exists = DB::table('user_tenants')
                ->where('user_id', $pivot['user_id'])
                ->where('tenant_id', $pivot['tenant_id'])
                ->exists();
            
            if (!$exists) {
                DB::table('user_tenants')->insert($pivot);
            }
        }
        echo "✅ Restored " . count($backup['user_tenants']) . " user-tenant pivots\n";
    }
    
    // 8. Restore transactions
    foreach ($backup['transactions'] as $txn) {
        $exists = DB::table('transactions')->where('id', $txn['id'])->exists();
        
        if (!$exists) {
            DB::table('transactions')->insert($txn);
        }
    }
    echo "✅ Restored " . count($backup['transactions']) . " transactions\n";
    
    DB::commit();
    
    echo "\n=== SUCCESS ===\n";
    echo "User data has been restored from backup!\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Restore failed, all changes rolled back\n";
}
