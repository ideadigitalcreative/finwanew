<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Message;
use App\Jobs\ProcessIncomingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SlackWebhookController extends Controller
{
    /**
     * Handle incoming Slack message
     * Payload sesuai standar: tenant_id, channel, channel_account, sender_id, message_id, type, content, timestamp
     */
    public function handleMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
            'channel' => 'required|in:slack',
            'channel_account' => 'required|string',
            'sender_id' => 'required|string',
            'message_id' => 'required|string',
            'type' => 'required|in:text,image,audio,doc,csv',
            'content' => 'nullable|string',
            'timestamp' => 'required|integer',
            'raw_data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid payload',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $data = $validator->validated();
            
            // Find or create channel
            $channel = Channel::firstOrCreate(
                [
                    'tenant_id' => $data['tenant_id'],
                    'type' => 'slack',
                    'channel_account' => $data['channel_account']
                ],
                [
                    'name' => 'Slack: ' . $data['channel_account'],
                    'is_active' => true
                ]
            );

            // Create message record
            $message = Message::create([
                'tenant_id' => $data['tenant_id'],
                'channel_id' => $channel->id,
                'channel' => $data['channel'],
                'channel_account' => $data['channel_account'],
                'sender_id' => $data['sender_id'],
                'message_id' => $data['message_id'],
                'type' => $data['type'],
                'content' => $data['content'],
                'timestamp' => $data['timestamp'],
                'raw_data' => $data['raw_data'] ?? null
            ]);

            // Update channel last activity
            $channel->update(['last_activity_at' => now()]);

            // Dispatch job to process message
            ProcessIncomingMessage::dispatch($message);

            return response()->json([
                'success' => true,
                'message' => 'Message received and queued for processing',
                'data' => [
                    'message_id' => $message->id,
                    'channel_id' => $channel->id
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error handling Slack message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to process message: ' . $e->getMessage()
            ], 500);
        }
    }
}
