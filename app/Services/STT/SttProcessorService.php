<?php

namespace App\Services\STT;

use App\Models\Message;
use App\Models\SttJob;
use App\Services\FinWaAIService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * SttProcessorService - Handles Speech-to-Text (Voice Note) processing
 * 
 * Flow:
 * 1. User sends voice note via WhatsApp
 * 2. Audio file is saved and SttJob is created
 * 3. Audio is sent to Whisper API for transcription
 * 4. Transcribed text is processed as a regular message
 */
class SttProcessorService
{
    protected Message $message;
    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Create STT job for audio processing
     */
    public function createSttJob(): ?SttJob
    {
        try {
            // Get audio URL/path
            $audioUrl = null;
            
            if ($this->message->type === 'audio') {
                // 1. If content is a URL
                if (!empty($this->message->content) && (filter_var($this->message->content, FILTER_VALIDATE_URL) || str_contains($this->message->content, 'http') || str_contains($this->message->content, '/storage/'))) {
                    $audioUrl = $this->message->content;
                } 
                // 2. Check metadata (common storage)
                elseif (isset($this->message->metadata['media_url'])) {
                    $audioUrl = $this->message->metadata['media_url'];
                }
                // 3. Check raw_data (WhatsApp standard)
                elseif (isset($this->message->raw_data['audio']['url'])) {
                    $audioUrl = $this->message->raw_data['audio']['url'];
                }
                // 4. Check media_id for base64 or path
                elseif (!empty($this->message->media_id)) {
                    $audioUrl = $this->message->media_id;
                }
                // 5. Fallback
                else {
                    $audioUrl = $this->message->content;
                }
            }
            
            if (!$audioUrl) {
                Log::warning('No audio found for STT job', ['message_id' => $this->message->id]);
                return null;
            }
            
            // Determine file type from URL or metadata
            $fileType = 'audio/ogg'; // WhatsApp default
            if (isset($this->message->metadata['mimetype'])) {
                $fileType = $this->message->metadata['mimetype'];
            } elseif (str_contains($audioUrl, '.mp3')) {
                $fileType = 'audio/mpeg';
            } elseif (str_contains($audioUrl, '.m4a')) {
                $fileType = 'audio/mp4';
            } elseif (str_contains($audioUrl, '.wav')) {
                $fileType = 'audio/wav';
            }
            
            // Get duration if available
            $duration = $this->message->metadata['duration'] ?? null;
            
            // Create STT Job
            $sttJob = SttJob::create([
                'tenant_id' => $this->message->tenant_id,
                'message_id' => $this->message->id,
                'file_path' => $audioUrl,
                'file_type' => $fileType,
                'duration_seconds' => $duration,
                'status' => 'pending',
                'created_at' => now()
            ]);
            
            Log::info('STT Job created', [
                'job_id' => $sttJob->id,
                'file_path' => $audioUrl,
                'file_type' => $fileType
            ]);
            
            return $sttJob;
            
        } catch (\Exception $e) {
            Log::error('Failed to create STT job', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Process STT job - transcribe audio to text
     */
    public function processSttJob(SttJob $sttJob): void
    {
        try {
            $this->sendReply("🎤 Sedang memproses pesan suara... Mohon tunggu.");
            
            // Update status
            $sttJob->update([
                'status' => 'processing',
                'started_at' => now()
            ]);
            
            // Get audio content
            $audioContent = $this->getAudioContent($sttJob->file_path);
            
            if (!$audioContent) {
                $this->handleSttFailure($sttJob, 'Gagal mengambil file audio');
                return;
            }
            
            // Transcribe using Whisper API
            $transcribedText = $this->transcribeWithWhisper($audioContent, $sttJob->file_type);
            
            if (!$transcribedText) {
                $this->handleSttFailure($sttJob, 'Gagal mentranskrip audio');
                return;
            }
            
            // Update job with result
            $processingTime = now()->diffInMilliseconds($sttJob->started_at);
            $sttJob->update([
                'status' => 'completed',
                'transcribed_text' => $transcribedText,
                'processing_time_ms' => $processingTime,
                'completed_at' => now()
            ]);
            
            Log::info('STT transcription completed', [
                'stt_job_id' => $sttJob->id,
                'transcribed_text' => $transcribedText,
                'processing_time_ms' => $processingTime
            ]);
            
            // Process the transcribed text as a message
            $this->processTranscribedText($sttJob, $transcribedText);
            
        } catch (\Exception $e) {
            Log::error('Error processing STT job', [
                'stt_job_id' => $sttJob->id,
                'error' => $e->getMessage()
            ]);
            $this->handleSttFailure($sttJob, $e->getMessage());
        }
    }

    /**
     * Get audio content from URL or storage
     */
    protected function getAudioContent(string $filePath): ?string
    {
        try {
            Log::info('Getting audio content', ['file_path' => $filePath]);
            
            $localPath = null;
            
            // Extract path from api/files URL format
            // Example: https://finwa.web.id/api/files?path=whatsapp%2F1%2F2026%2F01%2F01%2Ffile.ogg
            if (str_contains($filePath, 'api/files') && str_contains($filePath, 'path=')) {
                $parsedUrl = parse_url($filePath);
                parse_str($parsedUrl['query'] ?? '', $queryParams);
                $localPath = urldecode($queryParams['path'] ?? '');
                Log::info('Extracted path from api/files URL', ['local_path' => $localPath]);
            }
            // Extract path from URL containing whatsapp/
            elseif (str_contains($filePath, 'whatsapp/')) {
                if (str_contains($filePath, 'path=')) {
                    $parsedUrl = parse_url($filePath);
                    parse_str($parsedUrl['query'] ?? '', $queryParams);
                    $localPath = urldecode($queryParams['path'] ?? '');
                } elseif (preg_match('/(whatsapp\/[^\?\s]+)/', $filePath, $matches)) {
                    $localPath = $matches[1];
                } else {
                    $localPath = $filePath;
                }
            }
            
            if ($localPath) {
                $localPath = trim($localPath, '/');
                Log::info('Trying local storage path', ['path' => $localPath]);
                
                // Try public disk
                if (Storage::disk('public')->exists($localPath)) {
                    Log::info('Found audio in public storage', ['path' => $localPath]);
                    return Storage::disk('public')->get($localPath);
                }
                
                // Try local disk
                if (Storage::disk('local')->exists($localPath)) {
                    Log::info('Found audio in local storage', ['path' => $localPath]);
                    return Storage::disk('local')->get($localPath);
                }
                
                // Try with storage/app/public prefix
                $publicPath = 'public/' . $localPath;
                if (Storage::disk('local')->exists($publicPath)) {
                    Log::info('Found audio in local/public storage', ['path' => $publicPath]);
                    return Storage::disk('local')->get($publicPath);
                }
                
                // Try absolute path
                $absolutePath = storage_path('app/public/' . $localPath);
                if (file_exists($absolutePath)) {
                    Log::info('Found audio at absolute path', ['path' => $absolutePath]);
                    return file_get_contents($absolutePath);
                }
                
                Log::warning('Audio file not found in storage', [
                    'local_path' => $localPath,
                    'checked_paths' => [
                        'public:' . $localPath,
                        'local:' . $localPath,
                        'local:public/' . $localPath,
                        $absolutePath
                    ]
                ]);
            }
            
            // Fetch via HTTP as fallback
            if (str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://')) {
                Log::info('Trying to fetch audio via HTTP', ['url' => $filePath]);
                
                $response = Http::timeout(60)->get($filePath);
                
                if ($response->successful()) {
                    Log::info('Successfully fetched audio via HTTP', ['size' => strlen($response->body())]);
                    return $response->body();
                }
                
                Log::error('Failed to fetch audio via HTTP', [
                    'url' => $filePath,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200)
                ]);
            }
            
            // Try as base64
            if (str_starts_with($filePath, 'data:audio')) {
                $parts = explode(',', $filePath);
                if (count($parts) === 2) {
                    Log::info('Decoding base64 audio');
                    return base64_decode($parts[1]);
                }
            }
            
            Log::error('Could not get audio content from any source', ['file_path' => $filePath]);
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error getting audio content', [
                'error' => $e->getMessage(),
                'file_path' => $filePath
            ]);
            return null;
        }
    }

