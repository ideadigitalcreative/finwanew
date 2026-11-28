<?php

/*
|--------------------------------------------------------------------------
| FinWa — Chat LLM (pertanyaan umum & edukasi keuangan)
|--------------------------------------------------------------------------
|
| Isi prompt yang mudah diubah tanpa menyentuh kode. Harga paket & daftar
| perintah ditulis di sini supaya LLM tidak mengarang fitur/harga.
|
*/

return [

    // Ditampilkan apa adanya bila user bertanya harga/langganan.
    'plans' => env('FINWA_CHAT_PLANS', 'Gratis (50 transaksi/bulan), Premium Rp20.000/bulan, PRO Rp45.000/bulan'),

    // Perintah ASLI aplikasi — LLM hanya boleh menyarankan perintah dari daftar ini.
    'commands' => [
        'beli kopi 25rb' => 'catat pengeluaran',
        'gaji 5jt' => 'catat pemasukan',
        'cek saldo' => 'lihat saldo dompet',
        'ringkasan bulan ini' => 'ringkasan keuangan',
        'lihat transaksi' => 'daftar transaksi',
        'cek statistik' => 'pengeluaran terbesar',
        'set budget makan 500rb' => 'atur budget',
        'set target 10jt untuk liburan' => 'target tabungan',
        'ingatkan bayar listrik 20 tiap bulan' => 'pengingat',
        'export pdf' => 'laporan PDF',
        'help' => 'panduan lengkap',
    ],

    // Jumlah pesan user sebelumnya yang ikut dikirim (konteks percakapan).
    'history_messages' => (int) env('FINWA_CHAT_HISTORY', 2),

    // Jawaban LOKAL saat Chat LLM tidak aktif/gagal — supaya pertanyaan edukasi
    // keuangan tetap dijawab, bukan dibalas Panduan. Kunci = kata topik (substring),
    // dicek berurutan; yang pertama cocok dipakai.
    'local_answers' => [
        'dana darurat' => "Dana darurat itu tabungan khusus buat kejadian mendadak (sakit, kena PHK, motor rusak) 🛟\n\nIdealnya 3–6x pengeluaran bulanan (yang sudah berkeluarga 6–12x). Simpan di tempat yang gampang dicairkan, misalnya rekening terpisah atau reksa dana pasar uang. Mulai saja dari kecil, yang penting rutin.",
        'nabung' => "Kuncinya: *sisihkan dulu, baru belanja* 💰\n\n1. Begitu gajian, langsung pindahkan 10–20% ke rekening tabungan terpisah.\n2. Catat pengeluaran harian biar ketahuan bocornya — di FinWa tinggal ketik _beli kopi 25rb_.\n3. Pasang target biar semangat: _set target 10jt untuk liburan_.\n\nCek perkembanganmu kapan saja: _ringkasan bulan ini_.",
        'tabung' => "Kuncinya: *sisihkan dulu, baru belanja* 💰\n\n1. Begitu gajian, langsung pindahkan 10–20% ke rekening tabungan terpisah.\n2. Catat pengeluaran harian biar ketahuan bocornya — di FinWa tinggal ketik _beli kopi 25rb_.\n3. Pasang target biar semangat: _set target 10jt untuk liburan_.",
        'hemat' => "Tips hemat yang paling terasa 💡\n\n1. Catat SEMUA pengeluaran seminggu, lalu lihat yang paling besar: _cek statistik_.\n2. Pasang budget per kategori: _set budget makan 1jt_.\n3. Tunda belanja non-wajib 24 jam — sering kali keinginannya hilang sendiri.",
        'boros' => "Biar nggak boros 💡\n\n1. Catat semua pengeluaran, lalu cek yang paling besar: _cek statistik_.\n2. Pasang budget per kategori: _set budget jajan 300rb_.\n3. Tunda belanja non-wajib 24 jam sebelum membeli.",
        'hutang' => "Cara melunasi hutang lebih cepat 💪\n\n1. Daftar semua hutang + bunganya.\n2. Bayar minimum semua, lalu lebihkan ke yang bunganya paling tinggi dulu.\n3. Hindari hutang baru untuk kebutuhan konsumtif.\n\nCatat juga di FinWa: _hutang ke Budi 500rb_ → cek kapan saja: _cek hutang_.",
        'utang' => "Cara melunasi utang lebih cepat 💪\n\n1. Daftar semua utang + bunganya.\n2. Bayar minimum semua, lalu lebihkan ke yang bunganya paling tinggi dulu.\n3. Hindari utang baru untuk kebutuhan konsumtif.",
        'investasi' => "Sebelum investasi, pastikan dana darurat sudah aman dulu ya 📈\n\nUntuk pemula biasanya mulai dari instrumen risiko rendah (reksa dana pasar uang, deposito), lalu pelajari bertahap. Pilih produk yang terdaftar & diawasi OJK, dan jangan tergiur janji untung pasti.\n\n_Ini info umum, bukan rekomendasi produk — untuk keputusan besar konsultasikan ke perencana keuangan berlisensi._",
        'atur keuangan' => "Cara simpel atur keuangan: pakai pola *50/30/20* 📊\n\n• 50% kebutuhan (makan, tagihan, transport)\n• 30% keinginan (hiburan, jajan)\n• 20% tabungan & investasi\n\nCatat transaksi di FinWa lalu cek polanya dengan _ringkasan bulan ini_.",
        'kelola' => "Cara simpel kelola keuangan: pakai pola *50/30/20* 📊\n\n• 50% kebutuhan • 30% keinginan • 20% tabungan\n\nCatat transaksi di FinWa lalu cek polanya dengan _ringkasan bulan ini_.",
        'budget' => "Cara bikin budget yang realistis 📊\n\n1. Lihat pengeluaran bulan lalu: _ringkasan bulan lalu_.\n2. Tentukan batas per kategori: _set budget makan 1jt_.\n3. FinWa akan mengingatkan kalau budget hampir habis.",
    ],

    'max_tokens' => (int) env('FINWA_CHAT_MAX_TOKENS', 220),
    'temperature' => (float) env('FINWA_CHAT_TEMPERATURE', 0.4),

];
