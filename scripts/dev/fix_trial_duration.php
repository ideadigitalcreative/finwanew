<?php

// Fix free trial duration from 7 days to 30 days
$file = 'app/Http/Controllers/Auth/SocialAuthController.php';
$content = file_get_contents($file);

$search = "            // Create FREE trial subscription (7 days)\r\n            \$startsAt = Carbon::now();\r\n            \$endsAt = Carbon::now()->addDays(7);";

$replace = "            // Create FREE trial subscription (30 days)\r\n            \$startsAt = Carbon::now();\r\n            \$endsAt = Carbon::now()->addDays(30);";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: SocialAuthController.php\n";
echo "Free trial duration changed from 7 days to 30 days\n";
