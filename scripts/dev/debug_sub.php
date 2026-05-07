<?php

use App\Models\Subscription;
use App\Models\Tenant;

$targetName = 'Idea DigitalCreative'; // Keyword umum

echo "\n--- DEBUG SUBSCRIPTION: $targetName ---\n";

$tenants = Tenant::where('name', 'like', "%{$targetName}%")->get();

if ($tenants->isEmpty()) {
    echo "❌ No tenant found matching '$targetName'\n";
}

foreach ($tenants as $tenant) {
    echo "\n🏢 Tenant: {$tenant->name} (ID: {$tenant->id}, Active: {$tenant->is_active})\n";

    $subs = Subscription::where('tenant_id', $tenant->id)->get();

    if ($subs->isEmpty()) {
        echo "   (No Subscriptions found)\n";
    }

    foreach ($subs as $sub) {
        echo "   📄 Sub ID: {$sub->id}\n";
        echo "      Plan Raw: '{$sub->plan}'\n";
        echo "      Status: {$sub->status}\n";
        echo '      Starts: '.($sub->starts_at?->format('Y-m-d') ?? 'N/A')."\n";
        echo '      Ends:   '.($sub->ends_at?->format('Y-m-d') ?? 'N/A')."\n";
        echo "      Created: {$sub->created_at}\n";
        echo "      ------------------------\n";
    }
}
