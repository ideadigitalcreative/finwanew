<?php

// Update BroadcastController.php
$file1 = 'app/Http/Controllers/SuperAdmin/BroadcastController.php';
$content1 = file_get_contents($file1);
$content1 = str_replace(
    "                                    'is_primary' => false,\r\n                                    'is_active' => true\r\n                                ]);",
    "                                    'is_primary' => false,\r\n                                    'is_active' => true,\r\n                                    'is_lid' => true\r\n                                ]);",
    $content1
);
file_put_contents($file1, $content1);
echo "✅ Updated: BroadcastController.php\n";

// Update WhatsAppEngineWebhookController.php  
$file2 = 'app/Http/Controllers/Webhook/WhatsAppEngineWebhookController.php';
if (file_exists($file2)) {
    $content2 = file_get_contents($file2);
    $content2 = str_replace(
        "                        'is_primary' => false,\r\n                        'is_active' => true,\r\n                    ]);",
        "                        'is_primary' => false,\r\n                        'is_active' => true,\r\n                        'is_lid' => true\r\n                    ]);",
        $content2
    );
    file_put_contents($file2, $content2);
    echo "✅ Updated: WhatsAppEngineWebhookController.php\n";
} else {
    echo "⚠️  File not found: WhatsAppEngineWebhookController.php\n";
}

echo "\n✅ All LID mapping code updated!\n";
