Rencana Implementasi - Pelindung Kata Kunci Ketat \& Validasi Kategori Gemini AI

Rencana ini memecahkan dua masalah sekaligus:



Pelindung Kata Kunci Ketat (Strict Keyword Guard): Memastikan pesan yang tidak mengandung kata kunci kategori/transaksi sama sekali di ProcessIncomingMessage.php tidak akan dianggap sebagai transaksi, mencegah kesalahan pencatatan (false positive) atau balasan error yang tidak perlu untuk pesan obrolan biasa.

Validasi Kategori Gemini AI: Memvalidasi dan menyempurnakan kategori transaksi yang terdeteksi menggunakan Google Gemini AI sebelum disimpan ke database jika pencocokan lokal kurang meyakinkan.

Tinjauan Pengguna Diperlukan

IMPORTANT



Aturan Pelindung Kata Kunci Ketat: Jika sebuah pesan diklasifikasikan sebagai transaksi (baik oleh sistem cepat, AI eksternal, atau fallback), tetapi tidak mengandung kata kunci apa pun dari daftar $transactionKeywords di ProcessIncomingMessage.php (seperti beli, bayar, makan, kopi, bensin, gaji, dll.), maka pesan tersebut tidak akan dianggap sebagai transaksi dan akan diabaikan (tidak memicu pembuatan transaksi maupun balasan error).



Konfigurasi API Gemini: Pemeriksaan kami mengonfirmasi bahwa sudah ada API Key aktif (••••6QrQ) dan model (gemini-2.5-flash) yang tersimpan di database Anda, sehingga fitur validasi Gemini akan langsung berfungsi setelah disetujui!



Usulan Perubahan

1\. Pelindung Kata Kunci Ketat (Strict Keyword Guard)

\[MODIFY] 

ProcessIncomingMessage.php

Kita akan menambahkan validasi kata kunci yang ketat pada alur pemrosesan pesan teks (processTextMessage) sebelum memanggil penanganan transaksi.



A. Pada Blok Intent AI (FinWa-AI):



php



&#x20;               // Handle catat pengeluaran/pemasukan

&#x20;               if ($finwaIntent === 'catat\_pengeluaran' || $finwaIntent === 'catat\_pemasukan') {

&#x20;                   // Strict guard: pesan harus mengandung kata kunci transaksi/kategori

&#x20;                   if (!$hasTransactionKeyword) {

&#x20;                       Log::info('AI mengklasifikasikan sebagai transaksi, tetapi diblokir oleh Strict Keyword Guard karena tidak ada kata kunci kategori', \[

&#x20;                           'message\_id' => $this->message->id,

&#x20;                           'intent' => $finwaIntent,

&#x20;                           'message' => $messageText

&#x20;                       ]);

&#x20;                       return; // Abaikan pesan, tidak dianggap transaksi

&#x20;                   }

&#x20;                   

&#x20;                   $this->transactionService->handleTransaction($messageText, $finwaEntities);

&#x20;                   return;

&#x20;               }

B. Pada Blok Fallback / AIProcessor:



php



&#x20;       if ($intent === 'irrelevant') {

&#x20;           // Don't reply to non-relevant messages

&#x20;           return; // Don't send any reply

&#x20;       } elseif ($intent === 'query') {

&#x20;           $this->financialQueryHandler->handleQuery($messageText);

&#x20;       } elseif ($intent === 'greeting') {

&#x20;           $this->greetingService->handleSpecialIntent('sapa');

&#x20;       } else {

&#x20;           // Strict guard untuk fallback transaksi

&#x20;           if (!$hasTransactionKeyword) {

&#x20;               Log::info('Fallback/AIProcessor mengklasifikasikan sebagai transaksi, tetapi diblokir oleh Strict Keyword Guard karena tidak ada kata kunci kategori', \[

&#x20;                   'message\_id' => $this->message->id,

&#x20;                   'intent' => $intent,

&#x20;                   'message' => $messageText

&#x20;               ]);

&#x20;               return; // Abaikan pesan, tidak dianggap transaksi

&#x20;           }

&#x20;           // Check for ambiguous "transfer" pattern before processing

&#x20;           ...

&#x20;           $this->transactionService->handleTransaction($messageText, $finwaEntities);

&#x20;       }

2\. Layanan Gemini AI

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

3\. Layanan Inferensi Kategori (Category Inference Service)

\[MODIFY] 

CategoryInferenceService.php

Kita akan menghubungkan validasi Gemini AI ke tahap akhir dari alur inferensi kategori (infer()).



Kondisi Validasi Gemini: Panggilan Gemini akan dilakukan jika:



GeminiAIService tersedia dan memiliki API key.

Hasil pencocokan lokal kurang meyakinkan (confidence < 0.95).

Atau hasil pencocokan lokal jatuh ke kategori umum fallback (pengeluaran\_lainnya, pendapatan\_lainnya).

Rencana Verifikasi

Verifikasi Otomatis melalui Skrip Uji (Scratch Script)

Kita akan menulis skrip verifikasi khusus scratch/verify\_category\_gemini.php yang akan menguji:



Strict Keyword Guard: Menguji pesan-pesan umum yang tidak mengandung kata kunci transaksi (seperti "nomor saya 5", "hari ini tanggal 25") dan memastikan mereka diabaikan (tidak dianggap transaksi).

Gemini Validation: Menguji pesan transaksi dengan kata kunci (seperti "sewa jonson keliling pulau 100k") dan memastikan Gemini berhasil mengoreksi kategori menjadi Transportasi (bukan Hunian).

Bypass Efisiensi: Menguji pesan dengan keyakinan tinggi (seperti "bayar hutang ke budi 50rb") dan memastikan ia melewati Gemini secara langsung untuk efisiensi latensi.

Kita akan menjalankan skrip ini melalui:



powershell



php "C:\\Users\\melis\\Herd\\finwa\\scratch\_verify\_category\_gemini.php"

dan menganalisis log keluaran untuk memastikan akurasinya.

