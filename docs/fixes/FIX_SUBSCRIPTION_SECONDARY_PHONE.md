# Fix: Subscription Check for Secondary Phone Numbers

## Issue
When a user's premium subscription expires:
- **Main phone number**: ✅ Correctly blocked from sending transactions
- **Secondary phone number**: ❌ Could still send transactions (BUG)

## Root Cause
Multiple handlers allowed device linking (LID mapping) **without checking subscription status first**:

1. **WhatsAppWebhookService.php** - Two handlers for LINK command
2. **WhatsAppEngineWebhookController.php** - LID mapping endpoint called by gateway

Once linked, the secondary device could bypass subscription checks since the mapping was already created.

## Fixes Applied

### 1. WhatsAppWebhookService.php
Added subscription check BEFORE allowing device linking in BOTH handlers:

```php
// CHECK SUBSCRIPTION BEFORE LINKING
$userTenantSubscriptionValid = $this->checkSubscriptionStatus($existingUser->tenant_id);

if (!$userTenantSubscriptionValid) {
    // Subscription expired - cannot link device
    // Send expired message and reject
    return ['success' => false, 'handled' => 'link_subscription_expired', 'rejected' => true];
}
```

- **Line ~168**: Priority handler (before tenant routing)
- **Line ~388**: Secondary handler (after tenant routing)

### 2. WhatsAppEngineWebhookController.php
Added subscription check to LID mapping endpoint:

```php
// Check for active subscription
$hasActiveSubscription = Subscription::where('tenant_id', $existingMapping->tenant_id)
    ->where('status', 'active')
    ->where(...)->exists();

// Check if in trial period
$isInTrial = $tenant->trial_ends_at && $tenant->trial_ends_at->isFuture();

if (!$hasActiveSubscription && !$isInTrial) {
    return response()->json([
        'success' => false,
        'message' => 'Subscription expired - cannot create LID mapping'
    ], 403);
}
```

Also added `is_lid => true` flag when creating LID entries for easier identification.

## Files Modified
- `app/Services/WhatsAppWebhookService.php`
- `app/Http/Controllers/Webhook/WhatsAppEngineWebhookController.php`

## Testing
To test this fix:
1. Create a test user with expired subscription
2. From a new device (LID), try to link to the expired user by sending their phone number
3. Expected: System should reject linking with "Langganan Tidak Aktif" message
4. After renewing subscription, linking should work

## Date
2026-02-01

---

# Fix #2: Auto-Link for Website Registrations

## Issue
When a user:
1. Registers through the website with their WhatsApp number
2. Receives the "Account Active" message via WhatsApp
3. Tries to send a transaction

**Expected:** Transaction should be processed immediately
**Actual:** System asks user to send their phone number again to "link" their device

## Root Cause
When users reply to messages from WhatsApp Desktop/Web, their messages come with a **LID format** (WhatsApp Linked ID) instead of their phone number. The system didn't recognize the LID because it wasn't linked yet.

The system was designed to only link LIDs when users explicitly sent their phone number after receiving the "reconnect" message. This was confusing for new users who had just registered and received a welcome message.

## Fix Applied

### Auto-Link Logic (WhatsAppWebhookService.php)
Added intelligent auto-linking in `processIncomingMessage()`:

1. When an incoming message has LID format but is not recognized
2. Check if `senderId` contains a valid phone number format (gateway sometimes includes both)  
3. Search for a user with that phone number in the database
4. If found AND subscription is active → **automatically link the LID**
5. Send a quick confirmation and **continue processing their actual message**

```php
// AUTO-LINK FEATURE: For users who registered via website
if ($actualTenantId === null && $isLidFormat && $originalFrom) {
    // Check if senderId looks like a phone number
    $cleanedPhone = $mappingService->cleanPhoneNumber($senderId);
    $looksLikePhoneNumber = preg_match('/^62\d{9,13}$/', $cleanedPhone);
    
    if ($looksLikePhoneNumber) {
        // Find user by phone number
        $userByPhone = User::where('whatsapp_number', $cleanedPhone)->first();
        
        if ($userByPhone && $userByPhone->tenant_id) {
            // Check subscription before auto-linking
            if ($this->checkSubscriptionStatus($userByPhone->tenant_id)) {
                // AUTO-LINK: Create LID mapping
                UserLidMapping::linkLidToUser($lid, $userByPhone->id, ...);
                
                // Update actualTenantId and continue processing
                $actualTenantId = $userByPhone->tenant_id;
            }
        }
    }
}
```

## User Experience After Fix
1. User registers on website ✅
2. Receives "Account Active" message ✅  
3. Replies with a transaction (e.g., "makan siang 25rb")
4. System automatically links their device and shows: "✅ Perangkat Terhubung Otomatis"
5. Transaction is processed immediately ✅

## Files Modified
- `app/Services/WhatsAppWebhookService.php` (added auto-link logic around line 230-330)
