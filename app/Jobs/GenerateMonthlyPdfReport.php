<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Message;
use App\Services\MessageReplyService;
use App\Services\PdfReportService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateMonthlyPdfReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $messageId,
        public int $month,
        public int $year
    ) {
    }

    public function handle(): void
    {
        $message = Message::find($this->messageId);
        if (! $message) {
            return;
        }

        $doneKey = "report_pdf_done:{$message->id}";
        if (! Cache::add($doneKey, true, now()->addMinutes(30))) {
            return;
        }

        $replyService = new MessageReplyService($message);

        try {
            $pdfService = new PdfReportService($message->tenant_id);
            $pdfPath = $pdfService->generateMonthlyReport($this->month, $this->year);

            if (! $pdfPath || ! file_exists($pdfPath)) {
                $replyService->sendReply(
                    "⚠️ *Gagal membuat PDF*\n\n".
                    'Tidak dapat membuat laporan. Pastikan ada transaksi di bulan tersebut.'
                );
                return;
            }

            $caption = 'Laporan Keuangan - '.Carbon::create($this->year, $this->month, 1)->translatedFormat('F Y');
            $this->sendDocument($message, $pdfPath, $caption, $replyService);

            Log::info('PDF report generated and sent', [
                'message_id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'month' => $this->month,
                'year' => $this->year,
                'pdf' => basename($pdfPath),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error generating monthly PDF report', [
                'message_id' => $message->id,
                'tenant_id' => $message->tenant_id,
                'error' => $e->getMessage(),
            ]);

            $replyService->sendReply(
                "⚠️ *Gagal membuat PDF*\n\n".
                'Terjadi kesalahan. Silakan coba lagi nanti.'
            );
        }
    }

    protected function sendDocument(Message $message, string $filePath, string $caption, MessageReplyService $replyService): void
    {
        try {
            $channel = Channel::find($message->channel_id);
            if (! $channel) {
                $replyService->sendReply("⚠️ *Gagal mengirim PDF*\n\nChannel tidak ditemukan.");
                return;
            }

            $config = $channel->config ?? [];
            $sessionId = $config['session_id'] ?? null;
            if (! $sessionId) {
                $replyService->sendReply("⚠️ *Gagal mengirim PDF*\n\nSession ID belum terset.");
                return;
            }

            $toNumber = $message->phone_number
                ?? $message->from
                ?? $message->sender_id
                ?? null;

            if (! $toNumber) {
                $replyService->sendReply("⚠️ *Gagal mengirim PDF*\n\nNomor tujuan tidak ditemukan.");
                return;
            }

            $metadata = is_array($message->metadata)
                ? $message->metadata
                : json_decode($message->metadata ?? '{}', true);
            $originalLid = $metadata['original_sender_id'] ?? null;

            $whatsAppService = new WhatsAppService;
            $result = $whatsAppService->sendDocument($sessionId, $toNumber, $filePath, $caption, null, $originalLid);

            if (isset($result['success']) && $result['success'] === true) {
                return;
            }

            $replyService->sendReply($this->buildFallbackLinkMessage($filePath));
        } catch (\Throwable $e) {
            Log::error('Failed to send PDF document', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'file' => $filePath,
            ]);

            $replyService->sendReply($this->buildFallbackLinkMessage($filePath));
        }
    }

    protected function buildFallbackLinkMessage(string $filePath): string
    {
        $filename = basename($filePath);
        $publicDiskRoot = Storage::disk('public')->path('');
        $normalizedFile = str_replace('\\', '/', $filePath);
        $normalizedRoot = str_replace('\\', '/', $publicDiskRoot);

        $relativePath = ltrim(str_replace($normalizedRoot, '', $normalizedFile), '/');
        $publicUrl = url('storage/'.$relativePath);

        return
            "📄 *Laporan PDF Siap*\n\n".
            "📎 {$filename}\n\n".
            "Download di sini:\n{$publicUrl}\n\n".
            '_Link berlaku 24 jam_';
    }
}
