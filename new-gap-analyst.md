Gap Analysis — category\_mapping.php

1\. pengeluaran\_makanan — Missing keywords

Minuman kekinian yang belum ada:

php'kopi susu' => 'pengeluaran\_makanan',

'kopi hitam' => 'pengeluaran\_makanan',

'es boba' => 'pengeluaran\_makanan',

'brown sugar' => 'pengeluaran\_makanan',

'milk tea' => 'pengeluaran\_makanan',

'taro' => 'pengeluaran\_makanan',

'yakult' => 'pengeluaran\_makanan',

'ultramilk' => 'pengeluaran\_makanan',

'bear brand' => 'pengeluaran\_makanan',

'greenfields' => 'pengeluaran\_makanan',

'frisian flag' => 'pengeluaran\_makanan',

'energen' => 'pengeluaran\_makanan',

'nutrisari' => 'pengeluaran\_makanan',

'marimas' => 'pengeluaran\_makanan',

'pop ice' => 'pengeluaran\_makanan',

Brand restoran cepat saji yang belum masuk local\_expense\_extras:

php'a\&w' => 'pengeluaran\_makanan',

'texas chicken' => 'pengeluaran\_makanan',

'wendys' => 'pengeluaran\_makanan',

'burger king' => 'pengeluaran\_makanan',

'carl jr' => 'pengeluaran\_makanan',

'richeese' => 'pengeluaran\_makanan',

'nene ayam' => 'pengeluaran\_makanan',

'wingstop' => 'pengeluaran\_makanan',

'marugame' => 'pengeluaran\_makanan',

'pepper lunch' => 'pengeluaran\_makanan',

'shaburi' => 'pengeluaran\_makanan',

'hanamasa' => 'pengeluaran\_makanan',

Makanan bayi — ada di local\_expense\_extras tapi tidak di expense\_keywords utama:

php'mpasi' => 'pengeluaran\_makanan',  // ✅ sudah ada

// Tapi belum ada:

'tim bayi' => 'pengeluaran\_makanan',

'finger food bayi' => 'pengeluaran\_makanan',



2\. pengeluaran\_transport — Gap signifikan

Kendaraan listrik \& modern:

php'cas motor listrik' => 'pengeluaran\_transport',

'spbu' => 'pengeluaran\_transport',

'pom bensin' => 'pengeluaran\_transport',

'bahan bakar' => 'pengeluaran\_transport',  // ✅ ada, tapi belum: 

'pengisian daya' => 'pengeluaran\_transport',

'charging ev' => 'pengeluaran\_transport',

Tiket transportasi spesifik Sulawesi:

php'kapal pelni' => 'pengeluaran\_transport',

'kapal roro' => 'pengeluaran\_transport',

'kapal lambat' => 'pengeluaran\_transport',

'penyeberangan' => 'pengeluaran\_transport',

'terminal' => 'pengeluaran\_transport',

'bus damri' => 'pengeluaran\_transport',

'teman bus' => 'pengeluaran\_transport',  // BRT Makassar

'bus kota' => 'pengeluaran\_transport',

Slang/informal:

php'narik' => 'pengeluaran\_transport',  // ⚠️ konflik dengan income narik ojol — perlu context

'ke kantor' => 'pengeluaran\_transport',

'pp' => 'pengeluaran\_transport',  // pulang pergi — ambigu, skip jika berisiko



3\. pengeluaran\_kesehatan — Gap kategori farmasi

Karena Anda punya background farmasi, ini yang cukup penting untuk Finwa:

php// OTC drugs yang belum masuk:

'panadol flu' => 'pengeluaran\_kesehatan',

'feminax' => 'pengeluaran\_kesehatan',

'antimo' => 'pengeluaran\_kesehatan',

'vometa' => 'pengeluaran\_kesehatan',

'norit' => 'pengeluaran\_kesehatan',

'oralit' => 'pengeluaran\_kesehatan',

'entrostop' => 'pengeluaran\_kesehatan',

'bioplacenton' => 'pengeluaran\_kesehatan',

'tensoplas' => 'pengeluaran\_kesehatan',

