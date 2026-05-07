<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SavingsGoal;

// Find tenant ID first (getting from first goal)
$firstGoal = SavingsGoal::first();
if (! $firstGoal) {
    echo "No goals found in DB.\n";
    exit;
}

$tenantId = $firstGoal->tenant_id;
echo "Looking up goals for tenant: $tenantId\n";

$targetName = 'nikah';
$goals = SavingsGoal::where('tenant_id', $tenantId)
    ->where('status', 'active')
    ->where('name', 'like', '%'.$targetName.'%')
    ->get();

echo 'Found '.$goals->count()." goals matching '$targetName'\n";
foreach ($goals as $goal) {
    echo "- ID: {$goal->id}, Name: {$goal->name}, Status: {$goal->status}\n";
}

// Test regex
$messageText = 'Nabung 1jt buat nikah';
if (preg_match('/(?:tabung|nabung)\s+[\d\.,]+\s*(?:rb|ribu|k|jt|juta)?\s+(?:untuk|buat)\s+(.+)/i', $messageText, $matches)) {
    echo 'Regex matches! targetName: '.trim($matches[1])."\n";
} else {
    echo "Regex failed!\n";
}
