<?php

/**
 * Single source of truth untuk semua category mapping rules.
 *
 * Struktur:
 *   'expense_keywords' => [keyword => category_type]  — keyword berurutan panjang-first tidak WAJIB,
 *                                                        resolver otomatis prioritaskan match terpanjang.
 *   'income_keywords'  => [keyword => category_type]
 *   'ai_category_map'  => [kategori_dari_ai => category_type]
 *
 * Semua method di CategoryMappingService akan membaca dari sini.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Expense Keywords — keyword → pengeluaran_* category_type
    |--------------------------------------------------------------------------
    |
    | Urutan TIDAK menentukan prioritas — resolver menggunakan weighted matching
    | (frasa terpanjang yang match = pemenang). Jadi aman menambah entry baru
    | di mana saja tanpa khawatir urutan.
    |
    */
    'expense_keywords' => [

        // ── Hutang / Piutang ──
        'pelunasan hutang' => 'pengeluaran_bayar_hutang',
        'bayar hutang' => 'pengeluaran_bayar_hutang',
        'bayar utang' => 'pengeluaran_bayar_hutang',
        'lunas hutang' => 'pengeluaran_bayar_hutang',
        'balikin pinjaman' => 'pengeluaran_bayar_hutang',
        'kasih pinjam' => 'pengeluaran_piutang',
        'kasih pinjaman' => 'pengeluaran_piutang',
        'pinjamkan ke' => 'pengeluaran_piutang',
        'pijemin ke' => 'pengeluaran_piutang',
        'piutang ke' => 'pengeluaran_piutang',
        'pinjam ke' => 'pengeluaran_piutang',

        // ── Cicilan (frasa panjang — sebelum "bayar" generik) ──
        'cicilan motor' => 'pengeluaran_cicilan',
        'cicilan mobil' => 'pengeluaran_cicilan',
        'cicilan rumah' => 'pengeluaran_cicilan',
        'bayar cicilan' => 'pengeluaran_cicilan',
        'bayar angsuran' => 'pengeluaran_cicilan',
        'angsuran rumah' => 'pengeluaran_cicilan',

        // ── Tagihan spesifik (sebelum "air" yang ambigu) ──
        'bayar listrik' => 'pengeluaran_tagihan',
        'bayar air' => 'pengeluaran_tagihan',
        'bayar internet' => 'pengeluaran_tagihan',
        'bayar wifi' => 'pengeluaran_tagihan',
        'bayar pulsa' => 'pengeluaran_pulsa_token',
        'token listrik' => 'pengeluaran_pulsa_token',
        'air pdam' => 'pengeluaran_tagihan',

        // ── Acara & Hajatan (frasa panjang — sebelum makanan) ──
        'buka bersama' => 'pengeluaran_acara',
        'bukber' => 'pengeluaran_acara',
        'akad nikah' => 'pengeluaran_acara',
        'resepsi nikah' => 'pengeluaran_acara',
        'hajatan' => 'pengeluaran_acara',
        'undangan' => 'pengeluaran_acara',
        'kondangan' => 'pengeluaran_acara',
        'aqiqah' => 'pengeluaran_acara',
        'khitanan' => 'pengeluaran_acara',
        'sunatan' => 'pengeluaran_acara',
        'selamatan' => 'pengeluaran_acara',
        'syukuran' => 'pengeluaran_acara',
        'pernikahan' => 'pengeluaran_acara',
        'wisuda' => 'pengeluaran_acara',
        'katering' => 'pengeluaran_acara',
        'catering' => 'pengeluaran_acara',
        'konsumsi' => 'pengeluaran_acara',
        'dekorasi' => 'pengeluaran_acara',
        'dekor' => 'pengeluaran_acara',
        'souvenir nikah' => 'pengeluaran_acara',
        'souvenir' => 'pengeluaran_acara',
        'doorprize' => 'pengeluaran_acara',
        'event' => 'pengeluaran_acara',
        'acara' => 'pengeluaran_acara',

        // ── Makanan (frasa panjang dulu) ──
        'makan bareng' => 'pengeluaran_makanan',
        'makan siang' => 'pengeluaran_makanan',
        'makan malam' => 'pengeluaran_makanan',
        'makan pagi' => 'pengeluaran_makanan',
        'gofood' => 'pengeluaran_makanan',
        'grabfood' => 'pengeluaran_makanan',
        'shopeefood' => 'pengeluaran_makanan',
        'makan' => 'pengeluaran_makanan',
        'makanan' => 'pengeluaran_makanan',
        'minuman' => 'pengeluaran_makanan',
        'minum' => 'pengeluaran_makanan',
        'kopi' => 'pengeluaran_makanan',
        'coffee' => 'pengeluaran_makanan',
        'sarapan' => 'pengeluaran_makanan',
        'lunch' => 'pengeluaran_makanan',
        'dinner' => 'pengeluaran_makanan',
        'jajan' => 'pengeluaran_makanan',
        'snack' => 'pengeluaran_makanan',
        'cemilan' => 'pengeluaran_makanan',
        'gorengan' => 'pengeluaran_makanan',
        'risol' => 'pengeluaran_makanan',
        'martabak' => 'pengeluaran_makanan',
        'bakso' => 'pengeluaran_makanan',
        'sate' => 'pengeluaran_makanan',
        'soto' => 'pengeluaran_makanan',
        'roti' => 'pengeluaran_makanan',
        'mie' => 'pengeluaran_makanan',
        'nasi' => 'pengeluaran_makanan',
        'ayam' => 'pengeluaran_makanan',

        // ── Kesehatan ──
        'rumah sakit' => 'pengeluaran_kesehatan',
        'beli obat' => 'pengeluaran_kesehatan',
        'dokter gigi' => 'pengeluaran_kesehatan',
        'cek darah' => 'pengeluaran_kesehatan',
        'apotek' => 'pengeluaran_kesehatan',
        'dokter' => 'pengeluaran_kesehatan',
        'obat' => 'pengeluaran_kesehatan',
        'klinik' => 'pengeluaran_kesehatan',
        'puskesmas' => 'pengeluaran_kesehatan',
        'vitamin' => 'pengeluaran_kesehatan',

        // ── Perawatan Diri ──
        'facial' => 'pengeluaran_perawatan_diri',
        'cream wajah' => 'pengeluaran_perawatan_diri',
        'skincare' => 'pengeluaran_perawatan_diri',
        'sabun muka' => 'pengeluaran_perawatan_diri',
        'serum' => 'pengeluaran_perawatan_diri',
        'sunblock' => 'pengeluaran_perawatan_diri',
        'masker wajah' => 'pengeluaran_perawatan_diri',
        'lulur' => 'pengeluaran_perawatan_diri',
        'body lotion' => 'pengeluaran_perawatan_diri',
        'parfum' => 'pengeluaran_perawatan_diri',
        'minyak wangi' => 'pengeluaran_perawatan_diri',
        'potong rambut' => 'pengeluaran_perawatan_diri',
        'salon' => 'pengeluaran_perawatan_diri',
        'barbershop' => 'pengeluaran_perawatan_diri',
        'catok' => 'pengeluaran_perawatan_diri',
        'smoothing' => 'pengeluaran_perawatan_diri',
        'creambath' => 'pengeluaran_perawatan_diri',
        'hair spa' => 'pengeluaran_perawatan_diri',
        'perawatan' => 'pengeluaran_perawatan_diri',

        // ── Hiburan ──
        'top up game' => 'pengeluaran_hiburan',
        'topup game' => 'pengeluaran_hiburan',
        'voucher game' => 'pengeluaran_hiburan',
        'tiket bioskop' => 'pengeluaran_hiburan',
        'tiket konser' => 'pengeluaran_hiburan',
        'nonton bioskop' => 'pengeluaran_hiburan',
        'nonton' => 'pengeluaran_hiburan',
        'bioskop' => 'pengeluaran_hiburan',
        'cinema' => 'pengeluaran_hiburan',
        'netflix' => 'pengeluaran_langganan',
        'spotify' => 'pengeluaran_langganan',
        'karaoke' => 'pengeluaran_hiburan',
        'game' => 'pengeluaran_hiburan',
        'steam' => 'pengeluaran_hiburan',

        // ── Transport ──
        'naik grab' => 'pengeluaran_transport',
        'naik gojek' => 'pengeluaran_transport',
        'grab bike' => 'pengeluaran_transport',
        'grab car' => 'pengeluaran_transport',
        'go ride' => 'pengeluaran_transport',
        'go car' => 'pengeluaran_transport',
        'isi bensin' => 'pengeluaran_transport',
        'ngisi bensin' => 'pengeluaran_transport',
        'ongkos' => 'pengeluaran_transport',
        'ongkir' => 'pengeluaran_transport',
        'maxim' => 'pengeluaran_transport',
        'indriver' => 'pengeluaran_transport',
        'bluebird' => 'pengeluaran_transport',
        'gocar' => 'pengeluaran_transport',
        'grabcar' => 'pengeluaran_transport',
        'grab' => 'pengeluaran_transport',
        'gojek' => 'pengeluaran_transport',
        'ojol' => 'pengeluaran_transport',
        'ojek' => 'pengeluaran_transport',
        'taxi' => 'pengeluaran_transport',
        'taksi' => 'pengeluaran_transport',
        'bensin' => 'pengeluaran_transport',
        'parkir' => 'pengeluaran_transport',
        'transport' => 'pengeluaran_transport',
        'tol' => 'pengeluaran_transport',
        'transjakarta' => 'pengeluaran_transport',
        'busway' => 'pengeluaran_transport',
        'mrt' => 'pengeluaran_transport',
        'krl' => 'pengeluaran_transport',
        'commuter' => 'pengeluaran_transport',
        'pulang' => 'pengeluaran_transport',
        'pergi' => 'pengeluaran_transport',

        // ── Pendidikan ──
        'uang sekolah' => 'pengeluaran_pendidikan',
        'uang kuliah' => 'pengeluaran_pendidikan',
        'biaya sekolah' => 'pengeluaran_pendidikan',
        'biaya kuliah' => 'pengeluaran_pendidikan',
        'daftar ulang' => 'pengeluaran_pendidikan',
        'sekolah' => 'pengeluaran_pendidikan',
        'kursus' => 'pengeluaran_pendidikan',
        'les' => 'pengeluaran_pendidikan',
        'bimbel' => 'pengeluaran_pendidikan',
        'spp' => 'pengeluaran_pendidikan',
        'mengaji' => 'pengeluaran_pendidikan',
        'ngaji' => 'pengeluaran_pendidikan',
        'buku' => 'pengeluaran_pendidikan',

        // ── Hunian ──
        'sewa rumah' => 'pengeluaran_hunian',
        'sewa kos' => 'pengeluaran_hunian',
        'bayar kos' => 'pengeluaran_hunian',
        'bayar kontrakan' => 'pengeluaran_hunian',
        'kos' => 'pengeluaran_hunian',
        'kontrakan' => 'pengeluaran_hunian',
        'sewa' => 'pengeluaran_hunian',

        // ── Tagihan / Utilitas ──
        'listrik' => 'pengeluaran_tagihan',
        'pln' => 'pengeluaran_tagihan',
        'wifi' => 'pengeluaran_tagihan',
        'internet' => 'pengeluaran_tagihan',
        'indihome' => 'pengeluaran_tagihan',
        'biznet' => 'pengeluaran_tagihan',
        'bpjs' => 'pengeluaran_tagihan',

        // ── Pulsa / Token ──
        'pulsa' => 'pengeluaran_pulsa_token',
        'kuota' => 'pengeluaran_pulsa_token',
        'paket data' => 'pengeluaran_pulsa_token',
        'topup' => 'pengeluaran_pulsa_token',
        'top up' => 'pengeluaran_pulsa_token',

        // ── Asuransi ──
        'premi asuransi' => 'pengeluaran_asuransi',
        'asuransi' => 'pengeluaran_asuransi',

        // ── Pajak ──
        'bayar pajak' => 'pengeluaran_pajak',
        'pajak' => 'pengeluaran_pajak',
        'pbb' => 'pengeluaran_pajak',

        // ── Pakaian & Fashion ──
        'beli baju' => 'pengeluaran_pakaian',
        'beli sepatu' => 'pengeluaran_pakaian',
        'beli celana' => 'pengeluaran_pakaian',
        'beli jaket' => 'pengeluaran_pakaian',
        'beli tas' => 'pengeluaran_pakaian',
        'beli jilbab' => 'pengeluaran_pakaian',
        'beli kerudung' => 'pengeluaran_pakaian',
        'beli sandal' => 'pengeluaran_pakaian',
        'pakaian' => 'pengeluaran_pakaian',
        'baju' => 'pengeluaran_pakaian',
        'sepatu' => 'pengeluaran_pakaian',
        'celana' => 'pengeluaran_pakaian',
        'jaket' => 'pengeluaran_pakaian',
        'jilbab' => 'pengeluaran_pakaian',
        'kerudung' => 'pengeluaran_pakaian',
        'sandal' => 'pengeluaran_pakaian',
        'kaos' => 'pengeluaran_pakaian',
        'kemeja' => 'pengeluaran_pakaian',
        'gamis' => 'pengeluaran_pakaian',
        'hijab' => 'pengeluaran_pakaian',
        'fashion' => 'pengeluaran_pakaian',

        // ── Otomotif ──
        'servis motor' => 'pengeluaran_otomotif',
        'servis mobil' => 'pengeluaran_otomotif',
        'ganti oli' => 'pengeluaran_otomotif',
        'ganti ban' => 'pengeluaran_otomotif',
        'cuci motor' => 'pengeluaran_otomotif',
        'cuci mobil' => 'pengeluaran_otomotif',
        'tune up' => 'pengeluaran_otomotif',
        'ganti aki' => 'pengeluaran_otomotif',
        'tambal ban' => 'pengeluaran_otomotif',
        'bengkel' => 'pengeluaran_otomotif',
        'otomotif' => 'pengeluaran_otomotif',

        // ── Sosial & Kondangan ──
        'kondangan' => 'pengeluaran_sosial',
        'arisan' => 'pengeluaran_sosial',
        'kirim bunga' => 'pengeluaran_sosial',
        'papan bunga' => 'pengeluaran_sosial',
        'amplop nikahan' => 'pengeluaran_sosial',
        'amplop' => 'pengeluaran_sosial',
        'iuran warga' => 'pengeluaran_sosial',
        'iuran rt' => 'pengeluaran_sosial',
        'kas rt' => 'pengeluaran_sosial',
        'ronda' => 'pengeluaran_sosial',
        'perpisahan' => 'pengeluaran_sosial',
        'reuni' => 'pengeluaran_sosial',
        'sosial' => 'pengeluaran_sosial',

        // ── Hadiah & Bingkisan ──
        'kado ultah' => 'pengeluaran_hadiah',
        'bingkisan lebaran' => 'pengeluaran_hadiah',
        'parcel' => 'pengeluaran_hadiah',
        'hampers' => 'pengeluaran_hadiah',
        'kado' => 'pengeluaran_hadiah',
        'hadiah' => 'pengeluaran_hadiah',
        'gift' => 'pengeluaran_hadiah',

        // ── Cicilan (single) ──
        'cicilan' => 'pengeluaran_cicilan',
        'angsuran' => 'pengeluaran_cicilan',
        'kpr' => 'pengeluaran_cicilan',

        // ── Pinjaman ──
        'pinjaman' => 'pengeluaran_pinjaman',
        'kredit' => 'pengeluaran_pinjaman',
        'dana talangan' => 'pengeluaran_pinjaman',

        // ── Investasi (pengeluaran) ──
        'beli saham' => 'pengeluaran_investasi',
        'reksadana' => 'pengeluaran_investasi',
        'beli emas' => 'pengeluaran_investasi',
        'crypto' => 'pengeluaran_investasi',

        // ── Keluarga ──
        'orang tua' => 'pengeluaran_keluarga',
        'uang jajan' => 'pengeluaran_keluarga',
        'uang saku' => 'pengeluaran_keluarga',
        'uang bulanan' => 'pengeluaran_keluarga',
        'keluarga' => 'pengeluaran_keluarga',
        'ortu' => 'pengeluaran_keluarga',
        'mama' => 'pengeluaran_keluarga',
        'papa' => 'pengeluaran_keluarga',
        'istri' => 'pengeluaran_keluarga',
        'suami' => 'pengeluaran_keluarga',
        'anak' => 'pengeluaran_keluarga',
        'adik' => 'pengeluaran_keluarga',
        'kakak' => 'pengeluaran_keluarga',

        // ── Langganan ──
        'langganan' => 'pengeluaran_langganan',
        'subscription' => 'pengeluaran_langganan',

        // ── Ekspedisi & Logistik ──
        'jne' => 'pengeluaran_operasional',
        'jnt' => 'pengeluaran_operasional',
        'j&t' => 'pengeluaran_operasional',
        'sicepat' => 'pengeluaran_operasional',
        'si cepat' => 'pengeluaran_operasional',
        'anteraja' => 'pengeluaran_operasional',
        'ninja express' => 'pengeluaran_operasional',
        'ninja xpress' => 'pengeluaran_operasional',
        'pos indonesia' => 'pengeluaran_operasional',
        'tiki' => 'pengeluaran_operasional',
        'wahana' => 'pengeluaran_operasional',
        'lion parcel' => 'pengeluaran_operasional',
        'grab express' => 'pengeluaran_operasional',
        'gosend' => 'pengeluaran_operasional',
        'drop j&t' => 'pengeluaran_operasional',
        'drop jnt' => 'pengeluaran_operasional',
        'drop sicepat' => 'pengeluaran_operasional',
        'drop paket' => 'pengeluaran_operasional',
        'drop barang' => 'pengeluaran_operasional',
        'kirim barang' => 'pengeluaran_operasional',
        'pengiriman' => 'pengeluaran_operasional',
        'kurir' => 'pengeluaran_operasional',
        'resi' => 'pengeluaran_operasional',

        // ── UMKM / Bisnis ──
        'beli stok' => 'pengeluaran_modal',
        'beli bahan' => 'pengeluaran_modal',
        'bayar supplier' => 'pengeluaran_modal',
        'modal' => 'pengeluaran_modal',
        'kulakan' => 'pengeluaran_modal',
        'stok' => 'pengeluaran_modal',
        'bahan baku' => 'pengeluaran_modal',
        'supplier' => 'pengeluaran_modal',
        'operasional' => 'pengeluaran_operasional',
        'packaging' => 'pengeluaran_operasional',
        'ekspedisi' => 'pengeluaran_operasional',

        // ── Donasi / Amal ──
        'anak yatim' => 'pengeluaran_donasi',
        'zakat fitrah' => 'pengeluaran_donasi',
        'zakat mal' => 'pengeluaran_donasi',
        'zakat penghasilan' => 'pengeluaran_donasi',
        'sedekah' => 'pengeluaran_donasi',
        'infaq' => 'pengeluaran_donasi',
        'infak' => 'pengeluaran_donasi',
        'zakat' => 'pengeluaran_donasi',
        'donasi' => 'pengeluaran_donasi',
        'sumbangan' => 'pengeluaran_donasi',
        'amal' => 'pengeluaran_donasi',
        'santunan' => 'pengeluaran_donasi',
        'wakaf' => 'pengeluaran_donasi',
        'qurban' => 'pengeluaran_donasi',
        'piatu' => 'pengeluaran_donasi',

        // ── Transfer ──
        'transfer' => 'pengeluaran_transfer',
        'kirim uang' => 'pengeluaran_transfer',
        'kirim duit' => 'pengeluaran_transfer',
        'pindah' => 'pengeluaran_transfer',
        'pindah saldo' => 'pengeluaran_transfer',

        // ── Gaji karyawan ──
        'gaji' => 'pengeluaran_gaji',
        'upah' => 'pengeluaran_gaji',
        'honor' => 'pengeluaran_gaji',

        // ── Baby items (sebelum catch-all belanja) ──
        'kantong asi' => 'pengeluaran_belanja',
        'popok' => 'pengeluaran_belanja',
        'pampers' => 'pengeluaran_belanja',
        'diapers' => 'pengeluaran_belanja',
        'susu' => 'pengeluaran_belanja',

        // ── Laundry ──
        'laundry' => 'pengeluaran_lainnya',
        'cuci baju' => 'pengeluaran_lainnya',
        'cuci' => 'pengeluaran_lainnya',
        'setrika' => 'pengeluaran_lainnya',

        // ── Transfer generik ──
        'kirim' => 'pengeluaran_transfer',
        'setor' => 'pengeluaran_lainnya',

        // ── Belanja (catch-all — low priority) ──
        'belanja' => 'pengeluaran_belanja',
        'shopee' => 'pengeluaran_belanja',
        'tokped' => 'pengeluaran_belanja',
        'tokopedia' => 'pengeluaran_belanja',
        'alfamart' => 'pengeluaran_belanja',
        'indomaret' => 'pengeluaran_belanja',
        'supermarket' => 'pengeluaran_belanja',
        'minimarket' => 'pengeluaran_belanja',
        'pasar' => 'pengeluaran_belanja',
        'beli' => 'pengeluaran_belanja',
        'shopping' => 'pengeluaran_belanja',
        'bayar' => 'pengeluaran_belanja',

        // ── Lainnya ──
        'kasih' => 'pengeluaran_lainnya',
        'ngasih' => 'pengeluaran_lainnya',
        'kirimin' => 'pengeluaran_lainnya',
        'air' => 'pengeluaran_utilitas',
        'utilitas' => 'pengeluaran_utilitas',
        'lainnya' => 'pengeluaran_lainnya',
    ],

    /*
    |--------------------------------------------------------------------------
    | Income Keywords — keyword → pendapatan_* category_type
    |--------------------------------------------------------------------------
    */
    'income_keywords' => [

        // ── Frasa panjang (highest priority via weighted match) ──
        'pelunasan piutang' => 'pendapatan_terima_piutang',
        'terima piutang' => 'pendapatan_terima_piutang',
        'terima pelunasan' => 'pendapatan_terima_piutang',
        'piutang lunas' => 'pendapatan_terima_piutang',
        'piutang masuk' => 'pendapatan_terima_piutang',
        'dapat pinjaman' => 'pendapatan_hutang',
        'terima pinjaman' => 'pendapatan_hutang',
        'pinjaman dari' => 'pendapatan_hutang',
        'pinjam dari' => 'pendapatan_hutang',
        'hutang dari' => 'pendapatan_hutang',
        'dipinjemin' => 'pendapatan_hutang',
        'cairin pinjaman' => 'pendapatan_hutang',
        'bagi hasil' => 'pendapatan_investasi',
        'transfer masuk' => 'pendapatan_transfer',
        'terima transfer' => 'pendapatan_transfer',
        'dapat transfer' => 'pendapatan_transfer',

        // ── Gaji ──
        'gajian' => 'pendapatan_gaji',
        'gaji' => 'pendapatan_gaji',
        'salary' => 'pendapatan_gaji',
        'honor' => 'pendapatan_gaji',
        'upah' => 'pendapatan_gaji',
        'fee' => 'pendapatan_gaji',
        'komisi' => 'pendapatan_gaji',

        // ── Bonus ──
        'thr' => 'pendapatan_bonus',
        'bonus' => 'pendapatan_bonus',
        'insentif' => 'pendapatan_bonus',
        'tunjangan' => 'pendapatan_bonus',
        'hadiah' => 'pendapatan_bonus',
        'angpao' => 'pendapatan_bonus',
        'angpau' => 'pendapatan_bonus',

        // ── Investasi ──
        'dividen' => 'pendapatan_investasi',
        'profit' => 'pendapatan_investasi',
        'keuntungan' => 'pendapatan_investasi',
        'return' => 'pendapatan_investasi',
        'bunga' => 'pendapatan_investasi',
        'investasi' => 'pendapatan_investasi',

        // ── Transfer ──
        'kiriman' => 'pendapatan_transfer',
        'transfer' => 'pendapatan_transfer',
        'dikasih' => 'pendapatan_transfer',
        'titip' => 'pendapatan_transfer',

        // ── UMKM / Bisnis ──
        'penjualan' => 'pendapatan_usaha',
        'omset' => 'pendapatan_usaha',
        'omzet' => 'pendapatan_usaha',
        'jualan' => 'pendapatan_usaha',
        'jual' => 'pendapatan_usaha',
        'laku' => 'pendapatan_usaha',
        'laris' => 'pendapatan_usaha',
        'order masuk' => 'pendapatan_usaha',
        'orderan' => 'pendapatan_usaha',
        'dp masuk' => 'pendapatan_usaha',
        'closing' => 'pendapatan_usaha',
        'deal' => 'pendapatan_usaha',
        'cuan' => 'pendapatan_usaha',
        'untung' => 'pendapatan_usaha',
        'hasil jualan' => 'pendapatan_usaha',
        'pendapatan usaha' => 'pendapatan_usaha',

        // ── Sewa / Rental ──
        'sewa rumah masuk' => 'pendapatan_sewa',
        'kos masuk' => 'pendapatan_sewa',
        'kontrakan masuk' => 'pendapatan_sewa',
        'uang sewa' => 'pendapatan_sewa',
        'uang kos' => 'pendapatan_sewa',
        'rental masuk' => 'pendapatan_sewa',
        'hasil sewa' => 'pendapatan_sewa',
        'pendapatan sewa' => 'pendapatan_sewa',
        'sewa masuk' => 'pendapatan_sewa',

        // ── Refund & Cashback ──
        'refund' => 'pendapatan_refund',
        'retur' => 'pendapatan_refund',
        'cashback' => 'pendapatan_refund',
        'uang kembali' => 'pendapatan_refund',
        'pengembalian' => 'pendapatan_refund',
        'klaim asuransi' => 'pendapatan_refund',

        // ── Lainnya ──
        'freelance' => 'pendapatan_lainnya',
        'proyek' => 'pendapatan_lainnya',
        'penerimaan' => 'pendapatan_lainnya',
        'undian' => 'pendapatan_lainnya',
        'keluarga' => 'pendapatan_lainnya',
        'suami' => 'pendapatan_lainnya',
        'istri' => 'pendapatan_lainnya',
        'pacar' => 'pendapatan_lainnya',
        'papi' => 'pendapatan_lainnya',
        'mama' => 'pendapatan_lainnya',
        'ortu' => 'pendapatan_lainnya',
        'pemberian' => 'pendapatan_lainnya',
        'saldo awal' => 'pendapatan_lainnya',
        'lainnya' => 'pendapatan_lainnya',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Category Map — kategori dari AI response → category_type
    |--------------------------------------------------------------------------
    |
    | Digunakan oleh mapFinwaKategoriToCategoryType() untuk direct lookup.
    | Weighted partial matching otomatis aktif jika direct match gagal.
    |
    */
    'ai_category_map' => [
        // Pendapatan
        'salary' => ['pendapatan_gaji'],
        'upah' => ['pendapatan_gaji', 'pengeluaran_gaji'],
        'honor' => ['pendapatan_gaji'],
        'bonus' => ['pendapatan_bonus'],
        'thr' => ['pendapatan_bonus'],
        'komisi' => ['pendapatan_bonus'],
        'investasi' => ['pendapatan_investasi'],
        'dividen' => ['pendapatan_investasi'],
        'bunga' => ['pendapatan_investasi'],
        'bagi hasil' => ['pendapatan_investasi'],
        'transfer' => ['pendapatan_transfer', 'pengeluaran_transfer'],
        'kirim' => ['pengeluaran_transfer'],
        'pindah' => ['pengeluaran_transfer'],
        'pindah saldo' => ['pengeluaran_transfer'],

        // Pendapatan — usaha
        'penjualan' => ['pendapatan_usaha'],
        'omset' => ['pendapatan_usaha'],
        'omzet' => ['pendapatan_usaha'],
        'jualan' => ['pendapatan_usaha'],
        'closing' => ['pendapatan_usaha'],
        'cuan' => ['pendapatan_usaha'],
        'untung' => ['pendapatan_usaha'],

        // Pendapatan — sewa
        'sewa rumah' => ['pendapatan_sewa'],
        'uang kos' => ['pendapatan_sewa'],
        'kontrakan' => ['pendapatan_sewa', 'pengeluaran_hunian'],
        'rental' => ['pendapatan_sewa'],

        // Pendapatan — refund
        'refund' => ['pendapatan_refund'],
        'retur' => ['pendapatan_refund'],
        'cashback' => ['pendapatan_refund'],
        'pengembalian' => ['pendapatan_refund'],

        // Pengeluaran — makanan
        'makan' => ['pengeluaran_makanan'],
        'makanan' => ['pengeluaran_makanan'],
        'minuman' => ['pengeluaran_makanan'],
        'food' => ['pengeluaran_makanan'],
        'makan bareng' => ['pengeluaran_makanan'],

        // Pengeluaran — acara & hajatan
        'acara' => ['pengeluaran_acara'],
        'hajatan' => ['pengeluaran_acara'],
        'event' => ['pengeluaran_acara'],
        'buka bersama' => ['pengeluaran_acara'],
        'bukber' => ['pengeluaran_acara'],
        'nikahan' => ['pengeluaran_acara'],
        'aqiqah' => ['pengeluaran_acara'],
        'selamatan' => ['pengeluaran_acara'],
        'wisuda' => ['pengeluaran_acara'],
        'catering' => ['pengeluaran_acara'],
        'konsumsi' => ['pengeluaran_acara'],
        'dekorasi' => ['pengeluaran_acara'],
        'souvenir' => ['pengeluaran_acara'],

        // Pengeluaran — transport
        'transport' => ['pengeluaran_transport'],
        'transportasi' => ['pengeluaran_transport'],
        'bensin' => ['pengeluaran_transport'],
        'parkir' => ['pengeluaran_transport'],
        'ojek' => ['pengeluaran_transport'],
        'grab' => ['pengeluaran_transport'],
        'gojek' => ['pengeluaran_transport'],

        // Pengeluaran — hunian
        'hunian' => ['pengeluaran_hunian'],
        'rumah' => ['pengeluaran_hunian'],
        'kos' => ['pengeluaran_hunian'],
        'sewa' => ['pengeluaran_hunian'],

        // Pengeluaran — utilitas
        'utilitas' => ['pengeluaran_utilitas'],
        'listrik' => ['pengeluaran_utilitas'],
        'air' => ['pengeluaran_utilitas'],
        'internet' => ['pengeluaran_utilitas'],
        'wifi' => ['pengeluaran_utilitas'],

        // Pengeluaran — kesehatan
        'kesehatan' => ['pengeluaran_kesehatan'],
        'obat' => ['pengeluaran_kesehatan'],
        'dokter' => ['pengeluaran_kesehatan'],
        'rumah sakit' => ['pengeluaran_kesehatan'],
        'klinik' => ['pengeluaran_kesehatan'],
        'apotek' => ['pengeluaran_kesehatan'],

        // Pengeluaran — perawatan diri
        'perawatan' => ['pengeluaran_perawatan_diri'],
        'perawatan_diri' => ['pengeluaran_perawatan_diri'],
        'skincare' => ['pengeluaran_perawatan_diri'],
        'salon' => ['pengeluaran_perawatan_diri'],
        'barbershop' => ['pengeluaran_perawatan_diri'],
        'potong rambut' => ['pengeluaran_perawatan_diri'],
        'facial' => ['pengeluaran_perawatan_diri'],
        'parfum' => ['pengeluaran_perawatan_diri'],

        // Pengeluaran — pendidikan
        'pendidikan' => ['pengeluaran_pendidikan'],
        'sekolah' => ['pengeluaran_pendidikan'],
        'buku' => ['pengeluaran_pendidikan'],

        // Pengeluaran — belanja
        'belanja' => ['pengeluaran_belanja'],
        'shopping' => ['pengeluaran_belanja'],

        // Pengeluaran — pakaian & fashion
        'pakaian' => ['pengeluaran_pakaian'],
        'baju' => ['pengeluaran_pakaian'],
        'sepatu' => ['pengeluaran_pakaian'],
        'fashion' => ['pengeluaran_pakaian'],
        'jilbab' => ['pengeluaran_pakaian'],
        'hijab' => ['pengeluaran_pakaian'],

        // Pengeluaran — otomotif
        'otomotif' => ['pengeluaran_otomotif'],
        'servis motor' => ['pengeluaran_otomotif'],
        'servis mobil' => ['pengeluaran_otomotif'],
        'bengkel' => ['pengeluaran_otomotif'],

        // Pengeluaran — sosial
        'sosial' => ['pengeluaran_sosial'],
        'kondangan' => ['pengeluaran_sosial'],
        'arisan' => ['pengeluaran_sosial'],
        'reuni' => ['pengeluaran_sosial'],

        // Pengeluaran — hadiah
        'hadiah' => ['pengeluaran_hadiah', 'pendapatan_bonus'],
        'kado' => ['pengeluaran_hadiah'],
        'gift' => ['pengeluaran_hadiah'],
        'bingkisan' => ['pengeluaran_hadiah'],
        'hampers' => ['pengeluaran_hadiah'],

        // Pengeluaran — hiburan
        'hiburan' => ['pengeluaran_hiburan'],
        'entertainment' => ['pengeluaran_hiburan'],
        'nonton' => ['pengeluaran_hiburan'],
        'game' => ['pengeluaran_hiburan'],

        // Pengeluaran — pulsa/token
        'pulsa' => ['pengeluaran_pulsa_token'],
        'token' => ['pengeluaran_pulsa_token'],
        'kuota' => ['pengeluaran_pulsa_token'],

        // Pengeluaran — tagihan
        'tagihan' => ['pengeluaran_tagihan'],
        'bill' => ['pengeluaran_tagihan'],

        // Pengeluaran — investasi
        'invest' => ['pengeluaran_investasi'],

        // Pengeluaran — pinjaman/cicilan
        'pinjaman' => ['pengeluaran_pinjaman'],
        'cicilan' => ['pengeluaran_cicilan'],
        'angsuran' => ['pengeluaran_cicilan'],
        'kredit' => ['pengeluaran_cicilan'],
        'kpr' => ['pengeluaran_cicilan'],
        'cicilan motor' => ['pengeluaran_cicilan'],
        'cicilan mobil' => ['pengeluaran_cicilan'],
        'cicilan rumah' => ['pengeluaran_cicilan'],

        // Pengeluaran — asuransi/pajak
        'asuransi' => ['pengeluaran_asuransi'],
        'insurance' => ['pengeluaran_asuransi'],
        'pajak' => ['pengeluaran_pajak'],
        'tax' => ['pengeluaran_pajak'],

        // Pengeluaran — donasi
        'donasi' => ['pengeluaran_donasi'],
        'sedekah' => ['pengeluaran_donasi'],
        'zakat' => ['pengeluaran_donasi'],
        'infaq' => ['pengeluaran_donasi'],
        'infak' => ['pengeluaran_donasi'],
        'santunan' => ['pengeluaran_donasi'],
        'wakaf' => ['pengeluaran_donasi'],
        'anak yatim' => ['pengeluaran_donasi'],
        'piatu' => ['pengeluaran_donasi'],

        // Pengeluaran — gaji (juga termasuk income di atas)
        'gaji' => ['pendapatan_gaji', 'pengeluaran_gaji'],

        // Pengeluaran — keluarga
        'keluarga' => ['pengeluaran_keluarga'],
        'orang tua' => ['pengeluaran_keluarga'],
        'ortu' => ['pengeluaran_keluarga'],
        'ibu' => ['pengeluaran_keluarga'],
        'bapak' => ['pengeluaran_keluarga'],
        'ayah' => ['pengeluaran_keluarga'],
        'mama' => ['pengeluaran_keluarga'],
        'papa' => ['pengeluaran_keluarga'],
        'istri' => ['pengeluaran_keluarga'],
        'suami' => ['pengeluaran_keluarga'],
        'anak' => ['pengeluaran_keluarga'],
        'adik' => ['pengeluaran_keluarga'],
        'kakak' => ['pengeluaran_keluarga'],
        'kasih' => ['pengeluaran_keluarga', 'pengeluaran_lainnya'],

        // Pengeluaran — langganan
        'langganan' => ['pengeluaran_langganan'],
        'subscription' => ['pengeluaran_langganan'],

        // Pengeluaran — modal/operasional
        'modal' => ['pengeluaran_modal'],
        'kulakan' => ['pengeluaran_modal'],
        'stok' => ['pengeluaran_modal'],
        'bahan baku' => ['pengeluaran_modal'],
        'supplier' => ['pengeluaran_modal'],
        'operasional' => ['pengeluaran_operasional'],
        'packaging' => ['pengeluaran_operasional'],
        'ekspedisi' => ['pengeluaran_operasional'],

        // Pengeluaran — hutang/piutang
        'pelunasan hutang' => ['pengeluaran_bayar_hutang'],
        'bayar hutang' => ['pengeluaran_bayar_hutang'],
        'bayar utang' => ['pengeluaran_bayar_hutang'],
        'kasih pinjam' => ['pengeluaran_piutang'],
        'pinjamkan ke' => ['pengeluaran_piutang'],

        // Default
        'lainnya' => ['pengeluaran_lainnya', 'pendapatan_lainnya'],
        'setor' => ['pengeluaran_lainnya'],
        'penerimaan' => ['pendapatan_lainnya'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Income Detection Keywords
    |--------------------------------------------------------------------------
    |
    | Keywords yang menandai pesan sebagai pemasukan di local extraction.
    | Digunakan oleh TransactionExtractorService untuk menentukan income/expense
    | SEBELUM kategori ditentukan.
    |
    */
    'income_detection_keywords' => [
        'gaji', 'bonus', 'terima', 'penerimaan', 'dapat', 'dapet', 'pemasukan', 'pendapatan',
        'honor', 'upah', 'transfer masuk', 'thr', 'uang masuk', 'duit masuk', 'masuk',
        'dikasih', 'dikasi', 'dari papi', 'dari papa', 'dari mama', 'dari mami',
        'dari ortu', 'dari orang tua', 'dari ayah', 'dari ibu', 'dari bapak',
        'hadiah', 'kado', 'angpao', 'sumbangan', 'kiriman',
        'dikirim', 'dikirimin', 'ditransfer', 'di transfer',
        'saldo awal',
        'masuk duit', 'dapet duit', 'dapat duit', 'dapet transferan',
        'nyangkut', 'cair', 'pencairan',
        'penjualan', 'omset', 'jual',
        'laku', 'laris', 'order masuk', 'orderan masuk', 'dp masuk', 'down payment',
        'piutang lunas', 'piutang masuk', 'tagihan dibayar', 'closing', 'deal',
        'cash masuk', 'tunai masuk', 'hasil jualan', 'untung', 'profit', 'cuan',
        'pembayaran customer', 'bayar customer', 'pelanggan bayar', 'customer bayar',
        'masuk pembayaran', 'pembayaran masuk', 'terima pembayaran', 'penerimaan pembayaran',
        'narik grab', 'narik gojek', 'narik maxim', 'narik indrive', 'narik',
        'ojol masuk', 'grab masuk', 'gojek masuk', 'trip grab', 'trip gojek',
    ],

    /*
    |--------------------------------------------------------------------------
    | Expense Detection Patterns
    |--------------------------------------------------------------------------
    |
    | Patterns yang menandai pesan sebagai pengeluaran di local extraction.
    | Jika pattern ini match, income detection di-skip.
    |
    */
    'expense_detection_patterns' => [
        'beli obat', 'bayar obat', 'beli vitamin', 'bayar apotek',
        'beli pulsa', 'isi pulsa', 'bayar pulsa', 'beli kuota', 'isi kuota',
        'beli paket', 'isi paket',
        'top up gopay', 'topup gopay', 'isi gopay', 'top up ovo', 'topup ovo', 'isi ovo',
        'top up dana', 'topup dana', 'isi dana', 'top up shopeepay', 'isi shopeepay',
        'beli tiket', 'bayar tiket', 'tiket nonton', 'tiket bioskop', 'tiket konser',
        'beli rokok', 'beli skincare', 'beli makeup', 'beli kosmetik',
        'bayar kos', 'bayar kost', 'bayar kontrakan', 'bayar sewa',
        'bayar spp', 'uang spp', 'bayar les', 'uang les', 'bayar kursus', 'uang kursus',
        'bayar kuliah', 'beli buku',
        'mengaji', 'ngaji', 'daftar sekolah', 'daftar les', 'daftar kursus', 'daftar mengaji',
        'potong rambut', 'pangkas rambut', 'cukur',
        'gaji karyawan', 'upah karyawan', 'honor karyawan', 'bayar gaji', 'bayar thr',
        'pengeluaran gaji', 'pengeluaran upah', 'pengeluaran honor', 'pengeluaran thr',
        'ambil gaji', 'ambil upah', 'ambil honor', 'sudah ambil', 'ngambil gaji',
        'beli', 'belanja', 'jajan', 'pesan', 'pesen', 'checkout', 'borong', 'ngemil', 'nyemil',
        'abis duit', 'habis duit', 'keluar duit', 'abis uang', 'habis uang', 'keluar uang',
        'ngeluarin', 'keluarin', 'abis buat', 'habis buat', 'keluar buat',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Category Overrides — keyword → kategori override
    |--------------------------------------------------------------------------
    |
    | Digunakan di TransactionService (AI fast path) untuk meng-override kategori
    | dari AI jika keyword spesifik terdeteksi di pesan.
    | SEMUA entry di sini = ALWAYS expense ($isIncome dipaksa false).
    |
    | 'keyword' => 'kategori_nama' (tanpa prefix pengeluaran_)
    |
    */
    'ai_category_overrides' => [
        // Streaming & Langganan
        'netflix' => 'langganan', 'spotify' => 'langganan',
        'youtube premium' => 'langganan', 'youtube music' => 'langganan',
        'disney+' => 'langganan', 'disney plus' => 'langganan',
        'hbomax' => 'langganan', 'hbo max' => 'langganan',
        'prime video' => 'langganan', 'vidio' => 'langganan',
        'viu' => 'langganan', 'iqiyi' => 'langganan',
        'apple music' => 'langganan', 'joox' => 'langganan',
        'deezer' => 'langganan', 'tidal' => 'langganan',
        'wetv' => 'langganan', 'bstation' => 'langganan', 'bilibili' => 'langganan',

        // Hiburan
        'game' => 'hiburan', 'steam' => 'hiburan', 'voucher game' => 'hiburan',
        'nonton' => 'hiburan', 'bioskop' => 'hiburan', 'cinema' => 'hiburan',

        // Makanan (yang sering salah AI)
        'gorengan' => 'makanan', 'bakwan' => 'makanan', 'martabak' => 'makanan',
        'snack' => 'makanan', 'jajan' => 'makanan', 'cemilan' => 'makanan',
        'cilok' => 'makanan', 'cireng' => 'makanan', 'batagor' => 'makanan', 'siomay' => 'makanan',

        // Donasi (AI sering salah → makanan/belanja)
        'donasi' => 'donasi', 'sedekah' => 'donasi', 'infaq' => 'donasi', 'infak' => 'donasi',
        'zakat' => 'donasi', 'sumbangan' => 'donasi', 'amal' => 'donasi',
        'santunan' => 'donasi', 'wakaf' => 'donasi', 'qurban' => 'donasi', 'anak yatim' => 'donasi',

        // Sosial & Kondangan (SEBELUM acara supaya 'amplop nikahan' menang atas 'nikahan')
        'amplop nikahan' => 'sosial', 'amplop' => 'sosial',
        'kondangan' => 'sosial', 'arisan' => 'sosial',
        'kirim bunga' => 'sosial', 'papan bunga' => 'sosial',
        'iuran warga' => 'sosial', 'iuran rt' => 'sosial', 'kas rt' => 'sosial',
        'ronda' => 'sosial', 'reuni' => 'sosial',

        // Acara & Hajatan
        'konsumsi' => 'acara', 'catering' => 'acara', 'katering' => 'acara',
        'hajatan' => 'acara', 'aqiqah' => 'acara', 'khitanan' => 'acara',
        'sunatan' => 'acara', 'selamatan' => 'acara', 'syukuran' => 'acara',
        'dekorasi' => 'acara', 'dekor' => 'acara', 'souvenir' => 'acara',
        'doorprize' => 'acara', 'wisuda' => 'acara', 'nikahan' => 'acara',
        'pernikahan' => 'acara', 'buka bersama' => 'acara', 'bukber' => 'acara',

        // Perawatan Diri
        'salon' => 'perawatan_diri', 'barbershop' => 'perawatan_diri',
        'potong rambut' => 'perawatan_diri', 'facial' => 'perawatan_diri',
        'skincare' => 'perawatan_diri', 'cream wajah' => 'perawatan_diri',
        'sabun muka' => 'perawatan_diri', 'serum' => 'perawatan_diri',
        'creambath' => 'perawatan_diri', 'catok' => 'perawatan_diri',
        'smoothing' => 'perawatan_diri', 'parfum' => 'perawatan_diri',
        'minyak wangi' => 'perawatan_diri', 'lulur' => 'perawatan_diri',
        'body lotion' => 'perawatan_diri',

        // Pakaian & Fashion
        'beli baju' => 'pakaian', 'beli sepatu' => 'pakaian',
        'beli celana' => 'pakaian', 'beli jaket' => 'pakaian',
        'beli tas' => 'pakaian', 'beli jilbab' => 'pakaian',
        'beli kerudung' => 'pakaian', 'beli sandal' => 'pakaian',

        // Otomotif
        'servis motor' => 'otomotif', 'servis mobil' => 'otomotif',
        'ganti oli' => 'otomotif', 'ganti ban' => 'otomotif',
        'cuci motor' => 'otomotif', 'cuci mobil' => 'otomotif',
        'tune up' => 'otomotif', 'bengkel' => 'otomotif',
        'tambal ban' => 'otomotif', 'ganti aki' => 'otomotif',

        // Hadiah & Bingkisan
        'kado ultah' => 'hadiah', 'bingkisan lebaran' => 'hadiah',
        'parcel' => 'hadiah', 'hampers' => 'hadiah',

        // Pendidikan
        'mengaji' => 'pendidikan', 'ngaji' => 'pendidikan',
        'sekolah mengaji' => 'pendidikan', 'daftar sekolah' => 'pendidikan',
        'daftar les' => 'pendidikan', 'daftar kursus' => 'pendidikan',

        // Gaji (khusus expense override)
        'gaji karyawan' => 'gaji', 'upah karyawan' => 'gaji', 'bayar karyawan' => 'gaji',
    ],

    /*
    |--------------------------------------------------------------------------
    | Local Extraction Extras — keyword → category_type
    |--------------------------------------------------------------------------
    |
    | Keyword tambahan yang HANYA digunakan oleh TransactionExtractorService
    | untuk local extraction (Fast Path 2 / $finwaEntities = null).
    | Brand names, slang, dan kombinasi spesifik yang tidak ada di
    | expense_keywords / income_keywords utama.
    |
    | Otomatis di-merge dengan expense_keywords / income_keywords saat runtime.
    |
    */
    'local_expense_extras' => [
        // Health with "beli/bayar" prefix
        'beli obat' => 'pengeluaran_kesehatan', 'bayar obat' => 'pengeluaran_kesehatan',
        'beli vitamin' => 'pengeluaran_kesehatan', 'bayar apotek' => 'pengeluaran_kesehatan',

        // Pulsa with "beli/isi" prefix
        'beli pulsa' => 'pengeluaran_pulsa_token', 'isi pulsa' => 'pengeluaran_pulsa_token',
        'bayar pulsa' => 'pengeluaran_pulsa_token', 'beli kuota' => 'pengeluaran_pulsa_token',
        'isi kuota' => 'pengeluaran_pulsa_token', 'beli paket' => 'pengeluaran_pulsa_token',
        'isi paket' => 'pengeluaran_pulsa_token',

        // E-wallet top-up
        'top up gopay' => 'pengeluaran_lainnya', 'topup gopay' => 'pengeluaran_lainnya',
        'isi gopay' => 'pengeluaran_lainnya', 'top up ovo' => 'pengeluaran_lainnya',
        'topup ovo' => 'pengeluaran_lainnya', 'isi ovo' => 'pengeluaran_lainnya',
        'top up dana' => 'pengeluaran_lainnya', 'topup dana' => 'pengeluaran_lainnya',
        'isi dana' => 'pengeluaran_lainnya', 'top up shopeepay' => 'pengeluaran_lainnya',
        'isi shopeepay' => 'pengeluaran_lainnya',

        // Groceries
        'beli sayur' => 'pengeluaran_makanan', 'belanja sayur' => 'pengeluaran_makanan',
        'beli bahan' => 'pengeluaran_makanan', 'belanja bahan' => 'pengeluaran_makanan',
        'beli bumbu' => 'pengeluaran_makanan', 'belanja dapur' => 'pengeluaran_makanan',
        'belanja bulanan' => 'pengeluaran_belanja', 'grocery' => 'pengeluaran_makanan',
        'groceries' => 'pengeluaran_makanan',

        // Entertainment tickets
        'beli tiket' => 'pengeluaran_hiburan', 'bayar tiket' => 'pengeluaran_hiburan',
        'tiket nonton' => 'pengeluaran_hiburan',

        // Personal items with "beli" prefix
        'beli rokok' => 'pengeluaran_lainnya', 'rokok' => 'pengeluaran_lainnya',
        'beli jam' => 'pengeluaran_belanja', 'beli hp' => 'pengeluaran_belanja',
        'beli laptop' => 'pengeluaran_belanja',

        // Beauty with "beli" prefix
        'beli skincare' => 'pengeluaran_perawatan_diri', 'beli makeup' => 'pengeluaran_perawatan_diri',
        'beli kosmetik' => 'pengeluaran_perawatan_diri', 'pangkas rambut' => 'pengeluaran_perawatan_diri',
        'cukur' => 'pengeluaran_perawatan_diri',

        // Education with "bayar/uang" prefix
        'bayar spp' => 'pengeluaran_pendidikan', 'uang spp' => 'pengeluaran_pendidikan',
        'bayar les' => 'pengeluaran_pendidikan', 'uang les' => 'pengeluaran_pendidikan',
        'bayar kursus' => 'pengeluaran_pendidikan', 'uang kursus' => 'pengeluaran_pendidikan',
        'bayar kuliah' => 'pengeluaran_pendidikan', 'beli buku' => 'pengeluaran_pendidikan',

        // Housing with "bayar/uang" prefix
        'bayar kos' => 'pengeluaran_hunian', 'bayar kost' => 'pengeluaran_hunian',
        'bayar kontrakan' => 'pengeluaran_hunian', 'bayar sewa' => 'pengeluaran_hunian',
        'uang kost' => 'pengeluaran_hunian',

        // Streaming (brand names)
        'youtube premium' => 'pengeluaran_langganan', 'youtube music' => 'pengeluaran_langganan',
        'disney+' => 'pengeluaran_langganan', 'disney plus' => 'pengeluaran_langganan',
        'hbomax' => 'pengeluaran_langganan', 'hbo max' => 'pengeluaran_langganan',
        'prime video' => 'pengeluaran_langganan', 'vidio' => 'pengeluaran_langganan',
        'viu' => 'pengeluaran_langganan', 'iqiyi' => 'pengeluaran_langganan',
        'apple music' => 'pengeluaran_langganan', 'joox' => 'pengeluaran_langganan',
        'deezer' => 'pengeluaran_langganan', 'tidal' => 'pengeluaran_langganan',
        'wetv' => 'pengeluaran_langganan', 'bstation' => 'pengeluaran_langganan',
        'bilibili' => 'pengeluaran_langganan',

        // Makanan slang
        'mkn' => 'pengeluaran_makanan', 'maem' => 'pengeluaran_makanan',
        'mamam' => 'pengeluaran_makanan', 'nyemil' => 'pengeluaran_makanan',
        'ngemil' => 'pengeluaran_makanan', 'bukber bareng' => 'pengeluaran_makanan',
        'makan bersama' => 'pengeluaran_makanan',

        // Jenis makanan spesifik
        'chicken' => 'pengeluaran_makanan', 'geprek' => 'pengeluaran_makanan',
        'indomie' => 'pengeluaran_makanan', 'pizza' => 'pengeluaran_makanan',
        'burger' => 'pengeluaran_makanan', 'rawon' => 'pengeluaran_makanan',
        'rendang' => 'pengeluaran_makanan', 'gudeg' => 'pengeluaran_makanan',
        'pecel' => 'pengeluaran_makanan', 'gado' => 'pengeluaran_makanan',
        'ketoprak' => 'pengeluaran_makanan', 'bubur' => 'pengeluaran_makanan',
        'lontong' => 'pengeluaran_makanan', 'donat' => 'pengeluaran_makanan',
        'kue' => 'pengeluaran_makanan', 'pempek' => 'pengeluaran_makanan',
        'nasgor' => 'pengeluaran_makanan', 'nasgep' => 'pengeluaran_makanan',

        // Restoran / Brand
        'mcd' => 'pengeluaran_makanan', 'mcdonalds' => 'pengeluaran_makanan',
        'kfc' => 'pengeluaran_makanan', 'hokben' => 'pengeluaran_makanan',
        'yoshinoya' => 'pengeluaran_makanan', 'solaria' => 'pengeluaran_makanan',
        'warteg' => 'pengeluaran_makanan', 'warung' => 'pengeluaran_makanan',
        'resto' => 'pengeluaran_makanan', 'cafe' => 'pengeluaran_makanan',
        'kantin' => 'pengeluaran_makanan', 'foodcourt' => 'pengeluaran_makanan',
        'pizza hut' => 'pengeluaran_makanan', 'phd' => 'pengeluaran_makanan',
        'dominos' => 'pengeluaran_makanan', 'jco' => 'pengeluaran_makanan',
        'dunkin' => 'pengeluaran_makanan',

        // Minuman & Kopi brands
        'ngopi' => 'pengeluaran_makanan', 'starbucks' => 'pengeluaran_makanan',
        'sbux' => 'pengeluaran_makanan', 'sbx' => 'pengeluaran_makanan',
        'janji jiwa' => 'pengeluaran_makanan', 'kopi kenangan' => 'pengeluaran_makanan',
        'fore' => 'pengeluaran_makanan', 'tomoro' => 'pengeluaran_makanan',
        'mixue' => 'pengeluaran_makanan', 'chatime' => 'pengeluaran_makanan',
        'xiboba' => 'pengeluaran_makanan', 'boba' => 'pengeluaran_makanan',
        'teh' => 'pengeluaran_makanan', 'es teh' => 'pengeluaran_makanan',
        'es' => 'pengeluaran_makanan', 'es jeruk' => 'pengeluaran_makanan',
        'es kopi' => 'pengeluaran_makanan', 'es buah' => 'pengeluaran_makanan',
        'es campur' => 'pengeluaran_makanan', 'es cendol' => 'pengeluaran_makanan',
        'es dawet' => 'pengeluaran_makanan', 'es kelapa' => 'pengeluaran_makanan',
        'jus' => 'pengeluaran_makanan',

        // Transport spesifik
        'sewa jonson' => 'pengeluaran_transport', 'jonson' => 'pengeluaran_transport',
        'perahu' => 'pengeluaran_transport', 'boat' => 'pengeluaran_transport',
        'speed boat' => 'pengeluaran_transport', 'speedboat' => 'pengeluaran_transport',
        'pertamax' => 'pengeluaran_transport', 'pertalite' => 'pengeluaran_transport',
        'solar' => 'pengeluaran_transport', 'angkot' => 'pengeluaran_transport',
        'lrt' => 'pengeluaran_transport', 'kereta' => 'pengeluaran_transport',
        'toll' => 'pengeluaran_transport', 'etoll' => 'pengeluaran_transport',
        'pesawat' => 'pengeluaran_transport', 'bus' => 'pengeluaran_transport',

        // Belanja / E-commerce
        'borong' => 'pengeluaran_belanja', 'checkout' => 'pengeluaran_belanja',
        'order' => 'pengeluaran_belanja', 'pesen' => 'pengeluaran_belanja',
        'lazada' => 'pengeluaran_belanja', 'bukalapak' => 'pengeluaran_belanja',
        'blibli' => 'pengeluaran_belanja', 'olshop' => 'pengeluaran_belanja',
        'alfamidi' => 'pengeluaran_belanja', 'superindo' => 'pengeluaran_belanja',
        'hypermart' => 'pengeluaran_belanja',

        // Tagihan
        'pdam' => 'pengeluaran_tagihan', 'firstmedia' => 'pengeluaran_tagihan',
        'stnk' => 'pengeluaran_tagihan',

        // Hunian
        'kost' => 'pengeluaran_hunian', 'kontrak' => 'pengeluaran_hunian',
        'ngontrak' => 'pengeluaran_hunian',

        // Transfer
        'tf' => 'pengeluaran_transfer', 'trf' => 'pengeluaran_transfer',

        // Kesehatan
        'rs' => 'pengeluaran_kesehatan', 'puskesmas' => 'pengeluaran_kesehatan',
        'lab' => 'pengeluaran_kesehatan',

        // Perawatan
        'spa' => 'pengeluaran_perawatan_diri', 'pijat' => 'pengeluaran_perawatan_diri',

        // Donasi
        'bayar santunan' => 'pengeluaran_donasi',

        // Gaji
        'gaji pegawai' => 'pengeluaran_gaji', 'upah pegawai' => 'pengeluaran_gaji',
        'bayar karyawan' => 'pengeluaran_gaji',

        // Sewa properti
        'sewa toko' => 'pengeluaran_hunian', 'sewa gudang' => 'pengeluaran_hunian',
        'sewa ruko' => 'pengeluaran_hunian',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Income Overrides
    |--------------------------------------------------------------------------
    |
    | Override kategori untuk keyword income spesifik di AI fast path.
    | AI sering salah memilih kategori (misal "Transfer Masuk" untuk "dikasih").
    | Override ini memaksa kategori yang benar dan $isIncome = true.
    |
    */
    'ai_income_overrides' => [
        // Pemberian dari keluarga/kerabat
        'dikasih uang' => 'pendapatan_lainnya', 'dikasih' => 'pendapatan_lainnya',
        'dikasi uang' => 'pendapatan_lainnya', 'dikasi' => 'pendapatan_lainnya',
        'dari papi' => 'pendapatan_lainnya', 'dari papa' => 'pendapatan_lainnya',
        'dari mama' => 'pendapatan_lainnya', 'dari mami' => 'pendapatan_lainnya',
        'dari ortu' => 'pendapatan_lainnya', 'dari ayah' => 'pendapatan_lainnya',
        'dari ibu' => 'pendapatan_lainnya', 'dari bapak' => 'pendapatan_lainnya',
        'dari suami' => 'pendapatan_lainnya', 'dari istri' => 'pendapatan_lainnya',
        'dari pacar' => 'pendapatan_lainnya',
        'uang masuk' => 'pendapatan_lainnya', 'duit masuk' => 'pendapatan_lainnya',
        'transfer masuk' => 'pendapatan_lainnya',

        // Hadiah & THR
        'thr' => 'pendapatan_bonus', 'hadiah' => 'pendapatan_lainnya',
        'kado' => 'pendapatan_lainnya', 'angpao' => 'pendapatan_lainnya',
        'angpau' => 'pendapatan_lainnya',

        // Usaha
        'penjualan' => 'pendapatan_usaha', 'customer bayar' => 'pendapatan_lainnya',
        'pelanggan bayar' => 'pendapatan_lainnya', 'pembayaran customer' => 'pendapatan_lainnya',
        'order masuk' => 'pendapatan_usaha', 'orderan masuk' => 'pendapatan_usaha',
        'closing' => 'pendapatan_usaha',

        // Ojol (Ojek Online) income
        'narik grab' => 'pendapatan_usaha', 'narik gojek' => 'pendapatan_usaha',
        'narik maxim' => 'pendapatan_usaha', 'narik indrive' => 'pendapatan_usaha',
        'narik' => 'pendapatan_usaha', 'ojol masuk' => 'pendapatan_usaha',
        'grab masuk' => 'pendapatan_usaha', 'gojek masuk' => 'pendapatan_usaha',
        'trip grab' => 'pendapatan_usaha', 'trip gojek' => 'pendapatan_usaha',
    ],

    'local_income_extras' => [
        'dapat pinjaman' => 'pendapatan_hutang', 'terima pinjaman' => 'pendapatan_hutang',
        'pinjaman dari' => 'pendapatan_hutang', 'pinjam dari' => 'pendapatan_hutang',
        'hutang dari' => 'pendapatan_hutang', 'dipinjemin' => 'pendapatan_hutang',
        'pelunasan piutang' => 'pendapatan_terima_piutang',
        'terima piutang' => 'pendapatan_terima_piutang',
        'terima pelunasan' => 'pendapatan_terima_piutang',
        'piutang lunas' => 'pendapatan_terima_piutang',
        'piutang masuk' => 'pendapatan_terima_piutang',
        'bayaran' => 'pendapatan_gaji', 'penghasilan' => 'pendapatan_gaji',
        'income' => 'pendapatan_gaji',
        'fee' => 'pendapatan_bonus',
        'tf' => 'pendapatan_lainnya', 'trf' => 'pendapatan_lainnya',
        'dikasi' => 'pendapatan_lainnya', 'dikirim' => 'pendapatan_lainnya',
        'papi' => 'pendapatan_lainnya', 'papa' => 'pendapatan_lainnya',
        'mama' => 'pendapatan_lainnya', 'mami' => 'pendapatan_lainnya',
        'ayah' => 'pendapatan_lainnya', 'ibu' => 'pendapatan_lainnya',
        'bapak' => 'pendapatan_lainnya',
        'penjualan' => 'pendapatan_usaha', 'omset' => 'pendapatan_usaha',
        'jual' => 'pendapatan_usaha', 'laku' => 'pendapatan_usaha',
        'laris' => 'pendapatan_usaha', 'order masuk' => 'pendapatan_usaha',
        'orderan' => 'pendapatan_usaha', 'dp masuk' => 'pendapatan_usaha',
        'down payment' => 'pendapatan_usaha', 'closing' => 'pendapatan_usaha',
        'deal' => 'pendapatan_usaha', 'hasil jualan' => 'pendapatan_usaha',
        'untung' => 'pendapatan_usaha', 'profit' => 'pendapatan_usaha',
        'cuan' => 'pendapatan_usaha',
        'cash masuk' => 'pendapatan_lainnya', 'tunai masuk' => 'pendapatan_lainnya',
        'customer bayar' => 'pendapatan_lainnya', 'pelanggan bayar' => 'pendapatan_lainnya',
        'pembayaran customer' => 'pendapatan_lainnya',
        'masuk pembayaran' => 'pendapatan_lainnya', 'pembayaran masuk' => 'pendapatan_lainnya',
        'terima pembayaran' => 'pendapatan_lainnya', 'penerimaan pembayaran' => 'pendapatan_lainnya',
        'saldo awal' => 'pendapatan_lainnya',
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Transaction Action Keywords
    |--------------------------------------------------------------------------
    |
    | Keywords yang digunakan oleh BatchTransactionService untuk:
    | 1. Mendeteksi apakah baris adalah transaksi (isBatchTransactionFormat)
    | 2. Menentukan tipe transaksi per baris (expense vs income)
    |
    */
    'batch_expense_action_keywords' => [
        'beli', 'bayar', 'belanja', 'jajan', 'byr',
        'makan', 'mkn', 'maem', 'ngopi', 'minum',
        'gorengan', 'snack', 'cemilan', 'jajanan',
        'sarapan', 'siang', 'malam', 'brunch',
        'nonton', 'lihat', 'main',
        'ongkos', 'ongkir', 'kirim', 'transfer', 'tf',
        'sewa', 'kontrak', 'kos', 'kost',
        'servis', 'service', 'bensin', 'parkir', 'isi', 'ngisi',
        'topup', 'top', 'voucher',
        'sedekah', 'infaq', 'infak', 'zakat', 'sumbangan', 'donasi',
        'kasih', 'ngasih', 'kirimin', 'buat',
        'konsumsi', 'catering', 'akomodasi', 'transport',
        'perlengkapan', 'peralatan', 'bahan', 'material', 'alat',
        'dekorasi', 'dekor', 'cetak', 'print', 'fotokopi', 'fotocopy',
        'bingkisan', 'souvenir', 'doorprize',
        'bayarin', 'traktir', 'nombok', 'nombokin',
        'setor', 'setoran', 'iuran', 'kas', 'uang kas',
        'pengeluaran',
    ],

    'batch_income_action_keywords' => [
        'terima', 'dapat', 'dapet', 'gaji', 'bonus', 'honor', 'masuk',
        'dikasih', 'dikasi', 'hadiah', 'kado', 'angpao', 'angpau',
        'pemasukan', 'pendapatan',
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Fallback Keywords
    |--------------------------------------------------------------------------
    |
    | Keywords yang digunakan untuk "no amount detected" fallback path.
    | Dipakai saat transaksi terdeteksi tapi nominal tidak ditemukan.
    |
    */
    'error_income_keywords' => [
        'gaji', 'bonus', 'terima', 'pemasukan', 'dapat', 'dapet', 'dpt',
        'uang\s+masuk', 'dikasih', 'dikasi', 'hadiah', 'honor', 'upah',
        'transfer', 'kiriman', 'thr', 'tf', 'rejeki', 'rezeki',
    ],

    'error_expense_keywords' => [
        'beli', 'bayar', 'belanja', 'makan', 'mkn', 'maem', 'mamam',
        'bensin', 'bbm', 'transport', 'naik', 'jajan', 'ngopi', 'kopi',
        'isi', 'topup', 'top\s*up', 'ongkos', 'ongkir', 'parkir', 'tol',
        'toll', 'ojek', 'ojol', 'ngojek', 'grab', 'gojek', 'gocar',
        'goride', 'maxim', 'indrive', 'indriver', 'laundry', 'pulsa',
        'kuota', 'paket', 'listrik', 'pln', 'air', 'pdam', 'wifi',
        'internet', 'sewa', 'kos', 'kost', 'obat', 'dokter', 'salon',
        'potong\s*rambut', 'cukur',
    ],

];