'perban' => 'pengeluaran\_kesehatan',

'termometer' => 'pengeluaran\_kesehatan',

'tensimeter' => 'pengeluaran\_kesehatan',

'nebulizer' => 'pengeluaran\_kesehatan',

'oksimeter' => 'pengeluaran\_kesehatan',

'test pack' => 'pengeluaran\_kesehatan',

'alat cek gula' => 'pengeluaran\_kesehatan',

'strip gula' => 'pengeluaran\_kesehatan',



// Layanan kesehatan:

'telemedicine' => 'pengeluaran\_kesehatan',

'alodokter' => 'pengeluaran\_kesehatan',

'halodoc' => 'pengeluaran\_kesehatan',

'konsultasi dokter' => 'pengeluaran\_kesehatan',

'biaya rs' => 'pengeluaran\_kesehatan',

'biaya klinik' => 'pengeluaran\_kesehatan',

'biaya bersalin' => 'pengeluaran\_kesehatan',

'bersalin' => 'pengeluaran\_kesehatan',

'persalinan' => 'pengeluaran\_kesehatan',

'partus' => 'pengeluaran\_kesehatan',



4\. pengeluaran\_langganan — Gap SaaS \& tools 2024-2025

php// AI tools yang belum masuk (expense\_keywords utama):

'cursor' => 'pengeluaran\_langganan',        // ✅ ada di local\_expense\_extras, belum di utama

'windsurf' => 'pengeluaran\_langganan',       // sama

'perplexity' => 'pengeluaran\_langganan',

'gemini advanced' => 'pengeluaran\_langganan',

'copilot pro' => 'pengeluaran\_langganan',

'x premium' => 'pengeluaran\_langganan',



// Yang belum ada sama sekali:

'lovable' => 'pengeluaran\_langganan',

'bolt.new' => 'pengeluaran\_langganan',

'v0' => 'pengeluaran\_langganan',

'replit' => 'pengeluaran\_langganan',

'railway' => 'pengeluaran\_langganan',

'supabase' => 'pengeluaran\_langganan',

'planetscale' => 'pengeluaran\_langganan',

'render' => 'pengeluaran\_langganan',

'digital ocean' => 'pengeluaran\_langganan',

'digitalocean' => 'pengeluaran\_langganan',

'cloudflare' => 'pengeluaran\_langganan',

'mailchimp' => 'pengeluaran\_langganan',

'mailerlite' => 'pengeluaran\_langganan',

'klaviyo' => 'pengeluaran\_langganan',

'postmark' => 'pengeluaran\_langganan',

'resend' => 'pengeluaran\_langganan',

'linear' => 'pengeluaran\_langganan',

'lark' => 'pengeluaran\_langganan',

'whatsapp business api' => 'pengeluaran\_langganan',



5\. pengeluaran\_pendidikan — Gap platform digital

php'udemy' => 'pengeluaran\_pendidikan',

'coursera' => 'pengeluaran\_pendidikan',

'skillshare' => 'pengeluaran\_pendidikan',

'dicoding' => 'pengeluaran\_pendidikan',

'buildwithangga' => 'pengeluaran\_pendidikan',

'ruangguru' => 'pengeluaran\_pendidikan',

'zenius' => 'pengeluaran\_pendidikan',

'quipper' => 'pengeluaran\_pendidikan',

'cakap' => 'pengeluaran\_pendidikan',

'duolingo' => 'pengeluaran\_pendidikan',

'biaya ujian' => 'pengeluaran\_pendidikan',

'biaya sertifikasi' => 'pengeluaran\_pendidikan',

'sertifikasi' => 'pengeluaran\_pendidikan',

'biaya wisuda' => 'pengeluaran\_pendidikan',

'toga' => 'pengeluaran\_pendidikan',

'ukom' => 'pengeluaran\_pendidikan',      // relevant untuk BimbelNakes

'tryout ukom' => 'pengeluaran\_pendidikan',

'cpns' => 'pengeluaran\_pendidikan',

'tryout cpns' => 'pengeluaran\_pendidikan',



6\. pendapatan\_\* — Gap signifikan di income

Income digital/modern yang belum ada:

