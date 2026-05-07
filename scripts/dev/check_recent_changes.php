<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Subscription;
use App\Models\Tenant;

echo "=== LATEST UPDATED TENANTS ===\n";
$tenants = Tenant::orderBy('updated_at', 'desc')->limit(5)->get();
foreach ($tenants as $tenant) {
    echo "ID: {$tenant->id} | Name: {$tenant->name} | Trial Ends: {$tenant->trial_ends_at} | Active: ".($tenant->is_active ? 'YES' : 'NO')." | Updated: {$tenant->updated_at}\n";
}

echo "\n=== LATEST UPDATED SUBSCRIPTIONS ===\n";
$subs = Subscription::orderBy('updated_at', 'desc')->limit(5)->get();
foreach ($subs as $sub) {
    echo "ID: {$sub->id} | Tenant ID: {$sub->tenant_id} | Plan: {$sub->plan} | Status: {$sub->status} | Ends: {$sub->ends_at} | Updated: {$sub->updated_at}\n";
}
