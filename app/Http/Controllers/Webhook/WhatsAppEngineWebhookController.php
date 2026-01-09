<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Controller untuk menerima webhook dari wa-blast engine
 * Format payload dari wa-blast engine akan berbeda dengan standar core-api
 */
class WhatsAppEngineWebhookController extends Controller
{
    protected $webhookService;

    public function __construct(WhatsAppWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Handle incoming message from wa-blast engine
     * Endpoint ini akan dipanggil oleh wa-blast engine saat ada message masuk
     * 
     * Payload dari wa-blast:
     * {
     *   "sessionId": "wa_1_628123456789",
     *   "message": { ... whatsapp-web.js message object ... }
     * }
     */
    public function handleMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sessionId' => 'required|string',
            'message' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $sessionId = $request->input('sessionId');
            $messageData = $request->input('message');

            // Process and forward to core-api
            $result = $this->webhookService->processIncomingMessage($messageData, $sessionId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Message processed successfully'
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to process message'
            ], 500);

        } catch (\Exception $e) {
            Log::error('Error handling WhatsApp engine webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle LID mapping from gateway
     * When gateway sends a message to LID and discovers the real phone number,
     * it calls this endpoint to save the mapping for future routing
     */
    public function handleLidMapping(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lid' => 'required|string',
            'phoneNumber' => 'required|string',
            'sessionId' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $lid = str_replace('@lid', '', $request->input('lid'));
            $phoneNumber = $request->input('phoneNumber');
            $sessionId = $request->input('sessionId');

            // Extract tenant_id from sessionId (format: wa_{tenantId}_{channelAccount})
            $sessionParts = explode('_', $sessionId);
            $channelTenantId = isset($sessionParts[1]) ? (int) $sessionParts[1] : null;

            Log::info('LID mapping received', [
                'lid' => $lid,
                'phone_number' => $phoneNumber,
                'session_id' => $sessionId,
                'channel_tenant_id' => $channelTenantId
            ]);

            // Check if this phone number is already registered
            $existingMapping = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $phoneNumber)
                ->where('is_active', true)
                ->first();

            if ($existingMapping) {
                // Phone number is registered, add LID as alternative number for this user
                $lidExists = \App\Models\UserWhatsAppNumber::where('whatsapp_number', $lid)
                    ->where('user_id', $existingMapping->user_id)
                    ->where('tenant_id', $existingMapping->tenant_id)
                    ->exists();

                if (!$lidExists) {
                    \App\Models\UserWhatsAppNumber::create([
                        'user_id' => $existingMapping->user_id,
                        'tenant_id' => $existingMapping->tenant_id,
                        'whatsapp_number' => $lid,
                        'name' => 'LID - ' . $phoneNumber,
                        'is_primary' => false,
                        'is_active' => true
                    ]);

                    Log::info('LID mapping created', [
                        'lid' => $lid,
                        'phone_number' => $phoneNumber,
                        'user_id' => $existingMapping->user_id,
                        'tenant_id' => $existingMapping->tenant_id
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'LID mapping created successfully',
                        'tenant_id' => $existingMapping->tenant_id
                    ]);
                } else {
                    Log::info('LID mapping already exists', [
                        'lid' => $lid,
                        'user_id' => $existingMapping->user_id,
                        'tenant_id' => $existingMapping->tenant_id
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'LID mapping already exists',
                        'tenant_id' => $existingMapping->tenant_id
                    ]);
                }
            } else {
                Log::warning('Phone number not registered, cannot create LID mapping', [
                    'lid' => $lid,
                    'phone_number' => $phoneNumber
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Phone number not registered'
                ], 404);
            }

        } catch (\Exception $e) {
            Log::error('Error handling LID mapping', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error: ' . $e->getMessage()
            ], 500);
        }
    }
}

