<?php

use App\Services\WhatsAppWebhookService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

// Mock dependencies if needed, or just run the service
// We need to ensure the service lookup for Tenant/Channel works enough to reach our logic.
// We'll trust the DB has the 'channel' for the session we provide.

// 1. Get a valid channel to simulate a session
$channel = \App\Models\Channel::where('type', 'whatsapp')->where('is_active', true)->first();
if (! $channel) {
    echo "❌ No active channel found to test with.\n";
    exit;
}

$sessionId = "wa_{$channel->tenant_id}_{$channel->channel_account}";
echo "🔧 Using Session ID: $sessionId\n";

// 2. The input that was failing
$senderId = '6285604676142@c.us'; // The reported number
$messageBody = 'Halo kak, saya mau daftar FinWa. Ketik Daftar untuk mulai ya!';

echo "🧪 Testing Message: '$messageBody'\n";

// Clear cache to ensure clean state
Cache::forget('wa_reg_flow:'.'6285604676142');
Cache::forget('wa_reg_data:'.'6285604676142');

$payload = [
    'from' => $senderId,
    'id' => 'TEST_MSG_ID_'.time(),
    'type' => 'chat',
    'body' => $messageBody,
    'timestamp' => time(),
    // Simulate what might be happening with LID if relevant, but let's try standard first
    'originalFrom' => $senderId,
];

$service = new WhatsAppWebhookService;

// We need to capture the output or result
// The service returns an array
echo "🚀 calling processIncomingMessage...\n";

try {
    $result = $service->processIncomingMessage($payload, $sessionId);

    echo "\n📊 RESULT:\n";
    print_r($result);

    if (isset($result['handled']) && $result['handled'] === 'registration_start') {
        echo "\n✅ SUCCESS! Registration flow triggered.\n";
    } else {
        echo "\n❌ FAILED. Did not trigger registration flow.\n";
        if (isset($result['error'])) {
            echo 'Error: '.$result['error']."\n";
        }
    }

} catch (\Exception $e) {
    echo "\n💥 EXCEPTION: ".$e->getMessage()."\n";
    echo $e->getTraceAsString();
}

// 3. Test with JUST "daftar" (Control Group)
echo "\n--------------------------------\n";
echo "🧪 Control Test: 'daftar'\n";
$payload['body'] = 'daftar';
$result = $service->processIncomingMessage($payload, $sessionId);
if (isset($result['handled']) && $result['handled'] === 'registration_start') {
    echo "✅ Control Test Passed.\n";
} else {
    echo "❌ Control Test Failed.\n";
}
