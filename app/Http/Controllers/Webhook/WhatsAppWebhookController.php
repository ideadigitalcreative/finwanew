<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Attachment;
use App\Jobs\ProcessIncomingMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppWebhookController extends Controller
{
    /**
     * Handle incoming WhatsApp message
     * Payload sesuai standar: tenant_id, channel, channel_account, sender_id, message_id, type, content, timestamp
     */
    public function handleMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
            'channel' => 'required|in:whatsapp',
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
            
            // Rate limiting: max 30 messages per minute per tenant
            $rateLimitKey = 'whatsapp_message:' . $data['tenant_id'];
            $maxAttempts = 30; // 30 messages per minute
            $decaySeconds = 60; // 1 minute window
            
            if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                Log::warning('Rate limit exceeded for WhatsApp message', [
                    'tenant_id' => $data['tenant_id'],
                    'retry_after' => $seconds
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak pesan. Silakan tunggu ' . ceil($seconds) . ' detik lagi.',
                    'retry_after' => $seconds
                ], 429);
            }
            
            // Hit rate limiter
            RateLimiter::hit($rateLimitKey, $decaySeconds);
            
            // Find or create channel
            $channel = Channel::firstOrCreate(
                [
                    'tenant_id' => $data['tenant_id'],
                    'type' => 'whatsapp',
                    'channel_account' => $data['channel_account']
                ],
                [
                    'name' => 'WhatsApp: ' . $data['channel_account'],
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

            // Dispatch job to process message (AI extraction, etc)
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
            Log::error('Error handling WhatsApp message', [
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

    /**
     * Handle attachment upload from WhatsApp gateway
     */
    public function handleAttachment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
            'channel' => 'required|in:whatsapp',
            'channel_account' => 'required|string',
            'file_data' => 'required|string', // Base64 encoded
            'mime_type' => 'required|string',
            'filename' => 'required|string'
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
            
            // Rate limiting: max 10 images per minute per tenant
            $rateLimitKey = 'ocr_upload:' . $data['tenant_id'];
            $maxAttempts = 10; // 10 images per minute
            $decaySeconds = 60; // 1 minute window
            
            if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                Log::warning('Rate limit exceeded for OCR upload', [
                    'tenant_id' => $data['tenant_id'],
                    'retry_after' => $seconds
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Terlalu banyak request. Silakan tunggu ' . ceil($seconds) . ' detik lagi.',
                    'retry_after' => $seconds
                ], 429);
            }
            
            // Hit rate limiter
            RateLimiter::hit($rateLimitKey, $decaySeconds);
            
            // Decode base64 file data
            $fileData = base64_decode($data['file_data'], true);
            if ($fileData === false) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid base64 file data'
                ], 400);
            }

            // Determine file type
            $type = 'other';
            if (str_starts_with($data['mime_type'], 'image/')) {
                $type = 'image';
            } elseif (str_starts_with($data['mime_type'], 'audio/')) {
                $type = 'audio';
            } elseif (str_contains($data['mime_type'], 'pdf') || str_contains($data['mime_type'], 'document')) {
                $type = 'document';
            } elseif (str_contains($data['mime_type'], 'csv') || str_contains($data['mime_type'], 'spreadsheet') || str_contains($data['mime_type'], 'excel')) {
                $type = 'spreadsheet';
            }

            // Generate path (tanpa prefix public/)
            $path = "whatsapp/{$data['tenant_id']}/" . date('Y/m/d') . '/' . uniqid() . '_' . $data['filename'];
            
            // Upload to S3/R2 or public disk (fallback)
            $preferredDisk = config('services.whatsapp.sessions_disk', 'r2');
            
            // Check disk configuration BEFORE trying to use it
            $diskConfig = config("filesystems.disks.{$preferredDisk}");
            $diskName = $preferredDisk;
            
            // If S3/R2 disk but bucket is null or not configured, fallback to public disk
            if (isset($diskConfig['driver']) && $diskConfig['driver'] === 's3') {
                $bucket = $diskConfig['bucket'] ?? env('R2_BUCKET') ?? env('AWS_BUCKET');
                if (empty($bucket)) {
                    Log::warning('S3/R2 disk not configured (bucket missing), using public disk as fallback', [
                        'preferred_disk' => $preferredDisk,
                        'bucket' => $bucket
                    ]);
                    // Use public disk untuk file yang bisa diakses via HTTP
                    $diskName = 'public';
                }
            }
            
            // Now safely get the disk
            $disk = Storage::disk($diskName);
            $disk->put($path, $fileData);

            // Generate URL untuk OCR/STT worker
            // Gunakan API endpoint yang protected dengan API key
            if ($diskName === 'public') {
                // Untuk public disk, gunakan API endpoint dengan query parameter
                // Ini menghindari masalah encoded slash (%2F) yang tidak bisa di-match oleh Laravel route
                // Query parameter lebih reliable dan tidak akan di-encode oleh HTTP client
                $signedUrl = url('/api/files') . '?path=' . urlencode($path);
            } else {
                // Untuk S3/R2, gunakan temporary URL
                $signedUrl = Storage::disk($diskName)->temporaryUrl($path, now()->addHours(24));
            }

            return response()->json([
                'success' => true,
                'url' => $signedUrl,
                'path' => $path,
                'type' => $type,
                'size' => strlen($fileData)
            ]);

        } catch (\Exception $e) {
            Log::error('Error handling WhatsApp attachment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to upload attachment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle channel status update
     */
    public function handleStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
            'channel' => 'required|in:whatsapp',
            'channel_account' => 'required|string',
            'status' => 'required|string'
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
            
            $channel = Channel::where('tenant_id', $data['tenant_id'])
                ->where('type', 'whatsapp')
                ->where('channel_account', $data['channel_account'])
                ->first();

            if ($channel) {
                // Generate session_id from tenant_id and channel_account (format: wa_{tenantId}_{channelAccount})
                $sessionId = 'wa_' . $data['tenant_id'] . '_' . $data['channel_account'];
                
                // Normalize status
                $normalizedStatus = strtolower($data['status']);
                if ($normalizedStatus === 'connected' || $normalizedStatus === 'authenticated') {
                    $normalizedStatus = 'connected';
                } elseif ($normalizedStatus === 'logout' || $normalizedStatus === 'disconnected') {
                    $normalizedStatus = 'disconnected';
                }
                
                $config = $channel->config ?? [];
                $channel->update([
                    'session_id' => $sessionId,
                    'session_status' => $normalizedStatus,
                    'is_active' => in_array($normalizedStatus, ['connected', 'authenticated']),
                    'last_activity_at' => now(),
                    'config' => array_merge($config, [
                        'session_id' => $sessionId,
                        'session_status' => $normalizedStatus,
                        'last_status' => $data['status'],
                        'status_updated_at' => now()->toIso8601String()
                    ])
                ]);
                
                Log::info('Channel status updated', [
                    'channel_id' => $channel->id,
                    'session_id' => $sessionId,
                    'status' => $normalizedStatus,
                    'is_active' => $channel->is_active
                ]);
            } else {
                Log::warning('Channel not found for status update', [
                    'tenant_id' => $data['tenant_id'],
                    'channel_account' => $data['channel_account']
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Status updated'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating WhatsApp channel status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to update status: ' . $e->getMessage()
            ], 500);
        }
    }
}
