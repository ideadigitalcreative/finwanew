<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Tenant;
use App\Models\Subscription;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppService;
use App\Services\SubscriptionLimitService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppChannelController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * List all WhatsApp channels for current tenant
     */
    public function index(Request $request): Response
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        
        // Get newChannelId from session flash data (set by store method) or query parameter
        $newChannelId = session('newChannelId') ?? $request->query('newChannelId');
        
        // Clear session flash data after reading
        if (session('newChannelId')) {
            session()->forget('newChannelId');
        }

        // Log for debugging
        Log::info('Loading WhatsApp channels', [
            'tenant_id' => $tenant->id,
            'newChannelId' => $newChannelId
        ]);
        
        // Daftar nomor admin yang tidak boleh ditampilkan di halaman user
        $adminNumbers = [
            '6285762000079', // Nomor admin yang tidak boleh ditampilkan
            '6285242766676', // Super admin number
        ];
        
        $channels = Channel::where('tenant_id', $tenant->id)
            ->where('type', 'whatsapp')
            ->where('is_shared_channel', false) // Jangan tampilkan shared channel (admin channel)
            ->whereNotIn('channel_account', $adminNumbers) // Jangan tampilkan nomor admin
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(20);
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($channel) {
                $config = $channel->config ?? [];
                // Use session_id from database column first, fallback to config
                $sessionId = $channel->session_id ?? $config['session_id'] ?? null;
                
                // Use session_status from database column first, fallback to config
                $sessionStatus = $channel->session_status ?? $config['session_status'] ?? null;
                $lastStatusUpdate = $config['status_updated_at'] ?? null;
                $lastStatusCheck = $config['last_status_check'] ?? null;
                
                // Priority: Trust database status if it was updated recently (< 60 seconds ago)
                // Only check gateway if status is old or unknown
                $shouldCheckStatus = false;
                if ($sessionId) {
                    $statusCheckCacheTime = 60; // Cache for 60 seconds
                    $statusUpdateAge = $lastStatusUpdate ? now()->diffInSeconds(\Carbon\Carbon::parse($lastStatusUpdate)) : 999;
                    $statusCheckAge = $lastStatusCheck ? (now()->timestamp - $lastStatusCheck) : 999;
                    
                    // Check status from gateway ONLY if:
                    // 1. No status in database (first time)
                    // 2. Status was last updated more than 60 seconds ago (stale status)
                    // 3. Last status check was more than 60 seconds ago (prevent spam)
                    // 4. Current status is NOT disconnected (if disconnected, trust it unless very old)
                    if (!$sessionStatus) {
                        $shouldCheckStatus = true;
                    } elseif ($sessionStatus === 'disconnected') {
                        // If status is disconnected, only check if status update is very old (> 5 minutes)
                        // This prevents overriding a recent logout with stale gateway status
                        if ($statusUpdateAge > 300) { // 5 minutes
                            $shouldCheckStatus = true;
                        }
                    } elseif ($sessionStatus !== 'connected' && $sessionStatus !== 'authenticated') {
                        // Status is error, initializing, etc. - check gateway
                        if ($statusCheckAge > $statusCheckCacheTime) {
                            $shouldCheckStatus = true;
                        }
                    } else {
                        // Status is connected/authenticated - only check if stale
                        if ($statusUpdateAge > 300 || $statusCheckAge > $statusCheckCacheTime) {
                            $shouldCheckStatus = true;
                        }
                    }
                }
                
                if ($shouldCheckStatus) {
                    try {
                        $statusResult = $this->whatsappService->getSessionStatus($sessionId);
                        if ($statusResult['success']) {
                            // Handle different response formats
                            $responseData = $statusResult['data'] ?? [];
                            $gatewayStatus = $responseData['status'] ?? $responseData['data']['status'] ?? $statusResult['status'] ?? 'unknown';
                            
                            // Normalize status (handle case variations)
                            $gatewayStatus = strtolower($gatewayStatus);
                            if ($gatewayStatus === 'connected' || $gatewayStatus === 'authenticated') {
                                $gatewayStatus = 'connected';
                            }
                            
                            // IMPORTANT: Don't override "disconnected" status from database with "connected" from gateway
                            // unless the disconnected status is very old (> 5 minutes)
                            // This prevents showing "connected" when user actually logged out on phone
                            if ($sessionStatus === 'disconnected' && $gatewayStatus === 'connected') {
                                $statusUpdateAge = $lastStatusUpdate ? now()->diffInSeconds(\Carbon\Carbon::parse($lastStatusUpdate)) : 999;
                                if ($statusUpdateAge < 300) { // Less than 5 minutes old
                                    Log::info('Ignoring gateway status (connected) - database shows recent disconnect', [
                                        'channel_id' => $channel->id,
                                        'database_status' => $sessionStatus,
                                        'gateway_status' => $gatewayStatus,
                                        'status_update_age' => $statusUpdateAge
                                    ]);
                                    // Keep disconnected status, but update last check time
                                    $channel->update([
                                        'config' => array_merge($config, [
                                            'last_status_check' => now()->timestamp
                                        ])
                                    ]);
                                    // Don't update $sessionStatus - keep it as disconnected
                                } else {
                                    // Status is old, trust gateway
                                    $channel->update([
                                        'session_status' => $gatewayStatus,
                                        'is_active' => in_array($gatewayStatus, ['connected', 'authenticated']),
                                        'config' => array_merge($config, [
                                            'session_status' => $gatewayStatus,
                                            'last_status_check' => now()->timestamp
                                        ])
                                    ]);
                                    $sessionStatus = $gatewayStatus;
                                }
                            } else {
                                // Normal update - status matches or is updating from error/unknown
                                $channel->update([
                                    'session_status' => $gatewayStatus,
                                    'is_active' => in_array($gatewayStatus, ['connected', 'authenticated']),
                                    'config' => array_merge($config, [
                                        'session_status' => $gatewayStatus,
                                        'last_status_check' => now()->timestamp
                                    ])
                                ]);
                                $sessionStatus = $gatewayStatus;
                            }
                        } else {
                            // If status check fails, keep existing status
                            $sessionStatus = $sessionStatus ?? 'error';
                            // Update last check time even on error to prevent spam
                            $channel->update([
                                'config' => array_merge($config, [
                                    'last_status_check' => now()->timestamp
                                ])
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to get session status', [
                            'channel_id' => $channel->id,
                            'error' => $e->getMessage()
                        ]);
                        // Keep existing status if available, otherwise set to error
                        $sessionStatus = $sessionStatus ?? 'error';
                        // Update last check time even on error to prevent spam
                        $channel->update([
                            'config' => array_merge($config, [
                                'last_status_check' => now()->timestamp
                            ])
                        ]);
                    }
                }

                // Get recent messages with transaction status
                $recentMessages = $channel->messages->map(function ($message) {
                    $hasTransaction = \App\Models\Transaction::where('message_id', $message->id)->exists();
                    return [
                        'id' => $message->id,
                        'content' => $message->content,
                        'type' => $message->type,
                        'sender_id' => $message->sender_id,
                        'status' => $message->status,
                        'has_transaction' => $hasTransaction,
                        'created_at' => $message->created_at->format('Y-m-d H:i:s'),
                        'created_at_human' => $message->created_at->diffForHumans(),
                    ];
                });

                return [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'channel_account' => $channel->channel_account,
                    'is_active' => $channel->is_active,
                    'session_id' => $sessionId,
                    'session_status' => $sessionStatus,
                    'last_activity_at' => $channel->last_activity_at?->format('Y-m-d H:i:s'),
                    'messages_count' => $channel->messages->count(),
                    'recent_messages' => $recentMessages,
                    'created_at' => $channel->created_at->format('Y-m-d H:i:s'),
                ];
            });
        
        // Log channels after mapping
        Log::info('Channels after mapping', [
            'count' => $channels->count(),
            'channel_ids' => $channels->pluck('id')->toArray()
        ]);

        // Check if tenant has active subscription
        $hasActiveSubscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->exists();

        // Tenant is active if has active subscription AND tenant.is_active is true
        $tenantIsActive = $tenant->is_active && $hasActiveSubscription;

        // Get user WhatsApp numbers
        $user = $request->user();
        $userWhatsAppNumbers = UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('is_primary', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($number) {
                // Hide LID numbers from user view
                // LID format: long number that doesn't start with 62 (Indonesian format)
                // Show only:
                // 1. Primary numbers (always show)
                // 2. Numbers starting with 62 (Indonesian phone numbers)
                // 3. Numbers with less than 13 digits (normal phone numbers)
                
                if ($number->is_primary) {
                    return true; // Always show primary number
                }
                
                $phoneNumber = $number->whatsapp_number;
                
                // Check if it's a LID (long number, not Indonesian format)
                // LID typically: 62363562709234 (starts with 623635... or similar non-standard prefix)
                // Real Indonesian number: 628xxx (starts with 628)
                
                // Hide if:
                // - Starts with 62 but NOT 628 (likely LID)
                // - OR has name containing "LID"
                if (str_starts_with($phoneNumber, '62') && !str_starts_with($phoneNumber, '628')) {
                    return false; // Hide LID
                }
                
                if (str_contains(strtolower($number->name ?? ''), 'lid')) {
                    return false; // Hide if name contains "LID"
                }
                
                return true; // Show normal numbers
            })
            ->values() // Re-index array after filter
            ->map(function ($number) {
                return [
                    'id' => $number->id,
                    'whatsapp_number' => $number->whatsapp_number,
                    'name' => $number->name,
                    'is_primary' => $number->is_primary,
                    'created_at' => $number->created_at->format('Y-m-d H:i:s'),
                ];
            });

        // Get limit info
        $limitService = new SubscriptionLimitService();
        $limitInfo = $limitService->getLimitInfo($user->id, $tenant->id);

        return Inertia::render('WhatsApp/Index', [
            'channels' => $channels,
            'newChannelId' => $newChannelId ? (int) $newChannelId : null,
            'tenantIsActive' => $tenantIsActive,
            'hasActiveSubscription' => $hasActiveSubscription,
            'userWhatsAppNumbers' => $userWhatsAppNumbers,
            'limitInfo' => $limitInfo,
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Create new WhatsApp channel and session
     */
    public function store(Request $request)
    {
        $request->validate([
            'channel_account' => 'required|string|regex:/^[0-9]+$/',
            'name' => 'nullable|string|max:255',
        ]);

        $tenant = Tenant::findOrFail($request->tenant_id);

        // Clean phone number (remove +, spaces, etc)
        $phoneNumber = preg_replace('/[^0-9]/', '', $request->channel_account);
        
        // Check if channel already exists
        $existingChannel = Channel::where('tenant_id', $tenant->id)
            ->where('type', 'whatsapp')
            ->where('channel_account', $phoneNumber)
            ->first();

        if ($existingChannel) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'channel_account' => 'Channel dengan nomor ini sudah ada'
                ])->with('error', 'Channel dengan nomor ini sudah ada');
            }
            return redirect()->back()->with('error', 'Channel dengan nomor ini sudah ada');
        }

        try {
            // Create session in WhatsApp Gateway
            Log::info('Creating WhatsApp session', [
                'tenant_id' => $tenant->id,
                'phone_number' => $phoneNumber
            ]);
            
            $sessionResult = $this->whatsappService->createSession($tenant->id, $phoneNumber, $phoneNumber);

            if (!$sessionResult['success']) {
                $errorMessage = $sessionResult['error'] ?? 'Unknown error';
                $statusCode = $sessionResult['status_code'] ?? null;
                
                Log::error('Failed to create WhatsApp session', [
                    'tenant_id' => $tenant->id,
                    'phone_number' => $phoneNumber,
                    'error' => $errorMessage,
                    'status_code' => $statusCode,
                    'engine_url' => config('services.whatsapp.engine_url'),
                    'api_key_set' => !empty(config('services.whatsapp.api_key'))
                ]);
                
                // Provide more helpful error message (already formatted by WhatsAppService)
                // Error message dari WhatsAppService sudah user-friendly
                
                // For Inertia requests, return error in a way that frontend can handle
                if ($request->header('X-Inertia')) {
                    Log::error('Returning error to Inertia frontend', [
                        'error_message' => $errorMessage,
                        'status_code' => $statusCode
                    ]);
                    
                    return back()->withErrors([
                        'session' => $errorMessage,
                        'channel_account' => $errorMessage
                    ])->with('error', $errorMessage);
                }
                
                return redirect()->back()->with('error', 'Gagal membuat session: ' . $errorMessage);
            }

            $sessionId = $sessionResult['sessionId'] ?? $this->whatsappService->generateSessionId($tenant->id, $phoneNumber);
            
            Log::info('WhatsApp session created successfully', [
                'tenant_id' => $tenant->id,
                'phone_number' => $phoneNumber,
                'session_id' => $sessionId,
                'session_result' => $sessionResult
            ]);

            // Check if channel already exists, update instead of create
            $channel = Channel::where('tenant_id', $tenant->id)
                ->where('type', 'whatsapp')
                ->where('channel_account', $phoneNumber)
                ->first();

            if ($channel) {
                // Update existing channel
                $config = $channel->config ?? [];
                $config['session_id'] = $sessionId;
                $config['engine_url'] = config('services.whatsapp.engine_url');
                $channel->update([
                    'session_id' => $sessionId,
                    'session_status' => 'initializing',
                    'is_active' => false, // Set to false until connected
                    'config' => $config
                ]);
                Log::info('Channel updated', ['channel_id' => $channel->id]);
            } else {
                // Create channel in database
                $channel = Channel::create([
                    'tenant_id' => $tenant->id,
                    'type' => 'whatsapp',
                    'name' => $request->name ?? 'WhatsApp: ' . $phoneNumber,
                    'channel_account' => $phoneNumber,
                    'session_id' => $sessionId,
                    'session_status' => 'initializing',
                    'is_active' => false, // Set to false until connected
                    'config' => [
                        'session_id' => $sessionId,
                        'session_status' => 'initializing',
                        'engine_url' => config('services.whatsapp.engine_url'),
                    ],
                ]);
            }

            // For Inertia requests, redirect to index method which will load all channels
            // This ensures the index method is called and data is properly loaded
            if ($request->header('X-Inertia')) {
                // Store newChannelId in session flash data
                session()->flash('newChannelId', $channel->id);
                
                // Redirect to index - index method will read from session and pass to Inertia
                return redirect()->route('whatsapp.index', [
                    'tenant_id' => $tenant->id
                ])->with('success', 'Channel WhatsApp berhasil dibuat. QR code akan muncul otomatis.');
            }
            
            // For AJAX/JSON requests, return channel data
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Channel WhatsApp berhasil dibuat. Scan QR code untuk menghubungkan.',
                    'channel' => [
                        'id' => $channel->id,
                        'session_id' => $sessionId,
                    ]
                ]);
            }

            return redirect()->back()->with('success', 'Channel WhatsApp berhasil dibuat. Scan QR code untuk menghubungkan.');
        } catch (\Exception $e) {
            Log::error('Failed to create WhatsApp channel', [
                'tenant_id' => $tenant->id,
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Gagal membuat channel: ' . $e->getMessage());
        }
    }

    /**
     * Get QR code for session
     */
    public function getQrCode(Request $request, Channel $channel)
    {
        if ($channel->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'error' => 'Session ID not found. Please reconnect the channel.'
            ], 404);
        }

        try {
            Log::info('Getting QR code for channel', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId
            ]);

            // Check session status first - if connected, don't return QR
            $statusResult = $this->whatsappService->getSessionStatus($sessionId);
            if ($statusResult['success']) {
                $status = $statusResult['status'] ?? $statusResult['data']['status'] ?? 'unknown';
                Log::info('Session status check', [
                    'session_id' => $sessionId,
                    'status' => $status
                ]);
                if ($status === 'connected' || $status === 'CONNECTED' || $status === 'authenticated') {
                    return response()->json([
                        'success' => false,
                        'error' => 'WhatsApp sudah terhubung. QR code tidak diperlukan.',
                        'status' => $status
                    ], 400);
                }
            }

            $qrResult = $this->whatsappService->getQrCode($sessionId);
            
            Log::info('QR code result', [
                'success' => $qrResult['success'] ?? false,
                'has_data' => isset($qrResult['data']),
                'error' => $qrResult['error'] ?? null
            ]);
            
            // If QR not ready yet, return helpful message
            if (!$qrResult['success']) {
                $statusCode = $qrResult['status_code'] ?? 404;
                $status = $qrResult['status'] ?? 'unknown';
                
                Log::warning('QR code not available', [
                    'session_id' => $sessionId,
                    'error' => $qrResult['error'] ?? 'Unknown error',
                    'status_code' => $statusCode,
                    'status' => $status
                ]);
                
                // Return 202 if QR is being prepared
                if ($statusCode === 202 || $status === 'initializing' || $status === 'connecting') {
                    return response()->json([
                        'success' => false,
                        'error' => 'QR code sedang dipersiapkan. Silakan tunggu beberapa detik dan coba lagi.',
                        'status' => $status,
                        'retry_in' => 3
                    ], 202); // 202 Accepted - not ready yet
                }
                
                return response()->json([
                    'success' => false,
                    'error' => $qrResult['error'] ?? 'QR code belum tersedia. Status: ' . $status,
                    'status' => $status,
                    'debug' => [
                        'session_id' => $sessionId,
                        'engine_url' => config('services.whatsapp.engine_url')
                    ]
                ], 404);
            }
            
            // Parse QR code data - handle different response formats
            // wa-blast returns: { success: true, data: { sessionId, status, qrCode } }
            $qrData = $qrResult['data'] ?? [];
            $qrCode = null;
            
            // Try different possible keys
            if (isset($qrData['data']['qrCode'])) {
                $qrCode = $qrData['data']['qrCode'];
            } elseif (isset($qrData['data']['qr'])) {
                $qrCode = $qrData['data']['qr'];
            } elseif (isset($qrData['qrCode'])) {
                $qrCode = $qrData['qrCode'];
            } elseif (isset($qrData['qr'])) {
                $qrCode = $qrData['qr'];
            } elseif (isset($qrData['data']) && is_string($qrData['data'])) {
                $qrCode = $qrData['data'];
            } elseif (is_string($qrData)) {
                $qrCode = $qrData;
            }
            
            Log::info('QR code parsed', [
                'has_qrCode' => !empty($qrCode),
                'qrCode_length' => $qrCode ? strlen($qrCode) : 0,
                'qrCode_starts_with' => $qrCode ? substr($qrCode, 0, 50) : 'null'
            ]);
            
            if (empty($qrCode)) {
                // Check if QR is not ready yet
                $status = $qrData['data']['status'] ?? $qrData['status'] ?? 'unknown';
                if ($status === 'connecting' || $status === 'qr_ready') {
                    return response()->json([
                        'success' => false,
                        'error' => 'QR code sedang dipersiapkan. Silakan tunggu beberapa detik dan coba lagi.',
                        'status' => $status,
                        'retry_in' => 3
                    ], 202); // 202 Accepted - not ready yet
                }
                
                return response()->json([
                    'success' => false,
                    'error' => 'QR code belum tersedia. Status: ' . $status,
                    'status' => $status
                ], 404);
            }
            
            // QR code from wa-blast is already a data URL (from qrcode.toDataURL())
            // So we don't need to convert it
            if (!str_starts_with($qrCode, 'data:image') && !str_starts_with($qrCode, '<svg') && !str_starts_with($qrCode, 'http')) {
                // If it's base64 without prefix, add it
                $qrCode = 'data:image/png;base64,' . $qrCode;
            }
            
            $status = $qrData['data']['status'] ?? $qrData['status'] ?? 'ready';
            
            Log::info('QR code ready to return', [
                'session_id' => $sessionId,
                'status' => $status,
                'qrCode_length' => strlen($qrCode)
            ]);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'qr' => $qrCode,
                    'qrCode' => $qrCode,
                    'session_id' => $sessionId,
                    'status' => $status
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting QR code', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get session status
     */
    public function getStatus(Request $request, Channel $channel)
    {
        if ($channel->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'error' => 'Session ID not found'
            ], 404);
        }

        try {
            $statusResult = $this->whatsappService->getSessionStatus($sessionId);
            return response()->json($statusResult);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reconnect session
     */
    public function reconnect(Request $request, Channel $channel)
    {
        if ($channel->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        if (!$sessionId) {
            return redirect()->back()->with('error', 'Session ID not found');
        }

        try {
            $result = $this->whatsappService->reconnectSession($sessionId);
            
            if ($result['success']) {
                // Update channel config with latest status
                $status = $result['status'] ?? $result['data']['status'] ?? 'reconnecting';
                $config['session_status'] = $status;
                $channel->update(['config' => $config]);
                
                Log::info('Session reconnected', [
                    'channel_id' => $channel->id,
                    'session_id' => $sessionId,
                    'status' => $status
                ]);
                
                return redirect()->back()->with('success', 'Session berhasil di-reconnect');
            }

            return redirect()->back()->with('error', 'Gagal reconnect: ' . ($result['error'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            Log::error('Error reconnecting session', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete all WhatsApp sessions from gateway
     */
    public function deleteAllSessions(Request $request)
    {
        try {
            $result = $this->whatsappService->deleteAllSessions();
            
            if ($result['success']) {
                Log::info('All WhatsApp sessions deleted', [
                    'result' => $result
                ]);
                
                return redirect()->back()->with('success', $result['message'] ?? 'Semua session WhatsApp berhasil dihapus.');
            }
            
            return redirect()->back()->with('error', 'Gagal menghapus semua session: ' . ($result['error'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            Log::error('Failed to delete all WhatsApp sessions', [
                'error' => $e->getMessage()
            ]);
            
            return redirect()->back()->with('error', 'Error: ' . $e->getMessage());
        }
    }

    /**
     * Delete session and channel
     */
    public function destroy(Request $request, Channel $channel)
    {
        if ($channel->tenant_id !== $request->tenant_id) {
            abort(403);
        }

        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        try {
            // Delete session from engine first (before deleting channel)
            if ($sessionId) {
                Log::info('Deleting WhatsApp session', [
                    'channel_id' => $channel->id,
                    'session_id' => $sessionId
                ]);
                
                $deleteResult = $this->whatsappService->deleteSession($sessionId);
                
                if (!$deleteResult['success']) {
                    Log::warning('Failed to delete session from gateway, continuing with channel deletion', [
                        'channel_id' => $channel->id,
                        'session_id' => $sessionId,
                        'error' => $deleteResult['error'] ?? 'Unknown error'
                    ]);
                    // Continue with channel deletion even if session deletion fails
                } else {
                    Log::info('Session deleted successfully', [
                        'channel_id' => $channel->id,
                        'session_id' => $sessionId
                    ]);
                }
            }

            // Delete channel from database
            $channel->delete();
            
            Log::info('Channel deleted successfully', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId
            ]);

            // For Inertia requests
            if ($request->header('X-Inertia')) {
                return redirect()->route('whatsapp.index', [
                    'tenant_id' => $request->tenant_id
                ])->with('success', 'Channel berhasil dihapus');
            }

            return redirect()->route('whatsapp.index', [
                'tenant_id' => $request->tenant_id
            ])->with('success', 'Channel berhasil dihapus');
        } catch (\Exception $e) {
            Log::error('Failed to delete WhatsApp channel', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'delete' => 'Gagal menghapus channel: ' . $e->getMessage()
                ])->with('error', 'Gagal menghapus channel: ' . $e->getMessage());
            }

            return redirect()->back()->with('error', 'Gagal menghapus channel: ' . $e->getMessage());
        }
    }

    /**
     * Store user WhatsApp number
     */
    public function storeNumber(Request $request)
    {
        $request->validate([
            'whatsapp_number' => 'required|string|regex:/^[0-9+\-\s()]+$/|max:20',
            'name' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $tenant = Tenant::findOrFail($request->tenant_id);
        $user = $request->user();

        // Clean phone number
        $phoneNumber = preg_replace('/[^0-9]/', '', $request->whatsapp_number);
        
        // Normalize: add 62 if doesn't start with it
        if (substr($phoneNumber, 0, 1) === '0') {
            $phoneNumber = '62' . substr($phoneNumber, 1);
        } elseif (substr($phoneNumber, 0, 2) !== '62') {
            $phoneNumber = '62' . $phoneNumber;
        }

        // Check limit
        $limitService = new SubscriptionLimitService();
        $limitCheck = $limitService->canAddWhatsAppNumber($user->id, $tenant->id);

        if (!$limitCheck['can_add']) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'whatsapp_number' => "Limit nomor WhatsApp sudah tercapai. Paket {$limitCheck['plan']} hanya dapat menambahkan maksimal {$limitCheck['limit']} nomor."
                ])->with('error', "Limit nomor WhatsApp sudah tercapai. Paket {$limitCheck['plan']} hanya dapat menambahkan maksimal {$limitCheck['limit']} nomor.");
            }
            return redirect()->back()->with('error', "Limit nomor WhatsApp sudah tercapai. Paket {$limitCheck['plan']} hanya dapat menambahkan maksimal {$limitCheck['limit']} nomor.");
        }

        // Check if number already exists
        $existing = UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('whatsapp_number', $phoneNumber)
            ->first();

        if ($existing) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'whatsapp_number' => 'Nomor WhatsApp ini sudah terdaftar'
                ])->with('error', 'Nomor WhatsApp ini sudah terdaftar');
            }
            return redirect()->back()->with('error', 'Nomor WhatsApp ini sudah terdaftar');
        }

        // If setting as primary, unset other primary numbers
        $isPrimary = $request->boolean('is_primary', false);
        if ($isPrimary) {
            UserWhatsAppNumber::where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->update(['is_primary' => false]);
        }

        // If this is the first number, set as primary
        $isFirst = UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->count() === 0;
        
        if ($isFirst) {
            $isPrimary = true;
        }

        // Create number
        $userWhatsAppNumber = UserWhatsAppNumber::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'whatsapp_number' => $phoneNumber,
            'name' => $request->name,
            'is_primary' => $isPrimary,
            'is_active' => true,
        ]);

        Log::info('User WhatsApp number created', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'number_id' => $userWhatsAppNumber->id,
            'whatsapp_number' => $phoneNumber,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect()->route('whatsapp.index', [
                'tenant_id' => $tenant->id
            ])->with('success', 'Nomor WhatsApp berhasil ditambahkan');
        }

        return redirect()->back()->with('success', 'Nomor WhatsApp berhasil ditambahkan');
    }

    /**
     * Update user WhatsApp number
     */
    public function updateNumber(Request $request, UserWhatsAppNumber $userWhatsAppNumber)
    {
        $request->validate([
            'whatsapp_number' => 'required|string|regex:/^[0-9+\-\s()]+$/|max:20',
            'name' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $tenant = Tenant::findOrFail($request->tenant_id);
        $user = $request->user();

        // Verify ownership
        if ($userWhatsAppNumber->user_id !== $user->id || $userWhatsAppNumber->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }

        // Clean phone number
        $phoneNumber = preg_replace('/[^0-9]/', '', $request->whatsapp_number);
        
        // Normalize: add 62 if doesn't start with it
        if (substr($phoneNumber, 0, 1) === '0') {
            $phoneNumber = '62' . substr($phoneNumber, 1);
        } elseif (substr($phoneNumber, 0, 2) !== '62') {
            $phoneNumber = '62' . $phoneNumber;
        }

        // Check if number already exists (excluding current)
        $existing = UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('whatsapp_number', $phoneNumber)
            ->where('id', '!=', $userWhatsAppNumber->id)
            ->first();

        if ($existing) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'whatsapp_number' => 'Nomor WhatsApp ini sudah terdaftar'
                ])->with('error', 'Nomor WhatsApp ini sudah terdaftar');
            }
            return redirect()->back()->with('error', 'Nomor WhatsApp ini sudah terdaftar');
        }

        // If setting as primary, unset other primary numbers
        $isPrimary = $request->boolean('is_primary', false);
        if ($isPrimary) {
            UserWhatsAppNumber::where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $userWhatsAppNumber->id)
                ->update(['is_primary' => false]);
        }

        // Update number
        $userWhatsAppNumber->update([
            'whatsapp_number' => $phoneNumber,
            'name' => $request->name,
            'is_primary' => $isPrimary,
        ]);

        Log::info('User WhatsApp number updated', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'number_id' => $userWhatsAppNumber->id,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect()->route('whatsapp.index', [
                'tenant_id' => $tenant->id
            ])->with('success', 'Nomor WhatsApp berhasil diupdate');
        }

        return redirect()->back()->with('success', 'Nomor WhatsApp berhasil diupdate');
    }

    /**
     * Delete user WhatsApp number
     */
    public function destroyNumber(Request $request, UserWhatsAppNumber $userWhatsAppNumber)
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        $user = $request->user();

        // Verify ownership
        if ($userWhatsAppNumber->user_id !== $user->id || $userWhatsAppNumber->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }

        // Don't allow deleting LID (Linked Device ID)
        if ($userWhatsAppNumber->is_lid) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'whatsapp_number' => 'Tidak dapat menghapus Linked Device ID. Nomor ini dikelola otomatis oleh sistem.'
                ])->with('error', 'Tidak dapat menghapus Linked Device ID.');
            }
            return redirect()->back()->with('error', 'Tidak dapat menghapus Linked Device ID.');
        }

        // Don't allow deleting if it's the only number
        $count = UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();

        if ($count <= 1) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'whatsapp_number' => 'Tidak dapat menghapus nomor terakhir. Setidaknya harus ada 1 nomor WhatsApp.'
                ])->with('error', 'Tidak dapat menghapus nomor terakhir. Setidaknya harus ada 1 nomor WhatsApp.');
            }
            return redirect()->back()->with('error', 'Tidak dapat menghapus nomor terakhir. Setidaknya harus ada 1 nomor WhatsApp.');
        }

        // If deleting primary, set another as primary
        if ($userWhatsAppNumber->is_primary) {
            $otherNumber = UserWhatsAppNumber::where('user_id', $user->id)
                ->where('tenant_id', $tenant->id)
                ->where('id', '!=', $userWhatsAppNumber->id)
                ->where('is_active', true)
                ->first();
            
            if ($otherNumber) {
                $otherNumber->update(['is_primary' => true]);
            }
        }

        // Soft delete (set is_active = false)
        $userWhatsAppNumber->update(['is_active' => false]);

        Log::info('User WhatsApp number deleted', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'number_id' => $userWhatsAppNumber->id,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect()->route('whatsapp.index', [
                'tenant_id' => $tenant->id
            ])->with('success', 'Nomor WhatsApp berhasil dihapus');
        }

        return redirect()->back()->with('success', 'Nomor WhatsApp berhasil dihapus');
    }

    /**
     * Set primary WhatsApp number
     */
    public function setPrimaryNumber(Request $request, UserWhatsAppNumber $userWhatsAppNumber)
    {
        $tenant = Tenant::findOrFail($request->tenant_id);
        $user = $request->user();

        // Verify ownership
        if ($userWhatsAppNumber->user_id !== $user->id || $userWhatsAppNumber->tenant_id !== $tenant->id) {
            abort(403, 'Unauthorized');
        }

        // Unset all primary
        UserWhatsAppNumber::where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->update(['is_primary' => false]);

        // Set this as primary
        $userWhatsAppNumber->update(['is_primary' => true]);

        Log::info('User WhatsApp number set as primary', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'number_id' => $userWhatsAppNumber->id,
        ]);

        if ($request->header('X-Inertia')) {
            return redirect()->route('whatsapp.index', [
                'tenant_id' => $tenant->id
            ])->with('success', 'Nomor utama berhasil diubah');
        }

        return redirect()->back()->with('success', 'Nomor utama berhasil diubah');
    }
}

