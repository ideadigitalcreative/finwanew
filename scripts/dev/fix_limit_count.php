<?php

// Fix SubscriptionLimitService to exclude LID from count
$file = 'app/Services/SubscriptionLimitService.php';
$content = file_get_contents($file);

$search = "        return UserWhatsAppNumber::where('user_id', \$userId)\r\n            ->where('tenant_id', \$tenantId)\r\n            ->where('is_active', true)\r\n            ->count();";

$replace = "        return UserWhatsAppNumber::where('user_id', \$userId)\r\n            ->where('tenant_id', \$tenantId)\r\n            ->where('is_active', true)\r\n            ->where('is_lid', false) // EXCLUDE LID - hanya hitung nomor telepon asli\r\n            ->count();";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: SubscriptionLimitService.php - LID excluded from count\n";
echo "Sekarang limit akan menghitung hanya nomor telepon asli (exclude LID)\n";
