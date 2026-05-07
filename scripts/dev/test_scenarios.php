<?php

use App\Services\WhatsAppWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Helpers for Output
function printResult($name, $result, $expectedType)
{
    echo "🔍 TEST: $name\n";
    if (isset($result['handled']) && $result['handled'] === $expectedType) {
        echo "   ✅ SUCCESS: Handled as '$expectedType'.\n";
    } elseif (isset($result['rejected']) && $result['rejected'] === true) {
        if ($expectedType === 'rejected_unregistered' && str_contains($result['error'], 'not registered')) {
            echo "   ✅ SUCCESS: Rejected as Unregistered.\n";
        } elseif ($expectedType === 'rejected_expired' && str_contains($result['error'], 'expired')) {
            echo "   ✅ SUCCESS: Rejected as Expired.\n";
        } else {
            echo "   ❌ FAILED: Rejected with error '{$result['error']}'. Expected '$expectedType'.\n";
        }
    } else {
        echo "   ❌ FAILED: Unexpected result.\n";
        print_r($result);
    }
    echo "\n";
}

// Setup
$senderId = '628999999999@c.us'; // Random unregistered number
$sessionId = 'wa_1_6285762000079'; // Using the session from previous log (Tenant 1)

$service = new WhatsAppWebhookService;

echo "🛠️ STARTING SCENARIO TESTS 🛠️\n\n";

// --- Scenario 1: Unregistered User via Link (Contains 'Daftar') ---
echo "--- Scenario 1: Registration Intent via Link ---\n";
Cache::forget("wa_reg_flow:$senderId"); // Ensure clean state
$msg1 = [
    'from' => $senderId,
    'id' => 'TEST_1',
    'type' => 'chat',
    'body' => 'Halo kak, saya mau daftar FinWa. Ketik Daftar untuk mulai ya!', // Keyword 'daftar' present
    'timestamp' => time(),
];
$res1 = $service->processIncomingMessage($msg1, $sessionId);
printResult('Registration Link Click', $res1, 'registration_start');

// --- Scenario 2: Unregistered User saying "Daftar" ---
echo "--- Scenario 2: Simple 'Daftar' ---\n";
Cache::forget("wa_reg_flow:$senderId");
$msg2 = [
    'from' => $senderId,
    'id' => 'TEST_2',
    'type' => 'chat',
    'body' => 'daftar',
    'timestamp' => time(),
];
$res2 = $service->processIncomingMessage($msg2, $sessionId);
printResult("Keyword 'daftar'", $res2, 'registration_start');

// --- Scenario 3: Unregistered User saying "Halo" (No Intent) ---
echo "--- Scenario 3: Unregistered Random Message ---\n";
// Ensure cache doesn't look like we are in flow
Cache::forget("wa_reg_flow:$senderId");
// Also clear warning cache so we get the log message
Cache::forget("unregistered_warning:$senderId");

$msg3 = [
    'from' => $senderId,
    'id' => 'TEST_3',
    'type' => 'chat',
    'body' => 'Halo, info harga dong', // No registration keyword
    'timestamp' => time(),
    // Simulate LID param to prove fallback is GONE
    'originalFrom' => '123456789@lid',
];
$res3 = $service->processIncomingMessage($msg3, $sessionId);

// This should result in "rejected" ("WhatsApp number not registered")
// Previously this would have resulted in "Expired" due to fallback
printResult('Random Message (LID format)', $res3, 'rejected_unregistered');

echo "🏁 TESTS COMPLETED.\n";