    /**
     * Transcribe audio - try multiple providers in order:
     * 1. FinWa-AI (self-hosted) - FREE, no rate limits
     * 2. Groq Whisper API - FREE, 7000 seconds/day
     * 3. OpenAI Whisper API - Paid fallback
     */
    protected function transcribeWithWhisper(string $audioContent, string $mimeType): ?string
    {
        try {
            $finwaEnabled = config('services.finwa_ai.enabled', true);
            $groqKey = config('services.groq.api_key');
            $openaiKey = config('services.openai.api_key');
            
            // Priority 1: FinWa-AI Self-hosted (if enabled)
            if ($finwaEnabled) {
                $result = $this->transcribeWithFinWaAI($audioContent, $mimeType);
                if ($result) {
                    return $result;
                }
                Log::info('FinWa-AI STT failed, trying fallback...');
            }
            
            // Priority 2: Groq (FREE)
            if ($groqKey) {
                $result = $this->transcribeWithGroq($audioContent, $mimeType, $groqKey);
                if ($result) {
                    return $result;
                }
                Log::info('Groq STT failed, trying fallback...');
            }
            
            // Priority 3: OpenAI (Paid)
            if ($openaiKey) {
                return $this->transcribeWithOpenAI($audioContent, $mimeType, $openaiKey);
            }
            
            Log::error('No STT provider available. Configure FINWA_AI_ENABLED, GROQ_API_KEY, or OPENAI_API_KEY');
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error in transcription', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Transcribe using FinWa-AI self-hosted Whisper (FREE, no limits)
     * Endpoint: POST /process/audio
     */
    protected function transcribeWithFinWaAI(string $audioContent, string $mimeType): ?string
    {
        try {
            $baseUrl = config('services.finwa_ai.url', 'https://ai.finwa.web.id');
            $timeout = config('services.finwa_ai.timeout', 60);
            
            // Determine file extension
            $extension = match($mimeType) {
                'audio/ogg' => 'ogg',
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/mp4', 'audio/m4a' => 'm4a',
                'audio/wav', 'audio/wave' => 'wav',
                'audio/webm' => 'webm',
                'audio/flac' => 'flac',
                default => 'ogg'
            };
            
            // Convert to base64
            $base64Audio = base64_encode($audioContent);
            
            Log::info('Sending audio to FinWa-AI STT', [
                'url' => $baseUrl . '/process/audio',
                'file_size' => strlen($audioContent),
                'extension' => $extension
            ]);
            
            // Send to FinWa-AI
            $response = Http::timeout($timeout)
                ->post("{$baseUrl}/process/audio", [
                    'audio' => $base64Audio,
                    'audio_base64' => $base64Audio,
                    'file_type' => $mimeType,
                    'extension' => $extension,
                    'language' => 'id' // Indonesian
                ]);
            
            if ($response->successful()) {
                $result = $response->json();
                $text = $result['text'] ?? $result['transcription'] ?? $result['transcript'] ?? null;
                
                if ($text) {
                    Log::info('FinWa-AI STT transcription successful', ['text' => $text]);
                    return trim($text);
                }
            }
            
            Log::warning('FinWa-AI STT error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500)
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::warning('FinWa-AI STT connection error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Transcribe using Groq Whisper API (FREE)
     */
    protected function transcribeWithGroq(string $audioContent, string $mimeType, string $apiKey): ?string
    {
        try {
            // Determine file extension from mime type
            $extension = match($mimeType) {
                'audio/ogg' => 'ogg',
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/mp4', 'audio/m4a' => 'm4a',
                'audio/wav', 'audio/wave' => 'wav',
                'audio/webm' => 'webm',
                'audio/flac' => 'flac',
                default => 'ogg'
            };
            
            // Create temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'stt_') . '.' . $extension;
            file_put_contents($tempFile, $audioContent);
            
            Log::info('Sending audio to Groq Whisper API', [
                'file_size' => strlen($audioContent),
                'extension' => $extension
            ]);
            
            // Send to Groq API
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->attach('file', file_get_contents($tempFile), 'audio.' . $extension)
                ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                    'model' => 'whisper-large-v3',
                    'language' => 'id', // Indonesian
                    'response_format' => 'text'
                ]);
            
            // Clean up temp file
            @unlink($tempFile);
            
            if ($response->successful()) {
                $text = trim($response->body());
                Log::info('Groq Whisper transcription successful', ['text' => $text]);
                return $text;
            }
            
            Log::error('Groq Whisper API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error calling Groq Whisper API', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Transcribe using OpenAI Whisper API (fallback, paid)
     */
    protected function transcribeWithOpenAI(string $audioContent, string $mimeType, string $apiKey): ?string
    {
        try {
            // Determine file extension from mime type
            $extension = match($mimeType) {
                'audio/ogg' => 'ogg',
                'audio/mpeg', 'audio/mp3' => 'mp3',
                'audio/mp4', 'audio/m4a' => 'm4a',
                'audio/wav', 'audio/wave' => 'wav',
                'audio/webm' => 'webm',
                default => 'ogg'
            };
            
            // Create temp file
            $tempFile = tempnam(sys_get_temp_dir(), 'stt_') . '.' . $extension;
            file_put_contents($tempFile, $audioContent);
            
            Log::info('Sending audio to OpenAI Whisper API', [
                'file_size' => strlen($audioContent),
                'extension' => $extension
            ]);
            
            // Send to OpenAI Whisper API
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                ])
                ->attach('file', file_get_contents($tempFile), 'audio.' . $extension)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'id', // Indonesian
                    'response_format' => 'text'
                ]);
            
            // Clean up temp file
            @unlink($tempFile);
            
            if ($response->successful()) {
                $text = trim($response->body());
                Log::info('OpenAI Whisper transcription successful', ['text' => $text]);
                return $text;
            }
            
            Log::error('OpenAI Whisper API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Error calling OpenAI Whisper API', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Process transcribed text as a regular message
     */
    protected function processTranscribedText(SttJob $sttJob, string $text): void
    {
        // Update the original message with transcribed content
        $this->message->update([
            'content' => $text,
            'metadata' => array_merge($this->message->metadata ?? [], [
                'original_type' => 'audio',
                'transcribed' => true,
                'stt_job_id' => $sttJob->id
            ])
        ]);
        
        // Send confirmation with transcribed text
        $this->sendReply(
            "🎤 *Pesan suara dikenali:*\n" .
            "_\"{$text}\"_\n\n" .
            "⏳ Memproses..."
        );
        
        // The transcribed text will be processed by ProcessIncomingMessage
        // through a new job dispatch or by returning the text to be processed
        Log::info('Transcribed text ready for processing', [
            'stt_job_id' => $sttJob->id,
            'message_id' => $this->message->id,
            'text' => $text
        ]);
    }

    /**
     * Handle STT failure
     */
    protected function handleSttFailure(SttJob $sttJob, string $error): void
    {
        $sttJob->update([
            'status' => 'failed',
            'error_message' => $error,
            'completed_at' => now()
        ]);
        
        $this->sendReply(
            "⚠️ *Gagal memproses pesan suara*\n\n" .
            "Maaf, tidak dapat mentranskrip audio.\n" .
            "Silakan coba:\n" .
            "• Kirim ulang voice note dengan suara lebih jelas\n" .
            "• Atau ketik pesan Anda secara manual\n\n" .
            "_Error: {$error}_"
        );
    }
}
