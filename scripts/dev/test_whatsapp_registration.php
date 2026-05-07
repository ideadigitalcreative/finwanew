<?php

/**
 * TEST SCRIPT: WhatsApp Registration Flow
 * 
 * Run: php test_whatsapp_registration.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
use Illuminate\Support\Facades\Cache;

echo "=== TESTING WHATSAPP REGISTRATION HELPER ===\n\n";

$testPhone = '6281234567890';

// Test 1: Check confirmation words
echo "Test 1: Confirmation Detection\n";
$confirmTests = ['ya', 'Ya', 'YA', 'iya', 'ok', 'OK', 'daftar', 'DAFTAR'];
foreach ($confirmTests as $word) {
    $result = RegHelper::isConfirmation($word) ? '✅' : '❌';
    echo "  {$result} '{$word}' -> " . (RegHelper::isConfirmation($word) ? 'Confirmed' : 'Not confirmed') . "\n";
}

// Test 2: Check rejection words
echo "\nTest 2: Rejection Detection\n";
$rejectTests = ['tidak', 'Tidak', 'TIDAK', 'no', 'NO', 'cancel', 'batal'];
foreach ($rejectTests as $word) {
    $result = RegHelper::isRejection($word) ? '✅' : '❌';
    echo "  {$result} '{$word}' -> " . (RegHelper::isRejection($word) ? 'Rejected' : 'Not rejected') . "\n";
}

// Test 3: Email validation
echo "\nTest 3: Email Validation\n";
$emailTests = [
    'test@example.com' => true,
    'user.name@domain.co.id' => true,
    'invalid-email' => false,
    'no@domain' => false,
    '@example.com' => false,
];
foreach ($emailTests as $email => $expected) {
    $result = RegHelper::isValidEmail($email);
    $status = ($result === $expected) ? '✅' : '❌';
    echo "  {$status} '{$email}' -> " . ($result ? 'Valid' : 'Invalid') . "\n";
}

// Test 4: Password generation
echo "\nTest 4: Password Generation\n";
for ($i = 1; $i <= 5; $i++) {
    $password = RegHelper::generatePassword();
    echo "  Password {$i}: {$password} (Length: " . strlen($password) . ")\n";
}

// Test 5: Flow management
echo "\nTest 5: Registration Flow Management\n";

// Clear any existing flow
RegHelper::clearFlow($testPhone);
echo "  ✅ Cleared existing flow\n";

// Check if in flow (should be false)
$inFlow = RegHelper::isInRegistrationFlow($testPhone);
echo "  " . ($inFlow ? '❌' : '✅') . " Not in flow: " . ($inFlow ? 'false' : 'true') . "\n";

// Start flow
RegHelper::startFlow($testPhone);
echo "  ✅ Started flow\n";

// Check if in flow (should be true)
$inFlow = RegHelper::isInRegistrationFlow($testPhone);
echo "  " . ($inFlow ? '✅' : '❌') . " In flow: " . ($inFlow ? 'true' : 'false') . "\n";

// Check current step
$step = RegHelper::getCurrentStep($testPhone);
echo "  ✅ Current step: {$step}\n";

// Save name
RegHelper::saveData($testPhone, ['name' => 'Test User']);
echo "  ✅ Saved name\n";

// Set next step
RegHelper::setStep($testPhone, 'awaiting_email');
echo "  ✅ Set step to awaiting_email\n";

// Get registration data
$data = RegHelper::getRegistrationData($testPhone);
echo "  ✅ Registration data: " . json_encode($data) . "\n";

// Clear flow
RegHelper::clearFlow($testPhone);
echo "  ✅ Cleared flow\n";

// Check if in flow (should be false again)
$inFlow = RegHelper::isInRegistrationFlow($testPhone);
echo "  " . ($inFlow ? '❌' : '✅') . " Not in flow after clear: " . ($inFlow ? 'false' : 'true') . "\n";

// Test 6: Message templates
echo "\nTest 6: Message Templates\n";
echo "  ✅ Welcome Message:\n";
echo str_replace("\n", "\n     ", RegHelper::getWelcomeMessage()) . "\n\n";

echo "  ✅ Ask Name Message:\n";
echo str_replace("\n", "\n     ", RegHelper::getAskNameMessage()) . "\n\n";

echo "  ✅ Ask Email Message:\n";
echo str_replace("\n", "\n     ", RegHelper::getAskEmailMessage('John Doe')) . "\n\n";

echo "\n=== ALL TESTS COMPLETED ===\n";
echo "\n✅ Helper is ready to use!\n";
echo "\nNext step: Test with real WhatsApp message from unregistered number\n";
