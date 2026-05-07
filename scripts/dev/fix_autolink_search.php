<?php

// Fix AUTO-LINK to search in user_whatsapp_numbers table
$file = 'app/Jobs/ProcessIncomingMessage.php';
$content = file_get_contents($file);

$search = "                            // Find the most recent user registered in last hour\r\n                            \$recentUser = \\App\\Models\\User::where('created_at', '>=', now()->subDay())\r\n                                ->whereNotNull('whatsapp_number')\r\n                                ->orderBy('created_at', 'desc')\r\n                                ->first();";

$replace = "                            // Find the most recent user registered in last 24 hours\r\n                            // Check both users.whatsapp_number AND user_whatsapp_numbers table\r\n                            \$recentUser = \\App\\Models\\User::where('created_at', '>=', now()->subDay())\r\n                                ->where(function(\$query) {\r\n                                    \$query->whereNotNull('whatsapp_number')\r\n                                        ->orWhereHas('whatsappNumbers', function(\$q) {\r\n                                            \$q->where('is_active', true);\r\n                                        });\r\n                                })\r\n                                ->orderBy('created_at', 'desc')\r\n                                ->first();";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: ProcessIncomingMessage.php\n";
echo "AUTO-LINK now searches in BOTH:\n";
echo "  - users.whatsapp_number (old system)\n";
echo "  - user_whatsapp_numbers table (new system)\n";
echo "\nUser baru sekarang akan otomatis ter-link!\n";
