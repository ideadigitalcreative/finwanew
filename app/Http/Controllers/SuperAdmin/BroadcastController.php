<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\UserWhatsAppNumber;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BroadcastController extends Controller
{
    protected $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Display broadcast page
     */
    public function index(): Response
    {
        // Get all registered WhatsApp numbers
        $whatsappNumbers = UserWhatsAppNumber::with(['user', 'tenant'])
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($number) {
                return [
                    'id' => $number->id,
                    'phone_number' => $number->whatsapp_number,
                    'mapping_name' => $number->name, // Original label from mapping table
                    'user_name' => $number->user->name ?? 'Unknown',
                    'user_email' => $number->user->email ?? '-',
                    'tenant_name' => $number->tenant->name ?? '-',
                    'is_primary' => $number->is_primary,
                    'created_at' => $number->created_at->format('Y-m-d H:i'),
                ];
            });

        // Get ALL active WhatsApp channels and find one with valid session
        $allChannels = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->get();
        
        Log::info('Broadcast: Looking for active WhatsApp channels', [
            'total_found' => $allChannels->count(),
            'channels' => $allChannels->map(fn($c) => [
                'id' => $c->id,
                'account' => $c->channel_account,
                'config' => $c->config,
            ])->toArray()
        ]);
        
        // Find channel with session_id - prioritize ones with 'connected' status in config
        $superAdminChannel = null;
        $fallbackChannel = null;
        
        foreach ($allChannels as $channel) {
            $config = $channel->config;
            if (is_array($config) && !empty($config['session_id'])) {
                // Check if this channel has connected status in config
                $statusInConfig = strtolower($config['session_status'] ?? $config['last_status'] ?? '');
                if (in_array($statusInConfig, ['connected', 'authenticated'])) {
                    // This channel is marked as connected - use it
                    $superAdminChannel = $channel;
                    break;
                }
                // Keep as fallback if no connected channel found
                if (!$fallbackChannel) {
                    $fallbackChannel = $channel;
                }
            }
        }
        
        // If no connected channel found, use fallback
        if (!$superAdminChannel && $fallbackChannel) {
            $superAdminChannel = $fallbackChannel;
        }

        $sessionStatus = null;
        if ($superAdminChannel) {
            $sessionId = $superAdminChannel->config['session_id'];
            Log::info('Broadcast: Found channel with session', [
                'channel_id' => $superAdminChannel->id,
                'session_id' => $sessionId,
            ]);
            
            // Try to get status from API, but assume connected if channel is active
            try {
                $statusResult = $this->whatsAppService->getSessionStatus($sessionId);
                $isConnected = $statusResult['success'] && in_array(strtolower($statusResult['status'] ?? ''), ['connected', 'authenticated']);
                $sessionStatus = [
                    'session_id' => $sessionId,
                    'is_connected' => $isConnected,
                    'status' => $statusResult['status'] ?? 'unknown',
                ];
            } catch (\Exception $e) {
                Log::warning('Broadcast: Could not get WhatsApp status from API', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage()
                ]);
                // Assume connected if channel is active and has session
                $sessionStatus = [
                    'session_id' => $sessionId,
                    'is_connected' => true,
                    'status' => 'connected (from channel)',
                ];
            }
        } else {
            Log::warning('Broadcast: No channel with session_id found');
        }

        return Inertia::render('SuperAdmin/Broadcast/Index', [
            'whatsappNumbers' => $whatsappNumbers,
            'sessionStatus' => $sessionStatus,
            'stats' => [
                'total_numbers' => $whatsappNumbers->count(),
                'primary_numbers' => $whatsappNumbers->where('is_primary', true)->count(),
            ],
        ]);
    }

    /**
     * Send broadcast message to all or selected numbers
     */
    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'recipients' => 'required|array|min:1',
            'recipients.*' => 'string',
        ]);

        $message = $request->input('message');
        $recipients = $request->input('recipients');

        // Get active WhatsApp session - use same logic as index
        $allChannels = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->get();
        
        // Find channel with session_id - prioritize ones with 'connected' status
        $channel = null;
        $fallbackChannel = null;
        
        foreach ($allChannels as $ch) {
            $config = $ch->config;
            if (is_array($config) && !empty($config['session_id'])) {
                $statusInConfig = strtolower($config['session_status'] ?? $config['last_status'] ?? '');
                if (in_array($statusInConfig, ['connected', 'authenticated'])) {
                    $channel = $ch;
                    break;
                }
                if (!$fallbackChannel) {
                    $fallbackChannel = $ch;
                }
            }
        }
        
        if (!$channel && $fallbackChannel) {
            $channel = $fallbackChannel;
        }

        if (!$channel) {
            return response()->json([
                'success' => false,
                'error' => 'Tidak ada session WhatsApp yang aktif. Silakan setup WhatsApp terlebih dahulu.',
            ], 400);
        }

        $sessionId = $channel->config['session_id'] ?? null;
        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'error' => 'Session ID tidak ditemukan.',
            ], 400);
        }
        
        Log::info('Broadcast: Using session for sending', [
            'channel_id' => $channel->id,
            'session_id' => $sessionId,
            'recipients_count' => count($recipients),
        ]);

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($recipients as $phoneNumber) {
            try {
                // Add delay between messages to avoid being blocked
                if (count($results['success']) > 0 || count($results['failed']) > 0) {
                    usleep(500000); // 500ms delay
                }

                $result = $this->whatsAppService->sendMessage($sessionId, $phoneNumber, $message);

                if ($result['success']) {
                    $results['success'][] = $phoneNumber;
                    Log::info('Broadcast message sent', [
                        'phone_number' => $phoneNumber,
                        'session_id' => $sessionId,
                    ]);
                    
                    // Extract LID from messageId if available
                    // Format: "true_{LID}@lid_{uniqueId}" or "true_{LID}_..."
                    // Response structure: $result['data']['data']['messageId']
                    $responseData = $result['data']['data'] ?? $result['data'] ?? [];
                    $messageId = $responseData['messageId'] ?? null;
                    if ($messageId && preg_match('/true_(\d+)@lid/', $messageId, $matches)) {
                        $discoveredLid = $matches[1];
                        
                        // Find user by phone number and create LID mapping
                        $existingNumber = UserWhatsAppNumber::where('whatsapp_number', $phoneNumber)
                            ->where('is_active', true)
                            ->first();
                        
                        if ($existingNumber) {
                            // Check if LID already registered
                            $lidExists = UserWhatsAppNumber::where('whatsapp_number', $discoveredLid)
                                ->where('user_id', $existingNumber->user_id)
                                ->where('tenant_id', $existingNumber->tenant_id)
                                ->exists();
                            
                            if (!$lidExists) {
                                UserWhatsAppNumber::create([
                                    'user_id' => $existingNumber->user_id,
                                    'tenant_id' => $existingNumber->tenant_id,
                                    'whatsapp_number' => $discoveredLid,
                                    'name' => 'LID - ' . $phoneNumber,
                                    'is_primary' => false,
                                    'is_active' => true,
                                    'is_lid' => true
                                ]);
                                
                                Log::info('LID mapping created from broadcast', [
                                    'lid' => $discoveredLid,
                                    'phone_number' => $phoneNumber,
                                    'tenant_id' => $existingNumber->tenant_id
                                ]);
                            }
                        }
                    }
                } else {
                    $results['failed'][] = [
                        'number' => $phoneNumber,
                        'error' => $result['error'] ?? 'Unknown error',
                    ];
                    Log::warning('Broadcast message failed', [
                        'phone_number' => $phoneNumber,
                        'error' => $result['error'] ?? 'Unknown error',
                    ]);
                }
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'number' => $phoneNumber,
                    'error' => $e->getMessage(),
                ];
                Log::error('Broadcast message exception', [
                    'phone_number' => $phoneNumber,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => sprintf(
                'Broadcast selesai. %d berhasil, %d gagal.',
                count($results['success']),
                count($results['failed'])
            ),
            'results' => $results,
        ]);
    }

    /**
     * Send message to single number
     */
    public function sendSingle(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'phone_number' => 'required|string',
        ]);

        $message = $request->input('message');
        $phoneNumber = $request->input('phone_number');

        // Get active WhatsApp session - use same logic as index/send
        $allChannels = Channel::where('type', 'whatsapp')
            ->where('is_active', true)
            ->get();
        
        $channel = null;
        $fallbackChannel = null;
        
        foreach ($allChannels as $ch) {
            $config = $ch->config;
            if (is_array($config) && !empty($config['session_id'])) {
                $statusInConfig = strtolower($config['session_status'] ?? $config['last_status'] ?? '');
                if (in_array($statusInConfig, ['connected', 'authenticated'])) {
                    $channel = $ch;
                    break;
                }
                if (!$fallbackChannel) {
                    $fallbackChannel = $ch;
                }
            }
        }
        
        if (!$channel && $fallbackChannel) {
            $channel = $fallbackChannel;
        }

        if (!$channel) {
            return response()->json([
                'success' => false,
                'error' => 'Tidak ada session WhatsApp yang aktif.',
            ], 400);
        }

        $sessionId = $channel->config['session_id'] ?? null;
        if (!$sessionId) {
            return response()->json([
                'success' => false,
                'error' => 'Session ID tidak ditemukan.',
            ], 400);
        }

        try {
            $result = $this->whatsAppService->sendMessage($sessionId, $phoneNumber, $message);

            if ($result['success']) {
                Log::info('Single message sent by super admin', [
                    'phone_number' => $phoneNumber,
                    'session_id' => $sessionId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pesan berhasil dikirim ke ' . $phoneNumber,
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Gagal mengirim pesan',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Single message exception', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get message templates
     */
    public function getTemplates()
    {
        $templates = [
            [
                'id' => 'update_fitur',
                'name' => '🚀 Update Fitur Baru',
                'message' => "🚀 *Update Fitur Baru FinWa!*\n\n" .
                    "Halo! Ada fitur baru yang siap Anda coba:\n\n" .
                    "✨ [Nama Fitur]\n" .
                    "[Deskripsi singkat fitur]\n\n" .
                    "Cara menggunakan:\n" .
                    "• [Langkah 1]\n" .
                    "• [Langkah 2]\n\n" .
                    "Ketik *help* untuk panduan lengkap.\n\n" .
                    "Terima kasih telah menggunakan FinWa! 💙",
            ],
            [
                'id' => 'maintenance',
                'name' => '🔧 Pemberitahuan Maintenance',
                'message' => "🔧 *Pemberitahuan Maintenance*\n\n" .
                    "Halo! Kami akan melakukan maintenance sistem pada:\n\n" .
                    "📅 Tanggal: [Tanggal]\n" .
                    "⏰ Waktu: [Waktu] WIB\n" .
                    "⏱️ Durasi: ± [X] jam\n\n" .
                    "Selama maintenance, layanan mungkin tidak tersedia.\n\n" .
                    "Mohon maaf atas ketidaknyamanannya.\n" .
                    "Terima kasih atas pengertiannya! 🙏",
            ],
            [
                'id' => 'promo',
                'name' => '🎉 Promo Spesial',
                'message' => "🎉 *Promo Spesial FinWa!*\n\n" .
                    "Halo! Spesial untuk Anda:\n\n" .
                    "🎁 *[Nama Promo]*\n" .
                    "[Deskripsi promo]\n\n" .
                    "🗓️ Berlaku: [Tanggal mulai] - [Tanggal akhir]\n" .
                    "🔗 Info: https://finwa.web.id/promo\n\n" .
                    "Jangan sampai terlewat! 🚀",
            ],
            [
                'id' => 'tips',
                'name' => '💡 Tips Keuangan',
                'message' => "💡 *Tips Keuangan FinWa*\n\n" .
                    "Halo! Ini tips keuangan mingguan:\n\n" .
                    "*[Judul Tips]*\n\n" .
                    "[Isi tips]\n\n" .
                    "💰 Sudah catat pengeluaran hari ini?\n" .
                    "Ketik langsung: _makan siang 25rb_\n\n" .
                    "Semangat mengatur keuangan! 🚀",
            ],
            [
                'id' => 'reminder',
                'name' => '⏰ Pengingat',
                'message' => "⏰ *Pengingat dari FinWa*\n\n" .
                    "Halo! Jangan lupa:\n\n" .
                    "📝 [Isi pengingat]\n\n" .
                    "Semoga membantu! 😊",
            ],
        ];

        return response()->json([
            'success' => true,
            'templates' => $templates,
        ]);
    }
}
