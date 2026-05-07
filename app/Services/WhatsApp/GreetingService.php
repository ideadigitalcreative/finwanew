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
            "📱 *FinWa - Panduan Lengkap v2.7*\n\n" .
            "Halo! Saya FinWa, asisten keuangan Anda 🚀\n\n" .
            "━━━ 💸 *CATAT PENGELUARAN* ━━━\n" .
            "Ketik seperti ngobrol biasa:\n" .
            "• _makan siang 25rb_\n" .
            "• _beli bensin 50k_\n" .
            "• _kasih orang tua 500rb_\n\n" .
            "*Dari dompet tertentu:*\n" .
            "• _keluar dari BCA 50rb beli kopi_\n" .
            "• _bayar dari gopay 25rb grab_\n\n" .
            "━━━ 📸 *SCAN STRUK* ━━━\n" .
            "Kirim foto struk, FinWa baca otomatis!\n" .
            "• Foto harus jelas & tidak kusut\n" .
            "• Total belanja terlihat jelas\n\n" .
            "━━━ 🎤 *VOICE NOTE* ━━━\n" .
            "Malas ketik? Kirim pesan suara!\n" .
            "Contoh ucapkan:\n" .
            "• _\"beli makan dua puluh lima ribu\"_\n" .
            "• _\"terima transfer lima ratus ribu\"_\n\n" .
            "━━━ 📝 *CATAT BANYAK SEKALIGUS* ━━━\n" .
            "*Pengeluaran:*\n" .
            "_Biaya tanggal 10 Des 2025_\n" .
            "_1. Makan siang 25rb_\n" .
            "_2. Grab pulang 15rb_\n\n" .
            "*Pemasukan dari banyak orang:*\n" .
            "_Uang masuk atas nama_\n" .
            "_Agung 116k_\n" .
            "_Budi 500rb_\n\n" .
            "━━━ 💰 *CATAT PEMASUKAN* ━━━\n" .
            "• _gaji bulan ini 8jt_\n" .
            "• _dapat bonus 1.5jt_\n" .
            "• _terima transfer 500rb_\n" .
            "• _dapet tf 100rb ke BCA_ (ke dompet)\n\n" .
            "━━━ 💳 *DOMPET/REKENING* ━━━\n" .
            "*Tambah dompet:*\n" .
            "• _tambah dompet BCA_\n" .
            "• _tambah dompet Gopay saldo 100rb_\n\n" .
            "*Kelola dompet:*\n" .
            "• _lihat dompet_ - daftar saldo\n" .
            "• _set saldo BCA menjadi 1jt_ - atur saldo\n" .
            "• _tambah saldo Dana 500rb_ - top up\n" .
            "• _hapus dompet [nama]_\n\n" .
            "━━━ 📊 *CEK KEUANGAN* ━━━\n" .
            "• _cek saldo_ - lihat saldo\n" .
            "• _ringkasan hari ini_ - harian\n" .
            "• _ringkasan minggu ini_ - mingguan\n" .
            "• _ringkasan bulan ini_ - bulanan\n" .
            "• _cek cashflow_ - arus kas\n" .
            "• _lihat transaksi_ - riwayat\n\n" .
            "━━━ 🎯 *BUDGET* ━━━\n" .
            "• _set budget makan 500rb_\n" .
            "• _cek budget_ - lihat budget\n\n" .
            "━━━ 💰 *TARGET TABUNGAN* ━━━\n" .
            "• _set target 10jt untuk liburan_\n" .
            "• _cek target_ - lihat progress\n" .
            "• _nabung 500rb ke liburan_\n\n" .
            "━━━ 📈 *STATISTIK* ━━━\n" .
            "• _cek statistik_ - analisis AI\n" .
            "• _cek insight_ - pola pengeluaran\n" .
            "• _cek achievement_ - badge & streak\n\n" .
            "━━━ 🔔 *PENGINGAT* ━━━\n" .
            "• _ingatkan bayar listrik 20 tiap bulan_\n" .
            "• _reminder internet 250rb tanggal 5_\n" .
            "• _lihat pengingat_ / _hapus pengingat_\n\n" .
            "━━━ 📄 *LAPORAN* ━━━\n" .
            "• _export pdf_ - PDF bulan ini\n" .
            "• _laporan minggu ini_ - PDF minggu ini\n\n" .
            "━━━ 💡 *TIPS* ━━━\n" .
            "• Format angka: 25rb, 50k, 1.5jt\n" .
            "• Bahasa gaul OK: _gw beli kopi 25rb_\n" .
            "• Voice note 🎤 / Foto struk 📸\n" .
            "• Kategori otomatis terdeteksi\n\n" .
            "Butuh bantuan? Langsung tanya saja! 😊"
        );
    }
}
