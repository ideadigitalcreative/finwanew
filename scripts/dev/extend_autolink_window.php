<?php

// Extend AUTO-LINK window from 1 hour to 24 hours
$file = 'app/Jobs/ProcessIncomingMessage.php';
$content = file_get_contents($file);

// Change subHour() to subDay()
$search = "                            // Find the most recent user registered in last hour\r\n                            \$recentUser = \\App\\Models\\User::where('created_at', '>=', now()->subHour())";

$replace = "                            // Find the most recent user registered in last 24 hours\r\n                            \$recentUser = \\App\\Models\\User::where('created_at', '>=', now()->subDay())";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: ProcessIncomingMessage.php\n";
echo "AUTO-LINK window extended from 1 hour to 24 hours\n";
echo "User baru sekarang punya waktu 24 jam untuk kirim pesan pertama\n";
