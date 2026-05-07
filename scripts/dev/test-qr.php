<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\SuperAdmin\WhatsAppController;
use App\Models\Channel;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

echo "🔍 Testing QR Code Fetching...\n";

// 1. Find Channel
$channelId = 65;
$channel = Channel::find($channelId);

if (! $channel) {
    echo "❌ Channel ID $channelId not found!\n";
    exit(1);
}

echo "✅ Channel found: {$channel->name} (Session ID: ".($channel->config['session_id'] ?? 'NULL').")\n";

// 2. Setup Controller
$service = app(WhatsAppService::class);
$controller = new WhatsAppController($service);
$request = Request::create("/superadmin/whatsapp/{$channelId}/qr", 'GET');

// 3. Call getQrCode
try {
    echo "⏳ Calling getQrCode()...\n";
    $response = $controller->getQrCode($request, $channel);

    echo '📊 Status Code: '.$response->getStatusCode()."\n";

    $content = json_decode($response->getContent(), true);

    if ($response->getStatusCode() == 200) {
        echo "✅ Success!\n";
        if (isset($content['data']['qr'])) {
            $qrLength = strlen($content['data']['qr']);
            echo "📝 QR Code Data Length: $qrLength chars\n";
            echo '🖼️  QR Code Preview: '.substr($content['data']['qr'], 0, 50)."...\n";
        } else {
            echo "⚠️  QR Code key not found in data\n";
            print_r($content);
        }
    } else {
        echo "❌ Failed!\n";
        echo 'Error: '.($content['error'] ?? 'Unknown error')."\n";
        print_r($content);
    }

} catch (\Exception $e) {
    echo '❌ Exception: '.$e->getMessage()."\n";
    echo $e->getTraceAsString();
}
