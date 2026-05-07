<?php

$file = __DIR__.'/app/Jobs/ProcessIncomingMessage.php';

if (! file_exists($file)) {
    exit("❌ File not found: $file\n");
}

$content = file_get_contents($file);

echo "=== VPS File Check ===\n";
echo "File: $file\n";
echo 'Size: '.strlen($content)." bytes\n\n";

// Check for Critical Lines
$checks = [
    'Assignment Sentiment' => '$this->currentSentiment = $intentResult[\'data\'][\'sentiment\'] ?? null;',
    'Debug Line' => '$reply .= "\n\n🐞 [DEBUG: Mood=$moodDebug | Score=$scoreDebug]";',
    'SentimentResponses Array' => '$sentimentResponses = [',
    'Handle Stats AI' => 'handleCheckStatisticsWithAI',
];

foreach ($checks as $name => $snippet) {
    if (strpos($content, $snippet) !== false) {
        echo "✅ FOUND: $name\n";
    } else {
        echo "❌ MISSING: $name\n";
        echo '   (Snippet: '.substr($snippet, 0, 50)."...)\n";
    }
}
echo "\n";

// Extract line around 1168 (approx)
$lines = explode("\n", $content);
echo "--- Context Check (Lines 1160-1175 approx) ---\n";
for ($i = 1160; $i < 1180; $i++) {
    if (isset($lines[$i])) {
        $marker = (strpos($lines[$i], 'currentSentiment') !== false) ? ' <--- HERE' : '';
        echo ($i + 1).': '.trim($lines[$i]).$marker."\n";
    }
}
