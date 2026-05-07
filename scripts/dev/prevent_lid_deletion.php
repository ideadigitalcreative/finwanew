<?php

// Prevent LID deactivation in WhatsAppChannelController.php
$file = 'app/Http/Controllers/WhatsAppChannelController.php';
$content = file_get_contents($file);

// Add check for is_lid before line 977
$search = "        // Don't allow deleting if it's the only number\r\n        \$count = UserWhatsAppNumber::where('user_id', \$user->id)";

$replace = "        // Don't allow deleting LID (Linked Device ID)\r\n        if (\$userWhatsAppNumber->is_lid) {\r\n            if (\$request->header('X-Inertia')) {\r\n                return back()->withErrors([\r\n                    'whatsapp_number' => 'Tidak dapat menghapus Linked Device ID. Nomor ini dikelola otomatis oleh sistem.'\r\n                ])->with('error', 'Tidak dapat menghapus Linked Device ID.');\r\n            }\r\n            return redirect()->back()->with('error', 'Tidak dapat menghapus Linked Device ID.');\r\n        }\r\n\r\n        // Don't allow deleting if it's the only number\r\n        \$count = UserWhatsAppNumber::where('user_id', \$user->id)";

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "✅ Updated: WhatsAppChannelController.php - LID deletion prevented\n";
