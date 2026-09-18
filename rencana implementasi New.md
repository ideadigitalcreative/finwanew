Rencana Implementasi - Validasi \& Koreksi Kategori dengan Gemini AI

Memvalidasi dan menyempurnakan kategori transaksi menggunakan Google Gemini AI sebelum disimpan ke database. Ini secara langsung mengatasi masalah di mana transaksi WhatsApp terkadang salah diklasifikasikan ke bawah kategori yang tidak tepat atau kategori umum (seperti "Lainnya" atau karena salah mencocokkan kata kunci lokal).



Tinjauan Pengguna Diperlukan

IMPORTANT



Konfigurasi API Gemini: Fitur ini bergantung pada API Key Gemini yang dikonfigurasi di database melalui Pengaturan Super Admin (app\_settings dengan kunci gemini) dan cadangan variabel .env (GEMINI\_API\_KEY, dll.). Pemeriksaan kami mengonfirmasi bahwa sudah ada API Key aktif (••••6QrQ) dan model (gemini-2.5-flash) yang tersimpan di database Anda, yang berarti fitur ini akan langsung berfungsi!



Optimasi Performa \& Biaya: Untuk mencegah latensi yang tidak perlu dan menghemat biaya API:



Transaksi dengan keyakinan aturan lokal yang sangat tinggi (misalnya confidence >= 0.95, seperti intent hutang/piutang yang sangat jelas) akan melewati (bypass) validasi Gemini.

Kita hanya akan mengirim daftar kategori yang sesuai dengan tipe intent transaksi (income atau expense) ke Gemini, menjaga prompt tetap kecil dan sangat relevan.

Usulan Perubahan

Kita akan memperkenalkan tahap validasi Gemini terstruktur di dalam CategoryInferenceService dan melengkapi GeminiAIService dengan metode validasi yang tangguh.



1\. Layanan Gemini AI

\[MODIFY] 

GeminiAIService.php

Kita akan menambahkan metode publik baru validateCategory untuk memvalidasi dan mengoreksi jenis kategori transaksi menggunakan Gemini.



Tanda Tangan Metode (Method Signature):



php



/\*\*

&#x20;\* Memvalidasi dan mencocokkan/mengoreksi jenis kategori menggunakan Gemini

&#x20;\*

&#x20;\* @param string $transactionText Teks pesan transaksi mentah dari WhatsApp

&#x20;\* @param string $intentType 'income' (pendapatan) atau 'expense' (pengeluaran)

&#x20;\* @param string|null $suggestedCategoryType Jenis kategori yang disarankan secara lokal (misal: 'pengeluaran\_belanja')

&#x20;\* @param array $availableCategories Array konfigurasi kategori yang tersedia untuk dipilih

&#x20;\* @return array{category\_type: string, confidence: float, corrected: bool, reason: string}|null

&#x20;\*/

public function validateCategory(string $transactionText, string $intentType, ?string $suggestedCategoryType, array $availableCategories): ?array

Fitur Utama dari Metode Validasi:



Memfilter kategori target terlebih dahulu berdasarkan jenis transaksi (income vs expense) untuk meminimalkan token konteks dan mengoptimalkan presisi prompt.

Menggunakan Gemini API dengan konfigurasi keluaran JSON terstruktur (structured JSON output).

Menangkap pengecualian (exceptions) dengan aman dan kembali ke null jika panggilan API gagal atau waktu habis (timeout).

2\. Layanan Inferensi Kategori (Category Inference Service)

\[MODIFY] 

CategoryInferenceService.php

Kita akan menghubungkan validasi Gemini AI ke tahap akhir dari alur inferensi kategori.



Pembaruan Alur (Pipeline Update):



php



&#x20;   public function infer(string $messageText): array

&#x20;   {

&#x20;       ...

&#x20;       // Step 7: Final Decision

&#x20;       $result = $this->stepFinalDecision();

&#x20;       // Step 8: Gemini AI Validation / Double Check (Validasi Gemini AI)

&#x20;       $result = $this->stepGeminiValidation($result);

&#x20;       Log::info('CategoryInference: Pipeline complete', \[

&#x20;           'message' => $messageText,

&#x20;           'result' => $result,

&#x20;       ]);

&#x20;       return $result;

&#x20;   }

Kondisi Validasi: Kita akan memanggil Gemini jika:



GeminiAIService tersedia dan memiliki API key yang terkonfigurasi.

Hasil pencocokan lokal kurang meyakinkan (confidence < 0.95).

Atau hasil pencocokan lokal jatuh ke kategori umum fallback (pengeluaran\_lainnya, pendapatan\_lainnya).

Logika Validasi Gemini (stepGeminiValidation):



Mengambil GeminiAIService melalui Service Container Laravel: app(\\App\\Services\\GeminiAIService::class).

Mengirimkan teks transaksi, tipe intent, jenis kategori yang cocok saat ini, dan daftar $this->categoryConfig.

Jika Gemini mengusulkan koreksi, sistem akan memperbarui array hasil dengan jenis kategori yang dikoreksi, memperbarui skor keyakinan, memperbarui nama kategori, mengatur sumber sebagai gemini\_ai\_validation, dan menyimpan alasan koreksi di dalam metadata.

Rencana Verifikasi

Verifikasi Otomatis melalui Skrip Uji (Scratch Script)

Kita akan menulis skrip verifikasi khusus scratch/verify\_category\_gemini.php di dalam repositori yang akan:



Melakukan booting pada aplikasi Laravel.

Menjalankan beberapa pesan transaksi yang realistis (baik yang jelas maupun yang ambigu/kompleks) melalui CategoryInferenceService::infer().

Memverifikasi bahwa:

Ketika Gemini tersedia, sistem berhasil mengoreksi kesalahan klasifikasi (misalnya "sewa jonson keliling pulau 100k" - di mana "jonson" adalah transportasi lokal perahu, tetapi kata "sewa" biasanya memicu kategori Hunian secara lokal).

Kategori lokal dengan keyakinan tinggi (misalnya "bayar hutang ke budi 50rb") berhasil melewati (bypass) validasi Gemini dengan benar untuk efisiensi.

Pengecualian dan log ditangani dengan baik jika API Key atau koneksi bermasalah.

Kita akan menjalankan skrip ini melalui:



powershell



php "C:\\Users\\melis\\Herd\\finwa\\scratch\_verify\_category\_gemini.php"

dan menganalisis log keluaran untuk memastikan akurasinya.

