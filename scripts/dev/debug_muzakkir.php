<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Message;

$tenantId = 10189;
$userId = 285;
$waNumber = '279752711606488';

echo "=== DEEP DEBUG TENANT $tenantId ===\n";
echo 'Server Time: '.now()->format('Y-m-d H:i:s T')."\n\n";

// 1. Check message content detail
echo "1️⃣ DETAILED MESSAGE CONTENT:\n";
$messages = Message::where('tenant_id', $tenantId)->orderBy('created_at', 'desc')->limit(5)->get();
foreach ($messages as $msg) {
    echo "   ---\n";
    echo "   Message ID (db): {$msg->id}\n";
    echo "   Message ID (wa): {$msg->message_id}\n";
    echo "   Status: {$msg->status}\n";
    echo "   Type: {$msg->type}\n";
    echo "   Channel: {$msg->channel}\n";
    echo "   Channel ID: {$msg->channel_id}\n";
    echo "   Sender ID: {$msg->sender_id}\n";
    echo "   Content (raw): '".$msg->content."'\n";
    echo '   Content length: '.strlen($msg->content ?? '')."\n";
    echo '   Metadata: '.json_encode($msg->metadata)."\n";
    echo '   Raw Data: '.json_encode($msg->raw_data)."\n";
    echo "   Created: {$msg->created_at}\n";
    echo "   ---\n";
}
echo "\n";

// 2. Check ALL messages (including from tenant 1) with this sender number
echo "2️⃣ ALL MESSAGES FROM SENDER $waNumber (any tenant):\n";
$allMsgs = Message::where('sender_id', 'LIKE', "%$waNumber%")
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
echo '   Found: '.$allMsgs->count()." messages\n";
foreach ($allMsgs as $msg) {
    $content = mb_substr($msg->content ?? '', 0, 80);
    echo "   Tenant: {$msg->tenant_id} | Status: {$msg->status} | Content: '{$content}' | Time: {$msg->created_at}\n";
}
echo "\n";

// 3. Check recent Laravel log for errors related to this tenant
echo "3️⃣ RECENT LARAVEL LOG ENTRIES (related to tenant $tenantId or sender):\n";
$logPath = storage_path('logs/laravel.log');
if (file_exists($logPath)) {
    $logSize = filesize($logPath);
    echo '   Log file size: '.round($logSize / 1024 / 1024, 2)." MB\n";

    // Read last 500 lines
    $lines = [];
    $fp = fopen($logPath, 'r');
    if ($fp) {
        // Seek to near end
        $seekPos = max(0, $logSize - 200000); // Last ~200KB
        fseek($fp, $seekPos);
        fgets($fp); // Skip partial line
        while (! feof($fp)) {
            $line = fgets($fp);
            if ($line !== false) {
                $lines[] = $line;
            }
        }
        fclose($fp);
    }

    $relevantLines = [];
    foreach ($lines as $i => $line) {
        if (stripos($line, (string) $tenantId) !== false ||
            stripos($line, $waNumber) !== false ||
            stripos($line, 'muzakkir') !== false ||
            stripos($line, '285') !== false) {
            // Grab this line and next 2 for context
            $relevantLines[] = trim($line);
            if (isset($lines[$i + 1])) {
                $relevantLines[] = '  '.trim($lines[$i + 1]);
            }
            if (isset($lines[$i + 2])) {
                $relevantLines[] = '  '.trim($lines[$i + 2]);
            }
            $relevantLines[] = '---';
        }
    }

    if (empty($relevantLines)) {
        echo "   ℹ️ No relevant recent log entries found\n";
    } else {
        echo '   Found '.count($relevantLines)." relevant lines:\n";
        // Show last 50 relevant lines
        $show = array_slice($relevantLines, -50);
        foreach ($show as $rl) {
            echo "   $rl\n";
        }
    }
} else {
    echo "   ❌ Log file not found at: $logPath\n";
}
echo "\n";

// 4. Check if MessageReplyService can resolve channel for reply
echo "4️⃣ REPLY CHANNEL CHECK:\n";
$channel = \App\Models\Channel::where('tenant_id', 1) // Shared channel (admin tenant)
    ->where('type', 'whatsapp')
    ->where('is_active', true)
    ->first();
if ($channel) {
    echo "   Shared Channel: ID={$channel->id} | Account={$channel->channel_account} | Active={$channel->is_active}\n";
    $sessionId = $channel->config['session_id'] ?? "wa_{$channel->tenant_id}_{$channel->channel_account}";
    echo "   Session ID: $sessionId\n";
} else {
    echo "   ❌ No active shared WhatsApp channel found!\n";
}

// Also check if there's a channel for this specific tenant
$tenantChannel = \App\Models\Channel::where('tenant_id', $tenantId)
    ->where('type', 'whatsapp')
    ->where('is_active', true)
    ->first();
if ($tenantChannel) {
    echo "   Tenant Channel: ID={$tenantChannel->id} | Account={$tenantChannel->channel_account}\n";
} else {
    echo "   ℹ️ No dedicated channel for tenant $tenantId (uses shared channel)\n";
}
echo "\n";

// 5. Check queue worker
echo "5️⃣ QUEUE STATUS:\n";
$pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')->count();
echo "   Pending jobs: $pendingJobs\n";

// Check if there are any recent processed jobs (job_batches if exists)
try {
    $recentJobs = \Illuminate\Support\Facades\DB::table('jobs')
        ->orderBy('created_at', 'desc')
        ->limit(3)
        ->get();
    if ($recentJobs->isNotEmpty()) {
        echo "   Recent pending jobs:\n";
        foreach ($recentJobs as $job) {
            $payload = json_decode($job->payload, true);
            $class = $payload['displayName'] ?? 'Unknown';
            echo "   - Queue: {$job->queue} | Class: {$class} | Attempts: {$job->attempts} | Created: ".date('Y-m-d H:i:s', $job->created_at)."\n";
        }
    }
} catch (\Exception $e) {
    echo '   Error checking jobs: '.$e->getMessage()."\n";
}

echo "\n=== END DEEP DEBUG ===\n";
