<?php

/**
 * CHECK SUBSCRIPTION ENUM VALUES
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== CHECKING SUBSCRIPTION TABLE STRUCTURE ===\n\n";

// Get table structure
$columns = DB::select("SHOW COLUMNS FROM subscriptions WHERE Field = 'plan'");

if (! empty($columns)) {
    $column = $columns[0];
    echo "Column: {$column->Field}\n";
    echo "Type: {$column->Type}\n";
    echo "Null: {$column->Null}\n";
    echo "Default: {$column->Default}\n\n";

    // Extract ENUM values
    if (preg_match("/^enum\((.+)\)$/", $column->Type, $matches)) {
        $values = str_getcsv($matches[1], ',', "'");
        echo "Valid ENUM values:\n";
        foreach ($values as $value) {
            echo "  - '{$value}'\n";
        }
    }
} else {
    echo "Column 'plan' not found\n";
}

// Check existing subscriptions
echo "\nEXISTING SUBSCRIPTION PLANS:\n";
$plans = DB::table('subscriptions')
    ->select('plan', DB::raw('COUNT(*) as count'))
    ->groupBy('plan')
    ->get();

foreach ($plans as $plan) {
    echo "  - '{$plan->plan}' ({$plan->count} subscriptions)\n";
}