php// income\_keywords:

'payment gateway' => 'pendapatan\_usaha',

'midtrans' => 'pendapatan\_usaha',

'xendit' => 'pendapatan\_usaha',

'duitku' => 'pendapatan\_usaha',

'ipaymu' => 'pendapatan\_usaha',

'tripay' => 'pendapatan\_usaha',

'pembayaran masuk' => 'pendapatan\_usaha',

'affiliasi' => 'pendapatan\_lainnya',

'afiliasi' => 'pendapatan\_lainnya',

'referral' => 'pendapatan\_lainnya',

'komisi referral' => 'pendapatan\_lainnya',

'hasil sewa' => 'pendapatan\_sewa',      // sudah ada versi lain, tapi belum ini persis

'sewa properti' => 'pendapatan\_sewa',

'klaim' => 'pendapatan\_refund',

'pengembalian dana' => 'pendapatan\_refund',

'uang kembali shopee' => 'pendapatan\_refund',

'refund shopee' => 'pendapatan\_refund',

'refund tokopedia' => 'pendapatan\_refund',



7\. Potensi konflik / bug yang perlu diperhatikan

KeywordSaat iniPotensi masalah'susu'pengeluaran\_makanan (catch-all)Juga ada susu formula → pengeluaran\_kesehatan. Weighted match harusnya menang, tapi perlu verifikasi'sarden'pengeluaran\_makananBelum ada di local\_expense\_extras'kasih'Duplikat entry di expense\_keywords (2x)Harus dihapus salah satu'lainnya'Duplikat entry di expense\_keywords (2x)Sama'pajak kendaraan'Ada di pengeluaran\_pajak dan pengeluaran\_otomotifDuplikat lintas kategori — mana yang harus menang?'popok'Duplikat di expense\_keywords (pengeluaran\_belanja)Dua entry identik'susu formula'Ada di pengeluaran\_kesehatan dan pengeluaran\_belanjaDuplikat, perlu diputuskan salah satu'narik'income\_detection\_keywords (income)Tapi "narik tunai" harusnya expense



8\. Kategori yang belum ada sama sekali (saran baru)

pengeluaran\_rokok — banyak user Indonesia merokok, saat ini masuk lainnya yang kurang deskriptif:

php'rokok' => 'pengeluaran\_lainnya',   // bisa dipisah ke pengeluaran\_rokok jika mau tracking

'beli rokok' => 'pengeluaran\_lainnya',

'marlboro' => 'pengeluaran\_lainnya',

'surya' => 'pengeluaran\_lainnya',

'dji sam soe' => 'pengeluaran\_lainnya',

'gudang garam' => 'pengeluaran\_lainnya',

'vape' => 'pengeluaran\_lainnya',

'liquid vape' => 'pengeluaran\_lainnya',

'pod' => 'pengeluaran\_lainnya',

pengeluaran\_elektronik — saat ini semua masuk pengeluaran\_belanja, padahal nominal bisa besar:

php'hp' => 'pengeluaran\_elektronik',

'laptop' => 'pengeluaran\_elektronik',

'tablet' => 'pengeluaran\_elektronik',

'ipad' => 'pengeluaran\_elektronik',

'iphone' => 'pengeluaran\_elektronik',

'samsung' => 'pengeluaran\_elektronik',

'tv' => 'pengeluaran\_elektronik',

'kulkas' => 'pengeluaran\_elektronik',  // saat ini hunian

'ac' => 'pengeluaran\_elektronik',

'speaker' => 'pengeluaran\_elektronik',

'earphone' => 'pengeluaran\_elektronik',

'airpods' => 'pengeluaran\_elektronik',

'smartwatch' => 'pengeluaran\_elektronik',



Prioritas yang paling impactful untuk segera ditambah:



Bug fix duplikat (kasih, lainnya, popok, susu formula)

pengeluaran\_kesehatan — telemedicine \& alat kesehatan rumahan

pengeluaran\_langganan — SaaS 2024+

pendapatan\_refund — keyword e-commerce spesifik

pengeluaran\_transport — transportasi Makassar (Teman Bus, kapal Pelni)



