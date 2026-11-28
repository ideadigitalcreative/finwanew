<?php

/*
|--------------------------------------------------------------------------
| FinWa — Kata kunci query keuangan (SATU sumber kebenaran)
|--------------------------------------------------------------------------
|
| Sebelumnya daftar ini disalin di ProcessIncomingMessage, FinancialQueryHandler,
| ConversationContextService dan FinancialQueryService dengan isi berbeda-beda,
| sehingga pesan yang dikenali di satu tempat ditolak di tempat lain
| ("rinciannya bulan lalu" → "Maaf, saya belum bisa menjawab itu").
|
| Tambah kata baru DI SINI, jangan di kode.
|
*/

return [

    // Kata yang menandai pesan sebagai PERTANYAAN/LAPORAN keuangan.
    // Dipakai fast-path query (wajib disertai periode & tanpa nominal).
    // Jangan masukkan kata yang juga perintah lain (mis. "budget" → perintah budget).
    'query_keywords' => [
        'pengeluaran', 'pemasukan', 'ringkasan', 'rekap', 'rekapan', 'rangkuman', 'laporan',
        'saldo', 'cashflow', 'cash flow', 'total', 'berapa', 'cek', 'lihat', 'daftar',
        'rincian', 'rinci', 'detail', 'tampilkan', 'transaksi', 'riwayat', 'mutasi',
        // Informal
        'habis', 'udah habis', 'sudah habis', 'udah keluar', 'sudah keluar',
        'masuk berapa', 'keluar berapa', 'spending', 'income',
    ],

    // Tambahan yang hanya dipakai untuk VALIDASI "apakah ini query keuangan"
    // (FinancialQueryHandler::isRecognizedFinancialQuery), bukan untuk routing awal.
    'query_keywords_extra' => [
        'pendapatan', 'penghasilan', 'arus kas', 'keluar', 'masuk',
        'budget', 'anggaran', 'omset', 'omzet', 'expense', 'revenue',
        'statistik', 'analisis', 'analisa', 'keuanganku',
        'terakhir', 'tertinggi', 'terbesar', 'terbanyak',
        'list', 'tunjukkan', 'sebutkan', 'belanjaan', 'sisa', 'duit', 'uang',
    ],

    // Frasa periode relatif.
    'period_keywords' => [
        'hari ini', 'minggu ini', 'bulan ini', 'tahun ini',
        'kemarin', 'bulan lalu', 'minggu lalu', 'tahun lalu',
        'bulan kemarin', 'minggu kemarin', 'tahun kemarin',
        // Informal
        'hr ini', 'hari ni', 'bln ini', 'bln lalu', 'bln kemarin', 'kmrn',
    ],

    // Kata pembuka yang menandai kalimat sebagai pertanyaan/laporan
    // ("pengeluaran 500rb bulan ini" = query, "beli pulsa total 50rb hari ini" = transaksi).
    'query_start_words' => [
        'pengeluaran', 'pemasukan', 'ringkasan', 'rekap', 'rekapan', 'laporan', 'total',
        'berapa', 'brp', 'cek', 'lihat', 'daftar', 'list', 'rincian', 'rinciannya', 'rinci',
        'detail', 'tampilkan', 'riwayat', 'transaksi', 'saldo', 'cashflow', 'habis', 'udah',
        'sudah', 'keluar', 'masuk',
    ],

    // Permintaan statistik → butuh intent cek_statistik (bukan ringkasan biasa).
    'statistics_keywords' => [
        'terbesar', 'tertinggi', 'terendah', 'terkecil', 'paling', 'top',
        'rata-rata', 'average', 'statistik', 'stats', 'analisis', 'analysis',
        'tren', 'trend', 'kategori terbesar', 'spending habit',
    ],

    // Hutang/piutang (dipakai routing & guard intent AI di TransactionService).
    'debt_keywords' => [
        'hutang', 'utang', 'piutang', 'pinjam', 'pinjaman', 'pinjem', 'pijemin',
        'kasih pinjam', 'bayar hutang', 'bayar utang', 'pelunasan', 'lunas', 'dipinjemin',
        'balikin pinjaman', 'pinjamkan',
    ],

    // Kata kunci transaksi informal/slang di luar config finwa_category_rules.
    // Kata ≤ 3 huruf dicocokkan sebagai kata utuh, 4 huruf di awal kata
    // (lihat MessageSignals::hasKeyword) — 'ff' tidak cocok di "coffee".
    'transaction_slang_keywords' => [
        'mkn', 'maem', 'mamam', 'nyemil', 'ngemil',
        'nasgor', 'nasgep', 'mie instant',
        'mcd', 'mcdonalds', 'kfc', 'hokben', 'yoshinoya', 'solaria', 'warunk upnormal',
        'starbucks', 'sbux', 'sbx', 'janji jiwa', 'kopi kenangan', 'fore', 'tomoro',
        'mixue', 'chatime', 'gulu gulu', 'xiboba', 'boba',
        'pizza hut', 'phd', 'dominos', 'burger king', 'wendys',
        'jco', 'dunkin', 'krispy kreme',
        'ngopi', 'esteh',
        'jajan', 'borong', 'checkout', 'order', 'pesen',
        'shopee', 'tokped', 'tokopedia', 'lazada', 'bukalapak', 'blibli', 'olshop', 'online shop',
        'alfamart', 'indomaret', 'alfamidi', 'superindo', 'hypermart', 'carrefour', 'giant', 'lotte',
        'ojol', 'ojek online', 'maxim', 'indriver', 'bluebird', 'busway',
        'etoll', 'e-toll',
        'isi bensin', 'ngisi bensin',
        'pln', 'pdam', 'indihome', 'biznet', 'firstmedia',
        'kuota', 'paket data', 'top up', 'topup', 'isi pulsa', 'isi kuota',
        'xxi', 'cgv', 'cinepolis',
        'netflix', 'spotify', 'youtube', 'disney', 'vidio', 'viu', 'wetv', 'iqiyi',
        'steam', 'playstation', 'xbox', 'mobile legend', 'ml', 'ff', 'pubg', 'valorant',
        'gpt', 'chatgpt', 'openai', 'gemini', 'claude', 'copilot', 'cursor',
        'canva', 'figma', 'adobe', 'prem', 'subs',
        'apk', 'app', 'aplikasi', 'software', 'license', 'lisensi',
        'cloud', 'hosting', 'domain', 'vps',
        'tf', 'trf', 'terima duit', 'dapat duit', 'dapet duit',
        'uang masuk', 'duit masuk',
        'ngontrak', 'kredit',
        'ngasih', 'kirimin', 'kirim ke', 'transfer ke',
        'infak', 'amal',
        'apotek', 'rumah sakit', 'rs', 'puskesmas', 'klinik', 'lab', 'cek darah',
        'salon', 'barbershop', 'potong rambut', 'cukur', 'facial', 'spa', 'pijat', 'massage',
        'skincare', 'makeup', 'kosmetik',
        'atk', 'alat tulis', 'bimbel',
        'abis', 'habis', 'kluar', 'spending', 'spent',
        'ngeluarin', 'keluarin', 'abis buat', 'habis buat', 'keluar buat',
        'tadi', 'barusan', 'kmrn', 'semalem',
        'geprek', 'bakwan', 'batagor', 'empek', 'cilok', 'cireng', 'ketoprak',
        'terang bulan', 'roti bakar',
        // Kata jenis transaksi eksplisit ("pemasukan cash 200000"). Dulu hanya
        // "tertangkap" karena 'emas' cocok di dalam "pEMASukan" (kebetulan).
        // Aman: kalimat query ("pengeluaran 500rb bulan ini") disaring looksLikeQuery.
        'pemasukan', 'pengeluaran', 'pendapatan', 'penghasilan',
    ],

    // Topik edukasi keuangan → dijawab Chat LLM, bukan FAQ/perintah.
    // Dipakai FAQService (lewati FAQ) & aturan "pertanyaan nasihat" di ProcessIncomingMessage.
    'finance_topics' => [
        'nabung', 'menabung', 'tabungan', 'saving',
        'investasi', 'invest', 'reksadana', 'reksa dana', 'saham', 'obligasi', 'deposito', 'investasi emas', 'nabung emas', 'crypto', 'kripto',
        'hemat', 'menghemat', 'penghematan', 'irit', 'berhemat', 'boros', 'pemborosan',
        'hutang', 'utang', 'pinjaman', 'pinjol', 'kredit', 'cicilan', 'kpr', 'paylater',
        'asuransi', 'insurance', 'premi', 'pajak', 'tax', 'npwp',
        'inflasi', 'deflasi', 'suku bunga', 'bunga bank',
        'financial freedom', 'bebas finansial', 'kebebasan finansial',
        'dana darurat', 'emergency fund', 'dana pensiun', 'pensiun',
        'cash flow', 'cashflow', 'arus kas',
        'kelola uang', 'kelola keuangan', 'atur uang', 'atur keuangan', 'mengatur keuangan', 'mengatur uang',
        'manage keuangan', 'manajemen keuangan', 'keuangan keluarga',
        'tips keuangan', 'tips finansial', 'tips uang',
        'side hustle', 'penghasilan tambahan', 'passive income', 'pendapatan pasif',
        'konsisten', 'disiplin keuangan', 'kebiasaan keuangan',
        'umkm', 'usaha', 'bisnis', 'modal', 'untung', 'rugi', 'margin', 'omset', 'omzet',
        'gaji', 'penghasilan', 'income',
        'pengeluaran berlebih', 'impulsive buying', 'belanja impulsif',
        'rencana keuangan', 'perencanaan keuangan', 'financial plan', 'financial planning',
        '50/30/20',
    ],

    // Nama bulan (lengkap + singkatan) → nomor bulan. Dicocokkan sebagai kata utuh.
    'month_names' => [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'apr' => 4, 'jun' => 6, 'jul' => 7,
        'agu' => 8, 'agt' => 8, 'ags' => 8, 'sep' => 9, 'sept' => 9,
        'okt' => 10, 'nov' => 11, 'des' => 12,
    ],

];
