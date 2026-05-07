<?php
$file = __DIR__ . '/app/Services/FinWaAIService.php';

if (!file_exists($file)) {
    die("❌ File not found: $file\n");
}

$content = file_get_contents($file);

echo "=== VPS Service Check ===\n";
echo "File: $file\n";

// Check for Critical Sentiment Passing in classifyIntent
$checks = [
    'Sentiment Key' => "'sentiment' => \$result['data']['sentiment'] ?? (\$result['sentiment'] ?? null),",
    'Suggestion Key' => "'suggestion' => \$result['data']['suggestion'] ?? (\$result['suggestion'] ?? null)",
    'User ID Param' => 'public function processText(string $message, ?string $userId = null): array'
];

foreach ($checks as $name => $snippet) {
    if (strpos($content, $snippet) !== false) {
        echo "✅ FOUND: $name\n";
    } else {
        echo "❌ MISSING: $name\n";
        // echo "   (Snippet: " . substr($snippet, 0, 50) . "...)\n";
    }
}
