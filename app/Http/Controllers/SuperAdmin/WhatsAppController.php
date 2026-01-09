<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    protected $whatsappService;
    protected $systemTenantId = 1; // Tenant sistem untuk super admin

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * List all WhatsApp channels from all tenants (super admin view)
     */
    public function index(Request $request): Response
    {
        // Get newChannelId from session flash data (set by store method) or query parameter
        $newChannelId = session('newChannelId') ?? $request->query('newChannelId');
        
        // Clear session flash data after reading
        if (session('newChannelId')) {
            session()->forget('newChannelId');
        }

        // Filter by tenant if provided
        $tenantFilter = $request->query('tenant_id');
        
        // Daftar nomor admin yang tidak boleh ditampilkan di halaman SuperAdmin
        $adminNumbers = [
            '6285242766676', // Super admin number
        ];
        
        $query = Channel::where('type', 'whatsapp')
            ->whereNotIn('channel_account', $adminNumbers) // Jangan tampilkan nomor admin
            ->with(['tenant', 'messages' => function ($query) {
                $query->orderBy('created_at', 'desc')->limit(20);
            }]);
        
        if ($tenantFilter) {
            $query->where('tenant_id', $tenantFilter);
        }
        
        $channels = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($channel) {
                $config = $channel->config ?? [];
                $sessionId = $config['session_id'] ?? null;
                
                // Get session status from gateway
                $sessionStatus = $config['session_status'] ?? null;
                if ($sessionId) {
                    try {
                        $statusResult = $this->whatsappService->getSessionStatus($sessionId);
                        if ($statusResult['success']) {
                            // Handle different response formats
                            $responseData = $statusResult['data'] ?? [];
                            $sessionStatus = $responseData['status'] ?? $responseData['data']['status'] ?? $statusResult['status'] ?? 'unknown';
                            
                            // Normalize status (handle case variations)
                            $sessionStatus = strtolower($sessionStatus);
                            if ($sessionStatus === 'connected' || $sessionStatus === 'authenticated') {
                                $sessionStatus = 'connected';
                            }
                            
                            // Update config with latest status
                            $config['session_status'] = $sessionStatus;
                            $channel->update(['config' => $config]);
                        } else {
                            // If status check fails, keep existing status or set to error
                            $sessionStatus = $sessionStatus ?? 'error';
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to get session status', [
                            'channel_id' => $channel->id,
                            'error' => $e->getMessage()
                        ]);
                        // Keep existing status if available, otherwise set to error
                        $sessionStatus = $sessionStatus ?? 'error';
                    }
                } else {
                    $sessionStatus = $sessionStatus ?? null;
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
                    'is_shared_channel' => $channel->is_shared_channel ?? false,
                    'is_active' => $channel->is_active,
                    'session_id' => $sessionId,
                    'session_status' => $sessionStatus,
                    'last_activity_at' => $channel->last_activity_at?->format('Y-m-d H:i:s'),
                    'messages_count' => $channel->messages->count(),
                    'recent_messages' => $recentMessages,
                    'created_at' => $channel->created_at->format('Y-m-d H:i:s'),
                    'tenant' => $channel->tenant ? [
                        'id' => $channel->tenant->id,
                        'name' => $channel->tenant->name,
                    ] : null,
                ];
            });
        
        // Check engine status
        $engineStatus = $this->whatsappService->getEngineStatus();

        // Get all tenants for filter
        $tenants = Tenant::select('id', 'name')
            ->orderBy('name')
            ->get();

        return Inertia::render('SuperAdmin/WhatsApp/Index', [
            'channels' => $channels,
            'engineStatus' => $engineStatus,
            'newChannelId' => $newChannelId ? (int) $newChannelId : null,
            'tenants' => $tenants,
            'selectedTenantId' => $tenantFilter ? (int) $tenantFilter : null,
        ]);
    }

    /**
     * Create new WhatsApp channel for system tenant (super admin)
     */
    public function store(Request $request)
    {
        // Debug logging
        Log::info('WhatsAppController@store called', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'user_id' => $request->user()?->id,
            'is_super_admin' => $request->user()?->is_super_admin ?? false,
            'input_data' => $request->all(),
        ]);
        
        try {
            $request->validate([
                'channel_account' => 'required|string|regex:/^[0-9]+$/',
                'name' => 'nullable|string|max:255',
                'tenant_id' => 'nullable|integer|exists:tenants,id',
                'is_shared_channel' => 'nullable|boolean',
            ]);
            Log::info('Validation passed');
        } catch (ValidationException $e) {
            Log::error('Validation failed', [
                'errors' => $e->errors(),
            ]);
            throw $e;
        }

        // Use system tenant (tenant_id = 1) for super admin channels, or specified tenant_id
        // Handle null or empty tenant_id - use system tenant as default
        $tenantIdInput = $request->input('tenant_id');
        $tenantId = ($tenantIdInput !== null && $tenantIdInput !== '' && $tenantIdInput !== '0') 
            ? (int) $tenantIdInput 
            : $this->systemTenantId;
        Log::info('Getting tenant', [
            'tenant_id_input' => $tenantIdInput,
            'tenant_id_used' => $tenantId,
            'system_tenant_id' => $this->systemTenantId,
        ]);
        
        try {
            $tenant = Tenant::findOrFail($tenantId);
            Log::info('Tenant found', ['tenant_id' => $tenant->id, 'tenant_name' => $tenant->name]);
        } catch (\Exception $e) {
            Log::error('Tenant not found', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Clean phone number (remove +, spaces, etc)
        $phoneNumber = preg_replace('/[^0-9]/', '', $request->channel_account);
        
        // Check if channel already exists
        // $existingChannel = Channel::where('tenant_id', $tenantId)
        //     ->where('type', 'whatsapp')
        //     ->where('channel_account', $phoneNumber)
        //     ->first();

        // if ($existingChannel) {
        //     if ($request->header('X-Inertia')) {
        //         return back()->withErrors([
        //             'channel_account' => 'Channel dengan nomor ini sudah ada'
        //         ])->with('error', 'Channel dengan nomor ini sudah ada');
        //     }
        //     return redirect()->back()->with('error', 'Channel dengan nomor ini sudah ada');
        // }

        try {
            // Create session in WhatsApp Gateway
            Log::info('Creating WhatsApp session (Super Admin)', [
                'tenant_id' => $tenantId,
                'phone_number' => $phoneNumber
            ]);
            
            $sessionResult = $this->whatsappService->createSession($tenantId, $phoneNumber, $phoneNumber);

            if (!$sessionResult['success']) {
                $errorMessage = $sessionResult['error'] ?? 'Unknown error';
                $statusCode = $sessionResult['status_code'] ?? null;
                
                Log::error('Failed to create WhatsApp session (Super Admin)', [
                    'tenant_id' => $tenantId,
                    'phone_number' => $phoneNumber,
                    'error' => $errorMessage,
                    'status_code' => $statusCode,
                ]);
                
                if ($request->header('X-Inertia')) {
                    return back()->withErrors([
                        'session' => $errorMessage,
                        'channel_account' => $errorMessage
                    ])->with('error', $errorMessage);
                }
                
                return redirect()->back()->with('error', 'Gagal membuat session: ' . $errorMessage);
            }

            // Generate session ID manually (format: wa_{tenantId}_{channelAccount})
            $sessionId = $sessionResult['sessionId'] ?? "wa_{$tenantId}_{$phoneNumber}";
            
            Log::info('WhatsApp session created successfully (Super Admin)', [
                'tenant_id' => $tenantId,
                'phone_number' => $phoneNumber,
                'session_id' => $sessionId,
            ]);

            // Check if channel already exists, update instead of create
            $channel = Channel::where('tenant_id', $tenantId)
                ->where('type', 'whatsapp')
                ->where('channel_account', $phoneNumber)
                ->first();

            if ($channel) {
                // Update existing channel
                $config = $channel->config ?? [];
                $config['session_id'] = $sessionId;
                $config['engine_url'] = config('services.whatsapp.engine_url');
                $updateData = [
                    'is_active' => true,
                    'config' => $config
                ];
                
                // Update is_shared_channel if provided
                if ($request->has('is_shared_channel')) {
                    $updateData['is_shared_channel'] = $request->boolean('is_shared_channel');
                }
                
                $channel->update($updateData);
                Log::info('Channel updated (Super Admin)', ['channel_id' => $channel->id]);
            } else {
                // Create channel in database
                $channel = Channel::create([
                    'tenant_id' => $tenantId,
                    'type' => 'whatsapp',
                    'name' => $request->name ?? 'WhatsApp: ' . $phoneNumber,
                    'channel_account' => $phoneNumber,
                    'is_active' => true,
                    'is_shared_channel' => $request->boolean('is_shared_channel', false),
                    'config' => [
                        'session_id' => $sessionId,
                        'engine_url' => config('services.whatsapp.engine_url'),
                    ],
                ]);
            }

            // For Inertia requests, redirect to index method
            if ($request->header('X-Inertia')) {
                Log::info('Preparing Inertia redirect', [
                    'channel_id' => $channel->id,
                    'route_name' => 'superadmin.whatsapp.index',
                ]);
                
                // Store newChannelId in session flash data
                session()->flash('newChannelId', $channel->id);
                
                // Redirect to index - index method will read from session and pass to Inertia
                $redirectUrl = route('superadmin.whatsapp.index');
                Log::info('Redirecting to', ['url' => $redirectUrl]);
                
                return redirect()->route('superadmin.whatsapp.index')
                    ->with('success', 'Channel WhatsApp berhasil dibuat. QR code akan muncul otomatis.');
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
            Log::error('Failed to create WhatsApp channel (Super Admin)', [
                'tenant_id' => $tenantId,
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
        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'error' => 'Session ID not found. Please reconnect the channel.'
            ], 404);
        }

        try {
            Log::info('Getting QR code for channel (Super Admin)', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId
            ]);

            // Check session status first - if connected, don't return QR
            $statusResult = $this->whatsappService->getSessionStatus($sessionId);
            if ($statusResult['success']) {
                $status = $statusResult['status'] ?? $statusResult['data']['status'] ?? 'unknown';
                Log::info('Session status check (Super Admin)', [
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
            
            Log::info('QR code result (Super Admin)', [
                'success' => $qrResult['success'] ?? false,
                'has_data' => isset($qrResult['data']),
                'error' => $qrResult['error'] ?? null
            ]);
            
            // If QR not ready yet, return helpful message
            if (!$qrResult['success']) {
                $statusCode = $qrResult['status_code'] ?? 404;
                $status = $qrResult['status'] ?? 'unknown';
                
                Log::warning('QR code not available (Super Admin)', [
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
                ], 404);
            }
            
            // Parse QR code data - handle different response formats
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
            
            Log::info('QR code parsed (Super Admin)', [
                'has_qrCode' => !empty($qrCode),
                'qrCode_length' => $qrCode ? strlen($qrCode) : 0,
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
            if (!str_starts_with($qrCode, 'data:image') && !str_starts_with($qrCode, '<svg') && !str_starts_with($qrCode, 'http')) {
                // If it's base64 without prefix, add it
                $qrCode = 'data:image/png;base64,' . $qrCode;
            }
            
            $status = $qrData['data']['status'] ?? $qrData['status'] ?? 'ready';
            
            Log::info('QR code ready to return (Super Admin)', [
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
            Log::error('Error getting QR code (Super Admin)', [
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
                
                Log::info('Session reconnected (Super Admin)', [
                    'channel_id' => $channel->id,
                    'session_id' => $sessionId,
                    'status' => $status
                ]);
                
                return redirect()->back()->with('success', 'Session berhasil di-reconnect');
            }

            return redirect()->back()->with('error', 'Gagal reconnect: ' . ($result['error'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            Log::error('Error reconnecting session (Super Admin)', [
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
                Log::info('All WhatsApp sessions deleted (Super Admin)', [
                    'result' => $result
                ]);
                
                return redirect()->back()->with('success', $result['message'] ?? 'Semua session WhatsApp berhasil dihapus.');
            }
            
            return redirect()->back()->with('error', 'Gagal menghapus semua session: ' . ($result['error'] ?? 'Unknown error'));
        } catch (\Exception $e) {
            Log::error('Failed to delete all WhatsApp sessions (Super Admin)', [
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
        $config = $channel->config ?? [];
        $sessionId = $config['session_id'] ?? null;

        try {
            // Delete session from engine first (before deleting channel)
            if ($sessionId) {
                Log::info('Deleting WhatsApp session (Super Admin)', [
                    'channel_id' => $channel->id,
                    'session_id' => $sessionId
                ]);
                
                $deleteResult = $this->whatsappService->deleteSession($sessionId);
                
                if (!$deleteResult['success']) {
                    Log::warning('Failed to delete session from gateway, continuing with channel deletion (Super Admin)', [
                        'channel_id' => $channel->id,
                        'session_id' => $sessionId,
                        'error' => $deleteResult['error'] ?? 'Unknown error'
                    ]);
                    // Continue with channel deletion even if session deletion fails
                } else {
                    Log::info('Session deleted successfully (Super Admin)', [
                        'channel_id' => $channel->id,
                        'session_id' => $sessionId
                    ]);
                }
            }

            // Delete channel from database
            $channel->delete();
            
            Log::info('Channel deleted successfully (Super Admin)', [
                'channel_id' => $channel->id,
                'session_id' => $sessionId
            ]);

            // For Inertia requests
            if ($request->header('X-Inertia')) {
                return redirect()->route('superadmin.whatsapp.index')
                    ->with('success', 'Channel berhasil dihapus');
            }

            return redirect()->route('superadmin.whatsapp.index')
                ->with('success', 'Channel berhasil dihapus');
        } catch (\Exception $e) {
            Log::error('Failed to delete WhatsApp channel (Super Admin)', [
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

}
