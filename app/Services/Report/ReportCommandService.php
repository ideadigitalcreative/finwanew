<?php

namespace App\Services\Report;

use App\Models\Channel;
use App\Models\Message;
use App\Jobs\GenerateMonthlyPdfReport;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ReportCommandService - Handles Report generation and export commands
 */
class ReportCommandService
{
    protected Message $message;

    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    protected function sendReply(string $text): void
    {
        call_user_func($this->sendReplyCallback, $text);
    }

    /**
     * Handle export PDF request (export pdf, laporan pdf)
     */
    public function handleExportPdf(string $messageText): void
    {
        try {
            $dispatchKey = "report_pdf_dispatched:{$this->message->id}";
            if (! Cache::add($dispatchKey, true, now()->addMinutes(15))) {
                return;
            }

            $this->sendReply("📊 Sedang membuat laporan PDF...\n\nMohon tunggu sebentar.");

            // Parse month/year from message if specified
            $month = null;
            $year = null;

            $textLower = strtolower($messageText);

            // Check for month mentions
            $months = [
                'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
                'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
                'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
                'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
                'jun' => 6, 'jul' => 7, 'agt' => 8, 'aug' => 8,
                'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
            ];

            foreach ($months as $monthName => $monthNum) {
                if (str_contains($textLower, $monthName)) {
                    $month = $monthNum;
                    break;
                }
            }

            // Check for year
            if (preg_match('/20\d{2}/', $messageText, $yearMatch)) {
                $year = (int) $yearMatch[0];
            }

            // Default to current month if not specified
            if (! $month) {
                $month = now()->month;
            }
            if (! $year) {
                $year = now()->year;
            }

            GenerateMonthlyPdfReport::dispatch($this->message->id, $month, $year);

        } catch (\Exception $e) {
            Log::error('Error exporting PDF', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendReply(
                "⚠️ *Gagal membuat PDF*\n\n".
                'Terjadi kesalahan: '.$e->getMessage()."\n\n".
                'Silakan coba lagi nanti atau hubungi support.'
            );
        }
    }

    /**
     * Send document via WhatsApp
     */
    protected function sendDocument(string $filePath, string $caption = ''): void
    {
        try {
            $channel = Channel::find($this->message->channel_id);

            if (! $channel) {
                Log::error('Channel not found for document sending');

                return;
            }

            // Get session ID from channel config
            $config = $channel->config ?? [];
            $sessionId = $config['session_id'] ?? null;

            if (! $sessionId) {
                Log::error('Session ID not found in channel config');
                throw new \Exception('Session ID not found');
            }

            // Get phone number - try different fields
            $toNumber = $this->message->phone_number
                ?? $this->message->from
                ?? $this->message->sender_id
                ?? null;

            // Check for LID address
            $originalLid = null;
            if ($toNumber && str_contains($toNumber, '@lid')) {
                $originalLid = $toNumber;
                $toNumber = str_replace('@lid', '', $toNumber);
            }

            if (! $toNumber) {
                Log::error('Phone number not found for document sending');
                throw new \Exception('Phone number not found');
            }

            Log::info('Sending PDF document', [
                'session_id' => $sessionId,
                'to' => $toNumber,
                'file' => basename($filePath),
            ]);

            $whatsAppService = new WhatsAppService;
            $result = $whatsAppService->sendDocument(
                $sessionId,
                $toNumber,
                $filePath,
                $caption
            );

            if (! $result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to send document');
            }

        } catch (\Exception $e) {
            Log::error('Failed to send document', [
                'error' => $e->getMessage(),
                'file' => $filePath,
            ]);

            // Fallback: send link instead
            $filename = basename($filePath);
            $relativePath = str_replace(storage_path('app/public/'), '', $filePath);
            $publicUrl = url('storage/'.$relativePath);

            $this->sendReply(
                "📄 *Laporan PDF Siap*\n\n".
                "📎 {$filename}\n\n".
                "Download di sini:\n{$publicUrl}\n\n".
                '_Link berlaku 24 jam_'
            );
        }
    }
}
