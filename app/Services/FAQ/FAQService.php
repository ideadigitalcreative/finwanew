<?php

namespace App\Services\FAQ;

use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * FAQService - Handles FAQ/General Questions
 *
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 *
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class FAQService
{
    protected Message $message;

    protected $sendReplyCallback;

    /**
     * Constructor
     *
     * @param  Message  $message  The message being processed
     * @param  callable  $sendReplyCallback  Callback to send reply via WhatsApp
     */
    public function __construct(Message $message, callable $sendReplyCallback)
    {
        $this->message = $message;
        $this->sendReplyCallback = $sendReplyCallback;
    }

    /**
     * Send reply via callback
     */
    protected function sendReply(string $message): void
    {
        call_user_func($this->sendReplyCallback, $message);
    }

    /**
     * Check if message is a FAQ/general question and handle it
     * Returns true if handled, false otherwise
     *
     * MOVED FROM: ProcessIncomingMessage::checkAndHandleFAQ()
     * LINES: 8890-9067
     * MODIFICATION: None (structural move only)
     */
    public function checkAndHandleFAQ(string $messageText): bool
    {
        $textLower = strtolower($messageText);

        // GUARD: Skip FAQ for financial education topics → let Groq LLM handle these dynamically
        // This prevents generic patterns like "bagaimana cara" from catching financial questions
        $financialEducationTopics = [
            'nabung',
            'menabung',
            'tabungan',
            'saving',
            'investasi',
            'invest',
            'reksadana',
            'reksa dana',
            'saham',
            'obligasi',
            'deposito',
            'hemat',
            'menghemat',
            'penghematan',
            'irit',
            'berhemat',
            'hutang',
            'utang',
            'pinjaman',
            'kredit',
            'cicilan',
            'kpr',
            'asuransi',
            'insurance',
            'premi',
            'pajak',
            'tax',
            'npwp',
            'inflasi',
            'deflasi',
            'suku bunga',
            'bunga bank',
            'financial freedom',
            'bebas finansial',
            'kebebasan finansial',
            'dana darurat',
            'emergency fund',
            'cash flow',
            'cashflow',
            'arus kas',
            'kelola uang',
            'kelola keuangan',
            'manage keuangan',
            'manajemen keuangan',
            'tips keuangan',
            'tips finansial',
            'tips uang',
            'side hustle',
            'penghasilan tambahan',
            'passive income',
            'pendapatan pasif',
            'konsisten',
            'disiplin keuangan',
            'kebiasaan keuangan',
            'umkm',
            'usaha',
            'bisnis',
            'modal',
            'untung',
            'rugi',
            'margin',
            'omset',
            'omzet',
            'gaji',
            'penghasilan',
            'income',
            'pengeluaran berlebih',
            'boros',
            'pemborosan',
            'impulsive buying',
            'belanja impulsif',
            'rencana keuangan',
            'perencanaan keuangan',
            'financial plan',
            'financial planning',
        ];

        $isFinancialEducation = false;
        foreach ($financialEducationTopics as $topic) {
            if (str_contains($textLower, $topic)) {
                $isFinancialEducation = true;
                break;
            }
        }

        // If it's a financial education question, skip FAQ entirely → route to Groq LLM
        if ($isFinancialEducation) {
            return false;
        }

        // Define FAQ patterns and their answers
        $faqPatterns = [
            // ========== CARA PAKAI / PENGGUNAAN ==========
            // Only match questions specifically about using the FinWa app
            [
                'patterns' => [
                    'cara pakai finwa',
                    'cara menggunakan finwa',
                    'cara gunakan finwa',
                    'cara pakai aplikasi',
                    'cara menggunakan aplikasi',
                    'cara gunakan aplikasi',
                    'tutorial finwa',
                    'panduan finwa',
                    'petunjuk finwa',
                    'tutorial aplikasi',
                    'panduan aplikasi',
                    'petunjuk aplikasi',
                    'how to use',
                    'cara kerja finwa',
                    'cara kerja aplikasi',
                    'cara pakenya',
                    'cara pakai',
                    'cara menggunakan',
                    'mulai dari mana',
                    'langkah-langkah',
                    'gimana sih finwa',
                    'gimana sih aplikasi',
                    'caranya gimana finwa',
                    'harus gimana finwa',
                ],
                'answer' => $this->getFAQHowToUse(),
            ],
            // ========== TENTANG FINWA ==========
            [
                'patterns' => [
                    'apa itu finwa',
                    'apa itu aplikasi',
                    'finwa itu apa',
                    'tentang finwa',
                    'tentang aplikasi',
                    'about finwa',
                    'what is finwa',
                    'finwa apaan',
                    'ini apaan',
                    'ini apa',
                    'kamu siapa',
                    'siapa kamu',
                    'apakah finwa',
                    'finwa adalah',
                    'kegunaan finwa',
                ],
                'answer' => $this->getFAQAboutApp(),
            ],
            // ========== CARA BUAT DOMPET ==========
            [
                'patterns' => [
                    'cara buat dompet',
                    'cara tambah dompet',
                    'cara bikin dompet',
                    'gimana buat dompet',
                    'gimana tambah dompet',
                    'bagaimana buat dompet',
                    'bagaimana tambah dompet',
                    'cara tambah rekening',
                    'cara buat rekening',
                    'tambah wallet gimana',
                    'buat wallet gimana',
                    'cara add wallet',
                    'cara menambah dompet',
                    'cara membuat dompet',
                    'cara nambah dompet',
                    'dompet baru gimana',
                    'rekening baru gimana',
                    'mau buat dompet',
                    'pengen buat dompet',
                    'caranya buat dompet',
                ],
                'answer' => $this->getFAQCreateWallet(),
            ],
            // ========== CARA BUDGETING ==========
            [
                'patterns' => [
                    'cara buat budget',
                    'cara budgeting',
                    'cara set budget',
                    'cara atur budget',
                    'gimana budgeting',
                    'gimana set budget',
                    'gimana buat budget',
                    'bagaimana budgeting',
                    'bagaimana buat budget',
                    'bagaimana set budget',
                    'cara buat anggaran',
                    'cara atur anggaran',
                    'gimana atur anggaran',
                    'budget gimana',
                    'anggaran gimana',
                    'cara pakai budget',
                    'cara menggunakan budget',
                    'mau budgeting',
                    'pengen budgeting',
                    'caranya budgeting',
                    'caranya set budget',
                ],
                'answer' => $this->getFAQBudgeting(),
            ],
            // Tips keuangan (pribadi, UMKM, dll) → diarahkan ke Groq LLM untuk jawaban dinamis
            // ========== FITUR GRUP ==========
            [
                'patterns' => [
                    'bisa untuk grup',
                    'untuk grup',
                    'bisa di grup',
                    'pakai di grup',
                    'group chat',
                    'grup wa',
                    'grup whatsapp',
                    'bisa grup',
                ],
                'answer' => $this->getFAQGroupFeature(),
            ],
            // ========== HARGA / BIAYA ==========
            [
                'patterns' => [
                    'berapa harga',
                    'berapa biaya',
                    'tarif',
                    'pricing',
                    'biaya langganan',
                    'harga langganan',
                    'bayar berapa',
                    'gratis',
                    'free',
                    'berbayar',
                    'harganya berapa',
                    'biayanya berapa',
                ],
                'answer' => $this->getFAQPricing(),
            ],
            // ========== CARA LANGGANAN ==========
            [
                'patterns' => [
                    // Pertanyaan langsung cara langganan
                    'cara langganan',
                    'cara berlangganan',
                    'cara subscribe',
                    'cara subscription',
                    'gimana langganan',
                    'gimana berlangganan',
                    'gimana cara langganan',
                    'gimana cara berlangganan',
                    'gimana subscribe',
                    'gimana cara subscribe',
                    'bagaimana langganan',
                    'bagaimana berlangganan',
                    'bagaimana cara langganan',
                    'bagaimana cara berlangganan',
                    'bagaimana subscribe',
                    'caranya langganan',
                    'caranya berlangganan',
                    'caranya subscribe',
                    // Pertanyaan tentang paket
                    'cara beli paket',
                    'cara ambil paket',
                    'cara pilih paket',
                    'gimana beli paket',
                    'gimana ambil paket',
                    'bagaimana beli paket',
                    'bagaimana ambil paket',
                    'mau langganan',
                    'mau berlangganan',
                    'mau subscribe',
                    'mau beli paket',
                    'mau ambil paket',
                    'pengen langganan',
                    'pengen berlangganan',
                    'pengen subscribe',
                    'pengen beli paket',
                    'ingin langganan',
                    'ingin berlangganan',
                    'ingin subscribe',
                    'ingin beli paket',
                    // Pertanyaan seputar upgrade / perpanjang
                    'cara upgrade',
                    'cara perpanjang',
                    'cara perpanjang langganan',
                    'gimana upgrade',
                    'gimana perpanjang',
                    'bagaimana upgrade',
                    'bagaimana perpanjang',
                    'mau upgrade',
                    'mau perpanjang',
                    // Pertanyaan seputar pembayaran langganan
                    'cara bayar langganan',
                    'cara bayar paket',
                    'cara pembayaran',
                    'gimana bayar langganan',
                    'gimana bayar paket',
                    'bagaimana bayar langganan',
                    'bagaimana bayar paket',
                    'bayar langganan gimana',
                    'bayar paket gimana',
                    'pembayaran langganan',
                    'metode bayar',
                    // Pertanyaan singkat / informal
                    'langganan gimana',
                    'subscribe gimana',
                    'paket gimana',
                    'daftar premium',
                    'cara daftar premium',
                    'mau premium',
                    'upgrade premium',
                    'jadi premium',
                    'aktifkan premium',
                    'cara aktifkan premium',
                    'cara jadi premium',
                    'langkah langganan',
                    'langkah berlangganan',
                    'langkah subscribe',
                    'prosedur langganan',
                    'prosedur berlangganan',
                    'step langganan',
                    'step berlangganan',
                    'info langganan',
                    'info paket',
                    'info berlangganan',
                    'panduan langganan',
                    'panduan berlangganan',
                    'panduan subscribe',
                ],
                'answer' => $this->getFAQHowToSubscribe(),
            ],
            // ========== CARA TRANSFER ==========
            [
                'patterns' => [
                    'cara transfer',
                    'cara pindah saldo',
                    'cara kirim saldo',
                    'cara pindah dana',
                    'gimana transfer',
                    'gimana pindah saldo',
                    'gimana kirim dana',
                    'bagaimana transfer',
                    'bagaimana pindah saldo',
                    'bagaimana kirim dana',
                    'transfer antar dompet',
                    'pindah saldo antar dompet',
                    'transfer saldo',
                    'transfer dana',
                    'pindah dana',
                    'kirim dana',
                ],
                'answer' => $this->getFAQTransferFund(),
            ],
            // ========== KEAMANAN DATA ==========
            [
                'patterns' => [
                    'keamanan',
                    'keamanan data',
                    'privasi',
                    'privacy',
                    'data aman',
                    'data saya',
                    'security',
                    'rahasia',
                    'data bocor',
                    'aman gak',
                    'aman nggak',
                    'aman tidak',
                    'apakah aman',
                    'apa aman',
                ],
                'answer' => $this->getFAQSecurity(),
            ],
            // ========== FITUR TERSEDIA ==========
            [
                'patterns' => [
                    'fitur apa saja',
                    'bisa apa saja',
                    'apa saja fitur',
                    'fitur yang tersedia',
                    'kemampuan',
                    'features',
                    'bisa ngapain',
                    'bisa apa aja',
                ],
                'answer' => $this->getFAQFeatures(),
            ],
            // ========== CARA DAFTAR ==========
            [
                'patterns' => [
                    'cara daftar',
                    'gimana daftar',
                    'register',
                    'registrasi',
                    'buat akun',
                    'sign up',
                    'signup',
                    'daftar gimana',
                    'caranya daftar',
                ],
                'answer' => $this->getFAQRegister(),
            ],
            // ========== SUPPORT ==========
            [
                'patterns' => [
                    'hubungi',
                    'contact',
                    'kontak',
                    'customer service',
                    'cs',
                    'support',
                    'bantuan',
                    'komplain',
                    'lapor masalah',
                ],
                'answer' => $this->getFAQSupport(),
            ],
            // ========== KATEGORI ==========
            [
                'patterns' => [
                    'kategori apa saja',
                    'jenis kategori',
                    'daftar kategori',
                    'macam kategori',
                    'kategori yang tersedia',
                    'kategori apa aja',
                ],
                'answer' => $this->getFAQCategories(),
            ],
            // ========== EXPORT LAPORAN ==========
            [
                'patterns' => [
                    'cara export',
                    'download laporan',
                    'cetak laporan',
                    'print laporan',
                    'buat laporan',
                    'export gimana',
                    'unduh laporan',
                ],
                'answer' => $this->getFAQExport(),
            ],
            // ========== LIMIT/BATASAN ==========
            [
                'patterns' => [
                    'ada limit',
                    'batasan',
                    'maksimal transaksi',
                    'limit transaksi',
                    'batas',
                ],
                'answer' => $this->getFAQLimits(),
            ],
            // ========== CARA CATAT TRANSAKSI ==========
            [
                'patterns' => [
                    'cara catat transaksi',
                    'cara input transaksi',
                    'cara masukin transaksi',
                    'gimana catat',
                    'gimana input',
                    'cara catat pengeluaran',
                    'cara catat pemasukan',
                    'cara input pengeluaran',
                    'cara input pemasukan',
                    'caranya catat',
                    'caranya input',
                ],
                'answer' => $this->getFAQRecordTransaction(),
            ],
            // ========== CARA CEK SALDO ==========
            [
                'patterns' => [
                    'cara cek saldo',
                    'gimana cek saldo',
                    'bagaimana cek saldo',
                    'cara lihat saldo',
                    'gimana lihat saldo',
                    'caranya cek saldo',
                ],
                'answer' => $this->getFAQCheckBalance(),
            ],
        ];

        // Check each pattern using word boundary matching
        // This prevents false positives like 'pinjaman' matching 'aman'
        foreach ($faqPatterns as $faq) {
            foreach ($faq['patterns'] as $pattern) {
                // Use word boundary regex for short patterns (<=6 chars) to avoid false positives
                // For longer patterns, str_contains is safe enough
                $matched = false;
                if (mb_strlen($pattern) <= 6) {
                    // Short pattern: use word boundary to avoid substring matches
                    $escaped = preg_quote($pattern, '/');
                    $matched = (bool) preg_match('/\b'.$escaped.'\b/i', $textLower);
                } else {
                    $matched = str_contains($textLower, $pattern);
                }

                if ($matched) {
                    Log::info('FAQ matched', [
                        'message_id' => $this->message->id,
                        'pattern' => $pattern,
                    ]);

                    $this->sendReply($faq['answer']);

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * FAQ: How to use the app
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQHowToUse()
     * LINES: 9069-9087
     * MODIFICATION: None
     */
    public function getFAQHowToUse(): string
    {
        return "📱 *Cara Menggunakan FinWa*\n\n".
            "Sangat mudah! Cukup chat seperti biasa:\n\n".
            "*1️⃣ Catat Pengeluaran*\n".
            "Ketik: _makan siang 25rb_ atau _beli bensin 50k_\n\n".
            "*2️⃣ Catat Pemasukan*\n".
            "Ketik: _gaji bulan ini 5jt_ atau _bonus 500rb_\n\n".
            "*3️⃣ Foto Struk*\n".
            "Kirim foto struk belanja, otomatis tercatat! 📸\n\n".
            "*4️⃣ Cek Keuangan*\n".
            "Ketik: _cek saldo_, _cek statistik_, _lihat transaksi_\n\n".
            "*5️⃣ Kelola Dompet*\n".
            "Ketik: _tambah dompet BCA_, _lihat dompet_\n\n".
            '💡 Ketik *help* untuk panduan lengkap!';
    }

    /**
     * FAQ: About the app
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQAboutApp()
     * LINES: 9089-9104
     * MODIFICATION: None
     */
    public function getFAQAboutApp(): string
    {
        return "🤖 *Tentang FinWa*\n\n".
            "FinWa adalah asisten keuangan pribadi berbasis WhatsApp yang membantu Anda:\n\n".
            "✅ Mencatat pemasukan & pengeluaran dengan mudah\n".
            "✅ Menganalisis pola keuangan Anda\n".
            "✅ Membaca struk belanja otomatis (OCR)\n".
            "✅ Mengelola banyak dompet/rekening\n".
            "✅ Membuat laporan keuangan\n".
            "✅ Mengatur budget & target tabungan\n\n".
            "🎯 *Misi kami:* Membuat pencatatan keuangan semudah ngobrol!\n\n".
            '🌐 Website: https://finwa.web.id';
    }

    /**
     * FAQ: Group feature
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQGroupFeature()
     * LINES: 9106-9128
     * MODIFICATION: None
     */
    public function getFAQGroupFeature(): string
    {
        return "👥 *Fitur Multi-User & Pasangan*\n\n".
            "*📱 Grup WhatsApp:*\n".
            "Saat ini FinWa belum mendukung di grup WA.\n".
            "FinWa dirancang untuk chat 1-on-1.\n\n".
            "*💑 Fitur Pasangan (Premium):*\n".
            "Dengan paket Premium, Anda bisa:\n".
            "✅ Tambah nomor WA pasangan\n".
            "✅ Kelola keuangan bersama\n".
            "✅ Satu dashboard untuk berdua\n".
            "✅ Masing-masing bisa catat transaksi\n\n".
            "*Cara menambah nomor pasangan:*\n".
            "1. Login ke dashboard\n".
            "2. Buka menu WhatsApp\n".
            "3. Klik 'Tambah Nomor'\n".
            "4. Masukkan nomor pasangan\n\n".
            "🔗 https://finwa.web.id/login\n\n".
            '💡 Pasangan Anda akan bisa mencatat transaksi ke akun yang sama!';
    }

    /**
     * FAQ: Pricing
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQPricing()
     * LINES: 9130-9147
     * MODIFICATION: None
     */
    public function getFAQPricing(): string
    {
        return "💰 *Harga & Paket FinWa*\n\n".
            "*🆓 Paket Gratis Selamanya*\n".
            "├ 50 transaksi/bulan\n".
            "└ Catat via teks WhatsApp\n\n".
            "*💎 Paket Premium (Growth):*\n".
            "├ 📅 1 Bulan   — *Rp 20.000*\n".
            "├ 📅 12 Bulan — *Rp 204.000*\n".
            "├ ✅ Transaksi unlimited\n".
            "├ ✅ *100* Struk/bulan\n".
            "└ ✅ Hingga 2 nomor WhatsApp\n\n".
            "*🔥 Paket PRO:*\n".
            "├ 📅 1 Bulan   — *Rp 45.000*\n".
            "├ 📅 12 Bulan — *Rp 459.000*\n".
            "├ ✅ Transaksi unlimited\n".
            "├ ✅ *300* Struk/bulan\n".
            "├ ✅ Hingga 5 nomor WhatsApp\n".
            "└ ✅ *Dukungan Prioritas*\n\n".
            "📱 *Daftar sekarang:*\n".
            "https://finwa.web.id/checkout\n\n".
            "❓ *Butuh bantuan?*\n".
            'Hubungi admin: https://wa.me/6285242766676';
    }

    /**
     * FAQ: Security
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQSecurity()
     * LINES: 9149-9169
     * MODIFICATION: None
     */
    public function getFAQSecurity(): string
    {
        return "🔒 *Keamanan Data FinWa*\n\n".
            "Data Anda *aman* bersama kami!\n\n".
            "*Jaminan keamanan:*\n".
            "✅ Data terenkripsi end-to-end\n".
            "✅ Server aman dengan SSL\n".
            "✅ Tidak pernah dibagikan ke pihak ketiga\n".
            "✅ Anda bisa hapus data kapan saja\n".
            "✅ Backup otomatis\n\n".
            "*Yang kami simpan:*\n".
            "• Data transaksi keuangan Anda\n".
            "• Foto struk (untuk OCR)\n\n".
            "*Yang TIDAK kami simpan:*\n".
            "• Password WhatsApp Anda\n".
            "• Chat lain selain ke FinWa\n\n".
            '📧 Ada pertanyaan? Hubungi support@finwa.web.id';
    }

    /**
     * FAQ: Features
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQFeatures()
     * LINES: 9171-9197
     * MODIFICATION: None
     */
    public function getFAQFeatures(): string
    {
        return "⭐ *Fitur FinWa*\n\n".
            "*💸 Pencatatan Transaksi*\n".
            "• Catat pengeluaran & pemasukan\n".
            "• Input batch (banyak sekaligus)\n".
            "• OCR foto struk otomatis\n\n".
            "*💳 Multi-Dompet*\n".
            "• Kelola banyak rekening/e-wallet\n".
            "• Pantau saldo masing-masing\n\n".
            "*📊 Analisis Keuangan*\n".
            "• Statistik pengeluaran\n".
            "• Cashflow bulanan\n".
            "• Top kategori spending\n\n".
            "*🎯 Budget & Target*\n".
            "• Set budget per kategori\n".
            "• Target tabungan\n\n".
            "*📤 Export & Laporan*\n".
            "• Export PDF/Excel\n".
            "• Dashboard web lengkap\n\n".
            "*⏰ Pengingat*\n".
            "• Reminder tagihan\n\n".
            'Ketik *help* untuk panduan lengkap!';
    }

    /**
     * FAQ: How to register
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQRegister()
     * LINES: 9199-9219
     * MODIFICATION: None
     */
    public function getFAQRegister(): string
    {
        return "📝 *Cara Daftar FinWa*\n\n".
            "*Langkah mudah:*\n\n".
            "*1️⃣ Kunjungi link registrasi*\n".
            "https://finwa.web.id/checkout\n\n".
            "*2️⃣ Isi data diri*\n".
            "• Nama lengkap\n".
            "• Email aktif\n".
            "• Password\n\n".
            "*3️⃣ Daftarkan nomor WhatsApp*\n".
            "• Login ke dashboard\n".
            "• Buka menu WhatsApp\n".
            "• Tambahkan nomor WA Anda\n\n".
            "*4️⃣ Mulai gunakan!*\n".
            "Chat ke FinWa untuk mencatat transaksi\n\n".
            '🎁 *Bonus:* Trial gratis 3 hari untuk semua fitur premium!';
    }

    /**
     * FAQ: Support contact
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQSupport()
     * LINES: 9221-9239
     * MODIFICATION: None
     */
    public function getFAQSupport(): string
    {
        return "📞 *Hubungi Support FinWa*\n\n".
            "Ada pertanyaan atau masalah? Kami siap membantu!\n\n".
            "*💬 WhatsApp Admin*\n".
            "https://wa.me/6285242766676\n\n".
            "*📧 Email Support*\n".
            "support@finwa.web.id\n\n".
            "*🌐 Website*\n".
            "https://finwa.web.id\n\n".
            "*⏰ Jam Layanan*\n".
            "Senin - Jumat: 09:00 - 18:00 WIB\n\n".
            "*💡 Tips sebelum menghubungi:*\n".
            "• Ketik *help* untuk panduan\n".
            "• Cek FAQ di website\n".
            "• Jelaskan masalah dengan detail\n\n".
            'Kami akan merespon dalam 1x24 jam kerja 🙏';
    }

    /**
     * FAQ: Categories
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQCategories()
     * LINES: 9241-9267
     * MODIFICATION: None
     */
    public function getFAQCategories(): string
    {
        return "📁 *Kategori Transaksi FinWa*\n\n".
            "*💸 Pengeluaran:*\n".
            "• 🍔 Makanan & Minuman\n".
            "• 🚗 Transport\n".
            "• 🛒 Belanja\n".
            "• 📱 Pulsa & Token\n".
            "• 💡 Tagihan (Listrik, Air, dll)\n".
            "• 🎬 Hiburan\n".
            "• 🏠 Hunian (Kos, Kontrakan)\n".
            "• 💳 Pinjaman\n".
            "• 🏦 Cicilan\n".
            "• 👨‍👩‍👧 Keluarga\n".
            "• 🤲 Donasi/Sedekah\n".
            "• 🏥 Kesehatan\n".
            "• 📚 Pendidikan\n".
            "• 📦 Lainnya\n\n".
            "*💰 Pemasukan:*\n".
            "• 💵 Gaji\n".
            "• 🎁 Bonus\n".
            "• 📈 Investasi\n".
            "• 📦 Lainnya\n\n".
            '💡 Kategori otomatis terdeteksi dari deskripsi!';
    }

    /**
     * FAQ: Export reports
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQExport()
     * LINES: 9269-9287
     * MODIFICATION: None
     */
    public function getFAQExport(): string
    {
        return "📤 *Export Laporan Keuangan*\n\n".
            "*Via WhatsApp:*\n".
            "Ketik: _export laporan_\n".
            "Anda akan mendapat ringkasan + link dashboard\n\n".
            "*Via Dashboard Web:*\n".
            "1. Login ke https://finwa.web.id/login\n".
            "2. Buka menu Transaksi\n".
            "3. Klik tombol Export\n".
            "4. Pilih format: PDF atau Excel\n\n".
            "*Format tersedia:*\n".
            "📄 PDF - Laporan visual dengan grafik\n".
            "📊 Excel - Data lengkap untuk analisis\n\n".
            '💡 Anda bisa filter berdasarkan tanggal, tipe, dan kategori!';
    }

    /**
     * FAQ: Limits
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQLimits()
     * LINES: 9289-9309
     * MODIFICATION: None
     */
    public function getFAQLimits(): string
    {
        return "📊 *Batasan & Limit FinWa*\n\n".
            "*🆓 Trial Gratis (3 hari):*\n".
            "• Transaksi: Unlimited\n".
            "• OCR Struk: Unlimited\n".
            "• Dompet: Unlimited\n".
            "• Export: Unlimited\n\n".
            "*💎 Paket Berbayar:*\n".
            "• Transaksi & Dompet: Unlimited\n".
            "• Scan Struk: Sesuai kuota paket (100/300 per bulan)\n\n".
            "*⚠️ Batasan Teknis:*\n".
            "• Foto struk: Max 10MB\n".
            "• Maks 1 foto per pesan\n".
            "• Format foto: JPG, PNG, WEBP\n".
            "• Input batch: Max 20 item/pesan\n\n".
            '💡 Tidak ada limit jumlah transaksi per hari!';
    }

    /**
     * FAQ: How to create wallet
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQCreateWallet()
     * LINES: 9311-9337
     * MODIFICATION: None
     */
    public function getFAQCreateWallet(): string
    {
        return "💳 *Cara Buat Dompet/Rekening*\n\n".
            "Sangat mudah! Ikuti langkah berikut:\n\n".
            "*📱 Via WhatsApp:*\n".
            "Ketik salah satu:\n".
            "• _tambah dompet BCA_\n".
            "• _tambah dompet Gopay_\n".
            "• _tambah dompet Cash 100rb_\n".
            "  (dengan saldo awal)\n\n".
            "*Contoh lengkap:*\n".
            "✅ _tambah dompet BCA_\n".
            "✅ _buat dompet Dana saldo 500rb_\n".
            "✅ _tambah rekening Mandiri_\n\n".
            "*💡 Tips:*\n".
            "• Beri nama yang mudah diingat\n".
            "• Bisa tambah saldo awal sekaligus\n".
            "• Ketik _lihat dompet_ untuk cek daftar dompet\n\n".
            "*🌐 Via Dashboard Web:*\n".
            "1. Login ke https://finwa.web.id/login\n".
            "2. Buka menu Dompet\n".
            "3. Klik tombol Tambah Dompet\n".
            '4. Isi nama dan saldo awal';
    }

    /**
     * FAQ: How to use budgeting
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQBudgeting()
     * LINES: 9339-9366
     * MODIFICATION: None
     */
    public function getFAQBudgeting(): string
    {
        return "🎯 *Cara Menggunakan Budget/Anggaran*\n\n".
            "*Apa itu Budget?*\n".
            'Budget adalah batas pengeluaran per kategori yang Anda tetapkan. '.
            "FinWa akan membantu Anda agar tidak melebihi budget!\n\n".
            "*📱 Via WhatsApp:*\n\n".
            "*1️⃣ Set Budget Baru:*\n".
            "Ketik: _set budget [kategori] [nominal]_\n\n".
            "*Contoh:*\n".
            "• _set budget makan 1jt_\n".
            "• _budget transport 500rb_\n".
            "• _set budget belanja 2jt_\n\n".
            "*2️⃣ Cek Status Budget:*\n".
            "Ketik: _cek budget_\n\n".
            "*3️⃣ Tambah Budget:*\n".
            "Ketik: _tambah budget makan 100rb_\n\n".
            "*💡 Tips Budgeting:*\n".
            "• Mulai dengan kategori terbesar (makan, transport)\n".
            "• Set budget realistis berdasarkan histori\n".
            "• Cek budget rutin dengan _cek budget_\n".
            "• FinWa akan peringati jika mendekati limit!\n\n".
            "*📊 Kategori Budget Populer:*\n".
            'Makan • Transport • Belanja • Hiburan • Pulsa';
    }

    /**
     * FAQ: Financial tips
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQFinancialTips()
     * LINES: 9368-9398
     * MODIFICATION: None
     */
    public function getFAQFinancialTips(): string
    {
        return "💡 *Tips Kelola Keuangan dengan FinWa*\n\n".
            "*🎯 1. Catat Semua Transaksi*\n".
            "Sekecil apapun pengeluaran, catat! Ini membantu Anda melihat ke mana uang pergi.\n\n".
            "*💰 2. Gunakan Rumus 50/30/20*\n".
            "• 50% → Kebutuhan (makan, transport, tagihan)\n".
            "• 30% → Keinginan (hiburan, jajan)\n".
            "• 20% → Tabungan & investasi\n\n".
            "*📊 3. Set Budget Per Kategori*\n".
            "Ketik _set budget makan 1jt_ untuk batasi pengeluaran.\n\n".
            "*🏦 4. Pisahkan Dompet*\n".
            "Buat dompet terpisah: Kebutuhan, Tabungan, Dana Darurat.\n".
            "Ketik: _tambah dompet Tabungan_\n\n".
            "*📅 5. Review Mingguan*\n".
            "Ketik _ringkasan minggu ini_ untuk evaluasi.\n\n".
            "*🚫 6. Hindari Utang Konsumtif*\n".
            "Belanja sesuai kemampuan, bukan keinginan sesaat.\n\n".
            "*💵 7. Dana Darurat Dulu*\n".
            "Siapkan 3-6x pengeluaran bulanan sebelum investasi.\n\n".
            "*📈 8. Mulai Investasi*\n".
            "Setelah dana darurat cukup, mulai investasi rutin.\n\n".
            "*🎁 Bonus Tips:*\n".
            "• Foto struk belanja → otomatis tercatat!\n".
            "• Catat transfer ke ortu sebagai pengeluaran\n".
            "• Gunakan _cek statistik_ untuk analisis\n\n".
            '💪 Konsisten adalah kunci! Mulai dari yang kecil.';
    }

    /**
     * FAQ: Financial tips for UMKM/small business
     */
    public function getFAQFinancialTipsUMKM(): string
    {
        return "🏪 *Tips Kelola Keuangan UMKM dengan FinWa*\n\n".
            "*📒 1. Pisahkan Uang Pribadi & Usaha*\n".
            "Buat dompet terpisah untuk usaha.\n".
            "Ketik: _tambah dompet Usaha_\n\n".
            "*💰 2. Catat SEMUA Transaksi Usaha*\n".
            "Setiap pembelian bahan, penjualan, hingga ongkir — catat semuanya.\n".
            "Contoh: _beli bahan kue 150rb dari Usaha_\n\n".
            "*📊 3. Review Cashflow Rutin*\n".
            "Ketik _ringkasan bulan ini_ untuk lihat arus kas usaha.\n".
            "Pastikan pemasukan > pengeluaran!\n\n".
            "*🎯 4. Set Budget Operasional*\n".
            "Batasi pengeluaran per kategori usaha.\n".
            "Ketik: _set budget belanja 2jt_\n\n".
            "*📸 5. Foto Setiap Struk/Nota*\n".
            "Kirim foto struk pembelian bahan → otomatis tercatat!\n".
            "Berguna untuk rekap pajak & laporan.\n\n".
            "*💵 6. Siapkan Dana Darurat Usaha*\n".
            "Sisihkan 10-20% keuntungan sebagai cadangan operasional.\n\n".
            "*📈 7. Analisis Pola Pengeluaran*\n".
            "Ketik _cek statistik_ untuk tahu kategori mana yang paling banyak makan biaya.\n\n".
            "*🏦 8. Reinvestasi dengan Bijak*\n".
            "Gunakan keuntungan untuk mengembangkan usaha, bukan konsumtif.\n\n".
            "*🎁 Tips Tambahan:*\n".
            "• Gunakan _export laporan_ untuk pembukuan bulanan\n".
            "• Pantau margin keuntungan dengan _cek insight_\n".
            '• Disiplin catat = usaha sehat! 💪';
    }

    /**
     * FAQ: How to record transaction
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQRecordTransaction()
     * LINES: 9400-9436
     * MODIFICATION: None
     */
    public function getFAQRecordTransaction(): string
    {
        return "💰 *Cara Catat Transaksi FinWa*\n\n".
            "Sangat mudah! Anda bisa mencatat dengan kalimat natural seperti:\n\n".
            "*📉 Pengeluaran:*\n".
            "• _makan siang 25rb_\n".
            "Tambahkan kata kunci pemasukan.\n\n".
            "*Contoh:*\n".
            "• _gaji bulan ini 5jt_\n".
            "• _terima bonus 500rb_\n".
            "• _dapat transfer 1jt_\n".
            "• _freelance project 2.5jt_\n\n".
            "*📸 Foto Struk:*\n".
            "Kirim foto struk → otomatis tercatat!\n\n".
            "*📋 Input Batch (banyak sekaligus):*\n".
            "```\n".
            "Pengeluaran hari ini:\n".
            "1. Makan siang 25rb\n".
            "2. Kopi 15rb\n".
            "3. Parkir 5rb\n".
            "```\n\n".
            "*💳 Ke Dompet Tertentu:*\n".
            "• _makan 25rb dari BCA_\n".
            "• _gaji 5jt ke Mandiri_\n\n".
            "*💡 Tips:*\n".
            "• Kategori otomatis terdeteksi\n".
            "• Tidak perlu format khusus\n".
            '• Chat natural seperti ke teman!';
    }

    /**
     * FAQ: How to check balance
     *
     * MOVED FROM: ProcessIncomingMessage::getFAQCheckBalance()
     * LINES: 9438-9466
     * MODIFICATION: None
     */
    public function getFAQCheckBalance(): string
    {
        return "💰 *Cara Cek Saldo & Ringkasan*\n\n".
            "*📊 Cek Total Saldo:*\n".
            "Ketik: _cek saldo_ atau _lihat dompet_\n\n".
            "*💳 Cek Per Dompet:*\n".
            "Ketik: _daftar dompet_ atau _list wallet_\n\n".
            "*📈 Cek Ringkasan Cashflow:*\n".
            "• _ringkasan hari ini_\n".
            "• _ringkasan minggu ini_\n".
            "• _ringkasan bulan ini_\n\n".
            "*📉 Cek Pengeluaran:*\n".
            "• _pengeluaran hari ini_\n".
            "• _pengeluaran bulan ini_\n\n".
            "*📊 Cek Statistik:*\n".
            "• _cek statistik_\n".
            "• _pengeluaran terbesar bulan ini_\n\n".
            "*🎯 Cek Budget:*\n".
            "Ketik: _cek budget_\n\n".
            "*📜 Riwayat Transaksi:*\n".
            "• _lihat transaksi_\n".
            "• _transaksi hari ini_\n".
            "• _transaksi kemarin_\n\n".
            "*💡 Tips:*\n".
            'Cek saldo rutin membantu Anda tetap on track dengan budget!';
    }

    /**
     * FAQ: How to subscribe
     */
    public function getFAQHowToSubscribe(): string
    {
        return "📋 *Panduan Cara Langganan FinWa*\n\n".
            "Ikuti langkah-langkah berikut untuk berlangganan paket premium FinWa:\n\n".
            "*1️⃣ Pilih Paket yang Sesuai*\n".
            "├ 📅 1 Bulan   — *Rp 20.000*\n".
            "├ 📅 3 Bulan   — *Rp 57.000* _(hemat 5%)_\n".
            "├ 📅 6 Bulan   — *Rp 108.000* _(hemat 10%)_\n".
            "└ 📅 12 Bulan — *Rp 204.000* _(hemat 15%)_\n\n".
            "*2️⃣ Kunjungi Halaman Checkout*\n".
            "Buka link berikut untuk mendaftar:\n".
            "🔗 https://finwa.web.id/checkout\n\n".
            "*3️⃣ Isi Data & Pilih Paket*\n".
            "• Masukkan nama, email, dan password\n".
            "• Pilih durasi paket yang diinginkan\n\n".
            "*4️⃣ Lakukan Pembayaran*\n".
            "• Ikuti instruksi pembayaran yang tersedia\n".
            "• Pembayaran akan dikonfirmasi otomatis\n\n".
            "*5️⃣ Daftarkan Nomor WhatsApp*\n".
            "• Login ke dashboard https://finwa.web.id/login\n".
            "• Buka menu WhatsApp\n".
            "• Tambahkan nomor WA Anda\n\n".
            "*6️⃣ Mulai Gunakan FinWa!* 🎉\n".
            "Chat ke FinWa untuk mulai mencatat keuangan Anda.\n\n".
            "*❓ Butuh bantuan atau ada kendala?*\n".
            "Hubungi admin kami langsung via WhatsApp:\n".
            '📱 https://wa.me/6285242766676';
    }

    /**
     * FAQ: How to transfer fund between wallets
     */
    public function getFAQTransferFund(): string
    {
        return "💸 *Cara Transfer Antar Dompet*\n\n".
            "Anda bisa memindahkan saldo antar dompet dengan kalimat:\n\n".
            "*Format Utama:*\n".
            "• _transfer [nominal] dari [sumber] ke [tujuan]_\n".
            "• _pindah saldo [nominal] dari [sumber] ke [tujuan]_\n\n".
            "*Contoh:*\n".
            "✅ _transfer 100rb dari BCA ke Mandiri_\n".
            "✅ _pindah 50k dari Cash ke Gopay_\n".
            "✅ _kirim dana 200rb dari BRI ke Dana_\n\n".
            "*💡 Keuntungan:*\n".
            "• Saldo kedua dompet update otomatis\n".
            "• Terhitung sebagai mutasi internal (tidak masuk pengeluaran riil)\n".
            '• Sangat membantu menjaga akurasi saldo dompet.';
    }
}
