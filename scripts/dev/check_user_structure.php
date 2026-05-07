<?php

/**
 * CHECK USER TABLE STRUCTURE
 * See what columns exist in users table
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== CHECKING USER TABLE STRUCTURE ===\n\n";

// Get users table columns
$columns = DB::select("SHOW COLUMNS FROM users");

echo "Users table columns:\n";
foreach ($columns as $column) {
    echo "  - {$column->Field} ({$column->Type})\n";
}

// Check for pivot table
echo "\n=== CHECKING PIVOT TABLES ===\n\n";

$tables = DB::select("SHOW TABLES");
$dbName = DB::getDatabaseName();

echo "Looking for tenant-user pivot tables:\n";
foreach ($tables as $table) {
    $tableName = $table->{"Tables_in_{$dbName}"};
    if (stripos($tableName, 'tenant') !== false && stripos($tableName, 'user') !== false) {
        echo "  ✅ Found: {$tableName}\n";
        
        // Show structure
        $pivotColumns = DB::select("SHOW COLUMNS FROM {$tableName}");
        echo "     Columns:\n";
        foreach ($pivotColumns as $col) {
            echo "       - {$col->Field} ({$col->Type})\n";
        }
        echo "\n";
    }
}

// Check user model relationships
echo "=== CHECKING USER RELATIONSHIPS ===\n\n";

$user = \App\Models\User::find(115);
if ($user) {
    echo "User: {$user->email}\n";
    echo "Tenant ID: {$user->tenant_id}\n\n";
    
    // Try to get tenants relationship
    try {
        $tenants = $user->tenants;
        echo "Tenants relationship exists: YES\n";
        echo "Number of tenants: {$tenants->count()}\n";
        
        if ($tenants->count() > 0) {
            foreach ($tenants as $tenant) {
                echo "\n  Tenant: {$tenant->name}\n";
                echo "  Pivot data: " . json_encode($tenant->pivot->getAttributes()) . "\n";
            }
        }
    } catch (\Exception $e) {
        echo "Tenants relationship: " . $e->getMessage() . "\n";
    }
}
