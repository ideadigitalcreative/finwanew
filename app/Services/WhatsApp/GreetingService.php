<?php

namespace App\Services\WhatsApp;

use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * GreetingService - Handles greeting and help message generation
 *
 * MOVED FROM: ProcessIncomingMessage::handleFinWaSpecialIntent()
 */
class GreetingService
{
    protected Message $message;
    protected $sendReplyCallback;

    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Handle special intents from FinWa-AI (sapa, help)
     *
     * MOVED FROM: ProcessIncomingMessage::handleFinWaSpecialIntent()
     */
    public function handleSpecialIntent(string $intent): void
    {
        if ($intent === 'sapa') {
            $this->sendGreeting();
        } elseif ($intent === 'help') {
            $this->sendHelp();
        }
    }

    protected function sendGreeting(): void
    {
        $hour = (int) now()->format('H');
        $greeting = match(true) {
            $hour >= 5 && $hour < 11 => 'Selamat pagi',
            $hour >= 11 && $hour < 15 => 'Selamat siang',
            $hour >= 15 && $hour < 18 => 'Selamat sore',
            default => 'Selamat malam',
        };

        $userName = '';
        try {
            $tenant = $this->message->tenant;
            if ($tenant && $tenant->user && $tenant->user->name) {
                $firstName = explode(' ', $tenant->user->name)[0];
                $userName = ", {$firstName}";
            }
        } catch (\Exception $e) {
            // Ignore if can't get name
        }

        $this->sendReply(
            "{$greeting}{$userName}! 👋\n\n" .
            "Saya *FinWa*, asisten keuangan pribadi Anda. Siap membantu mencatat dan menganalisis keuangan Anda dengan mudah! 💰\n\n" .
            "📝 *Mau catat transaksi?*\n" .
            "Cukup ketik seperti ngobrol biasa:\n" .
            "• _\"beli kopi 25rb\"_\n" .
            "• _\"gaji bulan ini 5jt\"_\n" .
            "• _\"kasih orang tua 500rb\"_\n" .
            "• Atau kirim foto struk! 📸\n\n" .
            "📊 *Mau cek keuangan?*\n" .
            "• _\"ringkasan bulan ini\"_\n" .
            "• _\"berapa pengeluaran minggu ini\"_\n\n" .
            "Ketik *help* untuk panduan lengkap 💡"
        );
    }

    protected function sendHelp(): void
    {
        $this->sendReply(
            "📱 *Panduan FinWa*\n\n" .
            "💸 *Catat Transaksi:*\n" .
            "_beli kopi 25rb_\n" .
            "_gaji bulan ini 8jt_\n" .
            "_kasih ortu 500rb_\n\n" .
            "📸 *Scan Struk:*\n" .
            "Kirim foto struk → otomatis terbaca\n\n" .
            "🎤 *Voice Note:*\n" .
            "Kirim pesan suara → otomatis dicatat\n\n" .
            "💳 *Mengelola Dompet/Rekening:*\n" .
            "➕ _Tambah dompet:_\n" .
            "_tambah dompet BCA_\n" .
            "_tambah dompet Gopay saldo 500rb_\n" .
            "_tambah rekening Dana_\n\n" .
            "👀 _Lihat dompet:_\n" .
            "_lihat dompet_\n" .
            "_daftar rekening_\n" .
            "_cek saldo_\n\n" .
            "⚙️ _Atur saldo dompet:_\n" .
            "_set saldo BCA menjadi 5jt_\n" .
            "_ubah saldo Gopay jadi 500rb_\n\n" .
            "💰 _Pemasukan ke dompet tertentu:_\n" .
            "_dapet transfer 1jt ke BCA_\n" .
            "_terima bayaran 500rb ke Gopay_\n\n" .
            "📊 *Cek Keuangan:*\n" .
            "_ringkasan bulan ini_\n" .
            "_lihat transaksi_\n\n" .
            "🎯 *Budget & Target:*\n" .
            "_set budget makan 500rb_\n" .
            "_set target 10jt untuk liburan_\n\n" .
            "🔔 *Pengingat:*\n" .
            "_ingatkan bayar listrik 20 tiap bulan_\n\n" .
            "📄 *Laporan:*\n" .
            "_export pdf_ → PDF bulan ini\n\n" .
            "💡 Tips: 25rb=25ribu, 50k=50ribu"
        );
    }
}
