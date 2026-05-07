<?php

/**
 * Script untuk menambahkan 'is_lid' => true di semua tempat pembuatan LID mapping
 * Run: php update_lid_mappings.php
 */

$files = [
    [
        'file' => 'app/Jobs/ProcessIncomingMessage.php',
        'line' => 175,
        'search' => "                                     'is_active' => true\n                                 ]);",
        'replace' => "                                     'is_active' => true,\n                                     'is_lid' => true\n                                 ]);"
    ],
    [
        'file' => 'app/Http/Controllers/SuperAdmin/BroadcastController.php',
        'line' => 240,
        'search' => "                                    'is_active' => true,\n                                ]);",
        'replace' => "                                    'is_active' => true,\n                                    'is_lid' => true\n                                ]);"
    ],
    [
        'file' => 'app/Http/Controllers/Webhook/WhatsAppEngineWebhookController.php',
        'line' => 139,
        'search' => "                        'is_active' => true,\n                    ]);",
        'replace' => "                        'is_active' => true,\n                        'is_lid' => true\n                    ]);"
    ]
];

echo "=== Updating LID Mapping Code ===\n\n";

foreach ($files as $fileInfo) {
    $filePath = __DIR__ . '/' . $fileInfo['file'];
    
    if (!file_exists($filePath)) {
        echo "❌ File not found: {$fileInfo['file']}\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    if (strpos($content, $fileInfo['search']) !== false) {
        $newContent = str_replace($fileInfo['search'], $fileInfo['replace'], $content);
        file_put_contents($filePath, $newContent);
        echo "✅ Updated: {$fileInfo['file']} (around line {$fileInfo['line']})\n";
    } else {
        echo "⚠️  Pattern not found in: {$fileInfo['file']}\n";
        echo "   Might be already updated or format changed\n";
    }
}

echo "\n=== Done! ===\n";
echo "Please verify the changes manually.\n";
