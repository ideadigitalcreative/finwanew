<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserLidMapping;
use App\Models\UserWhatsAppNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $engineUrl;

    protected $apiKey;

    protected $coreApiUrl;

    public function __construct()
    {
        $this->engineUrl = config('services.whatsapp.engine_url', 'http://localhost:3001');
        $this->apiKey = config('services.whatsapp.api_key');
        $this->coreApiUrl = config('services.whatsapp.core_api_url', 'http://localhost:8000');
    }

    /**
     * Get engine status (from WhatsApp Gateway)
     */
    public function getEngineStatus(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("{$this->engineUrl}/health");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => 'running',
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'status' => 'stopped',
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create WhatsApp session (using wa-blast engine)
     */
    public function createSession(int $tenantId, string $channelAccount, ?string $phoneNumber = null): array
    {
        try {
            $sessionId = $this->generateSessionId($tenantId, $channelAccount);

            // Check if session already exists and handle accordingly
            try {
                $existingStatus = $this->getSessionStatus($sessionId);
                if ($existingStatus['success']) {
                    $status = $existingStatus['status'] ?? 'unknown';
                    Log::info('Checking existing session status before create', [
                        'session_id' => $sessionId,
                        'status' => $status,
                    ]);

                    // If session exists and is connected/authenticated, return success
                    if (in_array($status, ['connected', 'authenticated'])) {
                        Log::info('Session already exists and connected', [
                            'session_id' => $sessionId,
                            'status' => $status,
                        ]);

                        return [
                            'success' => true,
                            'data' => $existingStatus['data'] ?? [],
                            'sessionId' => $sessionId,
                            'message' => 'Session already exists and connected',
                        ];
                    }

                    // If session exists with error or disconnected status, delete it first
                    if (in_array($status, ['error', 'disconnected'])) {
                        Log::info('Session exists with error/disconnected status, deleting before recreate', [
                            'session_id' => $sessionId,
                            'status' => $status,
                        ]);

                        // Delete session first
                        $deleteResult = $this->deleteSession($sessionId);
                        if ($deleteResult['success']) {
                            Log::info('Session deleted successfully, will create new one', [
                                'session_id' => $sessionId,
                            ]);
                            // Wait a moment for cleanup
                            sleep(1);
                        } else {
                            Log::warning('Failed to delete existing session, will try to create anyway', [
                                'session_id' => $sessionId,
                                'error' => $deleteResult['error'] ?? 'Unknown error',
                            ]);
                        }
                    }
                    // If status is loading/initializing/reconnecting, wait a bit and check again
                    elseif (in_array($status, ['loading', 'initializing', 'reconnecting'])) {
                        Log::info('Session is already being initialized, returning existing status', [
                            'session_id' => $sessionId,
                            'status' => $status,
                        ]);

                        return [
                            'success' => true,
                            'data' => $existingStatus['data'] ?? [],
                            'sessionId' => $sessionId,
                            'message' => 'Session is already being initialized',
                        ];
                    }
                } else {
                    // Session doesn't exist or status check failed, will create new one
                    Log::info('Session does not exist or status check failed, will create new session', [
                        'session_id' => $sessionId,
                        'status_check_error' => $existingStatus['error'] ?? 'Unknown error',
                    ]);
                }
            } catch (\Exception $e) {
                // If status check fails, continue with create session anyway
                Log::warning('Error checking existing session status, will create new session', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }

            $response = Http::timeout(30)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions", [
                    'sessionId' => $sessionId,
                    'tenantId' => $tenantId,
                    'phoneNumber' => $phoneNumber ?? $channelAccount,
                ]);

            if ($response->successful()) {
                // Store session mapping in channel config
                $this->storeSessionMapping($tenantId, $channelAccount, $sessionId);

                return [
                    'success' => true,
                    'data' => $response->json(),
                    'sessionId' => $sessionId,
                ];
            }

            // Parse error response
            $errorBody = $response->body();
            $errorData = null;
            try {
                $errorData = json_decode($errorBody, true);
            } catch (\Exception $e) {
                // If JSON decode fails, use raw body
            }

            $errorMessage = $errorData['error'] ?? $errorBody ?? 'HTTP '.$response->status();

            // Log detailed error for debugging
            Log::error('WhatsApp engine returned error', [
                'status' => $response->status(),
                'body' => $errorBody,
                'parsed_error' => $errorData,
                'error_message' => $errorMessage,
                'engine_url' => $this->engineUrl,
                'api_key_set' => ! empty($this->apiKey),
                'api_key_length' => strlen($this->apiKey ?? ''),
            ]);

            // Provide user-friendly error messages
            if ($response->status() === 401) {
                $errorMessage = 'Unauthorized: API key tidak cocok. Pastikan API key di .env (WHATSAPP_ENGINE_API_KEY) sama dengan API key di WhatsApp Gateway (.env file di services/whatsapp-gateway).';
            } elseif ($response->status() === 0 || $response->status() === null) {
                $errorMessage = 'Tidak bisa terhubung ke WhatsApp Gateway di '.$this->engineUrl.'. Pastikan gateway berjalan (pm2 status whatsapp-gateway).';
            }

            return [
                'success' => false,
                'error' => $errorMessage,
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Error creating WhatsApp session', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get QR code for session (from wa-blast)
     */
    public function getQrCode(string $sessionId, bool $isRetry = false): array
    {
        try {
            Log::info('Getting QR code from wa-blast engine', [
                'session_id' => $sessionId,
                'engine_url' => $this->engineUrl,
                'endpoint' => "{$this->engineUrl}/sessions/{$sessionId}/qr",
            ]);

            $response = Http::timeout(30) // Increase timeout untuk QR generation
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("{$this->engineUrl}/sessions/{$sessionId}/qr");

            Log::info('QR code response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('QR code data received', [
                    'response_keys' => array_keys($data),
                    'has_data' => isset($data['data']),
                    'has_qrCode' => isset($data['data']['qrCode']),
                    'has_qr' => isset($data['data']['qr']),
                    'status' => $data['data']['status'] ?? $data['status'] ?? 'unknown',
                ]);

                // Check if response indicates QR not ready (202 status but successful HTTP)
                if (isset($data['success']) && $data['success'] === false) {
                    // Check if should_reconnect is true (only on first attempt, not retry)
                    if (! $isRetry && isset($data['should_reconnect']) && $data['should_reconnect'] === true) {
                        Log::info('Auto-reconnecting session due to should_reconnect flag', [
                            'session_id' => $sessionId,
                        ]);

                        // Try reconnect with fresh flag (delete session file and recreate)
                        $reconnectResult = $this->reconnectSession($sessionId, true); // true = fresh

                        if ($reconnectResult['success']) {
                            Log::info('Session reconnected with fresh flag, waiting for initialization', [
                                'session_id' => $sessionId,
                                'reconnect_data' => $reconnectResult,
                            ]);

                            // Wait longer for session to initialize and QR code generation
                            sleep(8);

                            // Retry getting QR code (with isRetry flag to prevent infinite loop)
                            return $this->getQrCode($sessionId, true);
                        } else {
                            Log::warning('Failed to reconnect session with fresh flag', [
                                'session_id' => $sessionId,
                                'error' => $reconnectResult['error'] ?? 'Unknown error',
                            ]);
                        }
                    }

                    return [
                        'success' => false,
                        'error' => $data['error'] ?? 'QR code not ready',
                        'status' => $data['status'] ?? 'unknown',
                        'status_code' => 202,
                        'should_reconnect' => $data['should_reconnect'] ?? false,
                    ];
                }

                return [
                    'success' => true,
                    'data' => $data,
                ];
            }

            // Handle 202 Accepted (QR not ready yet)
            if ($response->status() === 202) {
                $data = $response->json();

                return [
                    'success' => false,
                    'error' => $data['error'] ?? 'QR code not ready yet',
                    'status' => $data['status'] ?? 'unknown',
                    'status_code' => 202,
                ];
            }

            $errorBody = $response->body();
            Log::error('QR code request failed', [
                'status' => $response->status(),
                'error' => $errorBody,
            ]);

            return [
                'success' => false,
                'error' => $errorBody ?: 'HTTP '.$response->status(),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Exception getting QR code', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get session status (from WhatsApp Gateway)
     */
    public function getSessionStatus(string $sessionId): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->get("{$this->engineUrl}/sessions/{$sessionId}/status");

            if ($response->successful()) {
                $data = $response->json();
                // Extract status from different possible response formats
                $status = $data['data']['status'] ?? $data['status'] ?? 'unknown';

                return [
                    'success' => true,
                    'data' => $data,
                    'status' => $status,
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reconnect session (from WhatsApp Gateway)
     * This will load existing session from LocalAuth if available
     */
    public function reconnectSession(string $sessionId, bool $fresh = false): array
    {
        try {
            $query = $fresh ? '?fresh=1' : '';
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions/{$sessionId}/reconnect{$query}");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'data' => $data,
                    'status' => $data['data']['status'] ?? 'reconnecting',
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send message (using WhatsApp Gateway)
     *
     * @param  string  $sessionId  Session ID
     * @param  string  $toNumber  Phone number to send to
     * @param  string  $message  Message content
     * @param  string  $type  Message type (text, image, etc)
     * @param  string|null  $originalLid  Original LID address for fallback
     * @param  bool  $simulateTyping  Whether to show typing indicator before sending (default: true)
     * @param  int|null  $typingDuration  Custom typing duration in milliseconds
     */
    public function sendMessage(string $sessionId, string $toNumber, string $message, string $type = 'text', ?string $originalLid = null, bool $simulateTyping = true, ?int $typingDuration = null): array
    {
        try {
            $cleanedNumber = $this->cleanPhoneNumber($toNumber);

            Log::info('Sending WhatsApp message', [
                'session_id' => $sessionId,
                'to_number' => $toNumber,
                'cleaned_number' => $cleanedNumber,
                'original_lid' => $originalLid,
                'message_length' => strlen($message),
                'type' => $type,
                'simulate_typing' => $simulateTyping,
                'engine_url' => $this->engineUrl,
            ]);

            $payload = [
                'to' => $cleanedNumber,
                'message' => $message,
                'type' => $type,
                'simulateTyping' => $simulateTyping,
            ];

            // Add typing duration if specified
            if ($typingDuration !== null) {
                $payload['typingDuration'] = $typingDuration;
            }

            // Add originalLid if provided (for LID-based fallback)
            if ($originalLid) {
                $payload['originalLid'] = $originalLid;
            }

            $response = Http::timeout(45)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions/{$sessionId}/send", $payload);

            $responseData = $response->json() ?? [];
            Log::info('WhatsApp message response', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                $this->logAndSaveLidFromSendResponse($sessionId, $cleanedNumber, $responseData);

                return [
                    'success' => true,
                    'data' => $responseData,
                ];
            }

            Log::warning('WhatsApp message failed', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp message', [
                'session_id' => $sessionId,
                'to_number' => $toNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dari respons gateway saat kirim pesan: ekstrak LID (resultJid mengandung @lid) lalu log
     * dan simpan ke user_lid_mappings jika nomor terdaftar. Hanya proses jika benar-benar LID
     * (bukan @s.whatsapp.net / nomor biasa).
     */
    protected function logAndSaveLidFromSendResponse(string $sessionId, string $toNumber, array $responseData): void
    {
        $lid = null;
        foreach (['resultJid', 'jid', 'lid', 'originalTo', 'to'] as $key) {
            $val = $responseData[$key] ?? null;
            if (is_string($val) && str_contains(strtolower($val), '@lid')) {
                $cleaned = str_replace('@lid', '', trim($val));
                $cleaned = preg_replace('/[^0-9]/', '', $cleaned);
                if (strlen($cleaned) >= 10 && ! preg_match('/^628[0-9]{8,13}$/', $cleaned)) {
                    $lid = $cleaned;
                    break;
                }
            }
        }
        if ($lid) {
            Log::channel('single')->info('WA LID terdeteksi saat aplikasi kirim pesan', [
                'to_number' => $toNumber,
                'lid' => $lid,
                'session_id' => $sessionId,
                'response_keys' => array_keys($responseData),
            ]);
            if (preg_match('/^628[0-9]{8,13}$/', $toNumber)) {
                $user = User::where('whatsapp_number', $toNumber)->first();
                if (! $user) {
                    $m = UserWhatsAppNumber::where('whatsapp_number', $toNumber)->where('is_active', true)->first();
                    $user = $m ? $m->user : null;
                }
                if ($user) {
                    UserLidMapping::linkLidToUser($lid, $user->id, $user->tenant_id, $toNumber);
                    Log::channel('single')->info('WA LID mapping tersimpan dari respons kirim', [
                        'lid' => $lid,
                        'user_id' => $user->id,
                        'phone' => $toNumber,
                    ]);
                }
            }
        }
    }

    /**
     * Send message directly to a LID (Linked ID) address
     * This bypasses phone number cleaning since LID is NOT a phone number
     *
     * @param  string  $sessionId  Session ID
     * @param  string  $lidAddress  Full LID address (e.g., "218442590343379@lid")
     * @param  string  $message  Message content
     * @param  string  $type  Message type (text, image, etc)
     * @param  bool  $simulateTyping  Whether to show typing indicator before sending (default: true)
     */
    public function sendMessageToLid(string $sessionId, string $lidAddress, string $message, string $type = 'text', bool $simulateTyping = true): array
    {
        try {
            Log::info('Sending WhatsApp message to LID', [
                'session_id' => $sessionId,
                'lid_address' => $lidAddress,
                'message_length' => strlen($message),
                'type' => $type,
                'simulate_typing' => $simulateTyping,
            ]);

            $payload = [
                'to' => $lidAddress, // Use LID directly, no cleaning
                'message' => $message,
                'type' => $type,
                'simulateTyping' => $simulateTyping,
            ];

            $response = Http::timeout(45)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions/{$sessionId}/send", $payload);

            Log::info('WhatsApp LID message response', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('WhatsApp LID message failed', [
                'session_id' => $sessionId,
                'lid_address' => $lidAddress,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
                'status_code' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('Error sending WhatsApp LID message', [
                'session_id' => $sessionId,
                'lid_address' => $lidAddress,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send document via WhatsApp Gateway
     *
     * @param  string  $sessionId  Session ID
     * @param  string  $toNumber  Phone number to send to
     * @param  string  $filePath  Path to the document file
     * @param  string  $caption  Caption for the document
     * @param  string|null  $filename  Override filename shown to user
     * @param  string|null  $originalLid  Original LID address for fallback
     */
    public function sendDocument(string $sessionId, string $toNumber, string $filePath, string $caption = '', ?string $filename = null, ?string $originalLid = null): array
    {
        try {
            $resolvedTo = str_contains($toNumber, '@lid')
                ? $toNumber
                : $this->cleanPhoneNumber($toNumber);

            // Check if file exists
            if (! file_exists($filePath)) {
                return [
                    'success' => false,
                    'error' => 'File not found: '.$filePath,
                ];
            }

            $fileSize = filesize($filePath);
            if ($fileSize === false) {
                return [
                    'success' => false,
                    'error' => 'Unable to read file size: '.$filePath,
                ];
            }

            $maxBytes = 8 * 1024 * 1024;
            if ($fileSize > $maxBytes) {
                return [
                    'success' => false,
                    'error' => 'File too large for WhatsApp document: '.$fileSize.' bytes',
                ];
            }

            Log::info('Sending WhatsApp document', [
                'session_id' => $sessionId,
                'to_number' => $toNumber,
                'resolved_to' => $resolvedTo,
                'original_lid' => $originalLid,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'caption' => $caption,
            ]);

            // Read file and convert to base64
            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                return [
                    'success' => false,
                    'error' => 'Unable to read file: '.$filePath,
                ];
            }
            $base64Content = base64_encode($fileContent);
            $mimeType = function_exists('mime_content_type')
                ? (mime_content_type($filePath) ?: 'application/pdf')
                : 'application/pdf';
            $fileName = $filename ?: basename($filePath);

            $payload = [
                'to' => $resolvedTo,
                'document' => $base64Content,
                'filename' => $fileName,
                'mimetype' => $mimeType,
                'caption' => $caption,
            ];

            if ($originalLid && ! str_contains($resolvedTo, '@lid')) {
                $payload['originalLid'] = $originalLid;
            }

            $response = Http::timeout(60)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions/{$sessionId}/send-document", $payload);

            Log::info('WhatsApp document response', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::warning('WhatsApp document send failed', [
                'session_id' => $sessionId,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
                'status_code' => $response->status(),
            ];

        } catch (\Throwable $e) {
            Log::error('Error sending WhatsApp document', [
                'session_id' => $sessionId,
                'to_number' => $toNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if phone number is registered on WhatsApp
     *
     * @param  string  $sessionId  Session ID
     * @param  string  $phoneNumber  Phone number to check
     */
    public function checkNumber(string $sessionId, string $phoneNumber): array
    {
        try {
            $cleanedNumber = $this->cleanPhoneNumber($phoneNumber);

            Log::info('Checking WhatsApp number registration', [
                'session_id' => $sessionId,
                'phone_number' => $cleanedNumber,
            ]);

            $response = Http::timeout(15)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->engineUrl}/sessions/{$sessionId}/check-number", [
                    'number' => $cleanedNumber,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('WhatsApp number check result', [
                    'phone_number' => $cleanedNumber,
                    'exists' => $data['exists'] ?? false,
                    'jid' => $data['jid'] ?? null,
                ]);

                return [
                    'success' => true,
                    'exists' => $data['exists'] ?? false,
                    'jid' => $data['jid'] ?? null,
                    'number' => $cleanedNumber,
                ];
            }

            Log::warning('WhatsApp number check failed', [
                'phone_number' => $cleanedNumber,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return [
                'success' => false,
                'error' => $response->body() ?: 'Failed to check number',
                'status_code' => $response->status(),
            ];

        } catch (\Exception $e) {
            Log::error('Error checking WhatsApp number', [
                'phone_number' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete session (from WhatsApp Gateway)
     */
    public function deleteSession(string $sessionId): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->delete("{$this->engineUrl}/sessions/{$sessionId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Session deleted successfully',
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to delete session',
            ];
        } catch (\Exception $e) {
            Log::error('Error deleting WhatsApp session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Delete all sessions (from WhatsApp Gateway)
     */
    public function deleteAllSessions(): array
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-API-Key' => $this->apiKey,
                ])
                ->delete("{$this->engineUrl}/sessions");

            if ($response->successful()) {
                $data = $response->json();
                Log::info('All sessions deleted', [
                    'deleted_count' => count($data['deleted'] ?? []),
                    'errors' => $data['errors'] ?? [],
                ]);

                return [
                    'success' => true,
                    'data' => $data,
                    'message' => $data['message'] ?? 'All sessions deleted successfully',
                ];
            }

            return [
                'success' => false,
                'error' => $response->body() ?: 'Failed to delete all sessions',
            ];
        } catch (\Exception $e) {
            Log::error('Error deleting all WhatsApp sessions', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Store session mapping in channel config
     */
    protected function storeSessionMapping(int $tenantId, string $channelAccount, string $sessionId): void
    {
        $channel = Channel::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'type' => 'whatsapp',
                'channel_account' => $channelAccount,
            ],
            [
                'name' => 'WhatsApp: '.$channelAccount,
                'is_active' => false,
            ]
        );

        $config = $channel->config ?? [];
        $config['session_id'] = $sessionId;
        $config['engine_url'] = $this->engineUrl;

        $channel->update(['config' => $config]);
    }

    /**
     * Clean phone number (helper from wa-blast)
     */
    protected function cleanPhoneNumber(string $phoneNumber): string
    {
        // If it's a JID (contains @), don't clean it
        if (str_contains($phoneNumber, '@')) {
            return $phoneNumber;
        }

        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);

        // Remove leading + if exists
        $cleaned = ltrim($cleaned, '+');

        // If starts with 0, replace with 62
        if (substr($cleaned, 0, 1) === '0') {
            $cleaned = '62'.substr($cleaned, 1);
        }

        // If doesn't start with 62, add it
        if (substr($cleaned, 0, 2) !== '62') {
            $cleaned = '62'.$cleaned;
        }

        return $cleaned;
    }

    /**
     * Generate session ID from tenant and channel account
     */
    public function generateSessionId(int $tenantId, string $channelAccount): string
    {
        return "wa_{$tenantId}_{$channelAccount}";
    }
}
