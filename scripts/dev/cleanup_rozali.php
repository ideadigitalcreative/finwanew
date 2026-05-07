<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;

$rozaliId = 412;
$haerulId = 411;

echo "--- Cleaning up Muhammad Rozali (412) ---\n";
$lidsToDelete = [
    '150547294384288',
    '205076199153829',
];

foreach ($lidsToDelete as $lid) {
    echo "Processing LID: $lid\n";

    // Delete from UserWhatsAppNumber
    $deletedUwn = UserWhatsAppNumber::where('user_id', $rozaliId)
        ->where('whatsapp_number', $lid)
        ->delete();
    echo "Deleted $deletedUwn entries from UserWhatsAppNumber for Rozali.\n";

    // Delete from UserLidMapping
    $deletedMap = UserLidMapping::where('user_id', $rozaliId)
        ->where('lid', $lid)
        ->delete();
    echo "Deleted $deletedMap entries from UserLidMapping for Rozali.\n";
}

echo "\n--- Done cleaning. User 412 is now 'clean' of These LIDs. ---\n";
