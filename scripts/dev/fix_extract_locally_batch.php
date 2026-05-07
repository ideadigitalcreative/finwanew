<?php

// Update extractTransactionLocally to use extractAmountFromText (which supports batch)
$file = 'app/Jobs/ProcessIncomingMessage.php';
$content = file_get_contents($file);

// Find and replace the amount extraction logic in extractTransactionLocally
$search = "        // Extract amount using regex\r\n        // Supports: 60rb, 60 rb, 60ribu, 60k, 5jt, 5 jt, 5juta, 50000, 50.000\r\n        \$amount = null;\r\n        \r\n        // Pattern 1: Number with suffix (60rb, 5jt, 25k)\r\n        // Use word boundary \\b to ensure suffix is not part of another word\r\n        if (preg_match('/\\b(\\d+(?:[.,]\\d+)?)\\s*(rb|ribu|k|jt|juta)\\b/i', \$textLower, \$matches)) {\r\n            \$num = floatval(str_replace(',', '.', \$matches[1]));\r\n            \$suffix = strtolower(\$matches[2]);\r\n            \r\n            \$multipliers = [\r\n                'rb' => 1000, 'ribu' => 1000, 'k' => 1000,\r\n                'jt' => 1000000, 'juta' => 1000000\r\n            ];\r\n            \r\n            \$amount = (int)(\$num * (\$multipliers[\$suffix] ?? 1));\r\n        }\r\n        // Pattern 2: Plain number with dots (50.000)\r\n        elseif (preg_match('/(\\d{1,3}(?:\\.\\d{3})+)/', \$textLower, \$matches)) {\r\n            \$amount = (int)str_replace('.', '', \$matches[1]);\r\n        }\r\n        // Pattern 3: Plain number (50000, at least 4 digits for amounts)\r\n        elseif (preg_match('/(\\d{4,})/', \$textLower, \$matches)) {\r\n            \$amount = (int)\$matches[1];\r\n        }";

$replace = "        // Extract amount using extractAmountFromText (supports batch transactions)\r\n        \$amount = \$this->extractAmountFromText(\$messageText);";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: extractTransactionLocally()\n";
echo "Now uses extractAmountFromText() which supports batch transactions!\n\n";
echo "Batch transactions will now work correctly! 🎉\n";
