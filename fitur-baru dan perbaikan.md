Fitur Konfirmasi Transaksi \& Perbaikan Kategori

Latar Belakang

User sering mengirim pesan transaksi tanpa kata kunci yang terdaftar, misalnya:



"Lipstik 20k" → Tidak diproses / salah jadi Ringkasan Keuangan

"Baju 105rb" → Salah jadi Ringkasan Keuangan

"Air minum 21rb" → Masuk Utilitas, seharusnya Makanan \& Minuman

Akar masalah:



Strict Keyword Guard memblokir pesan yang mengandung nominal + deskripsi karena keyword tidak terdaftar

Ketika diblokir, pesan jatuh ke fallback dan menampilkan output yang salah (Ringkasan Keuangan)

Kategori "air" secara default memetakan ke pengeluaran\_utilitas, mengalahkan "air minum" yang seharusnya pengeluaran\_makanan

Solusi yang dipilih: Opsi B — Tanya konfirmasi dulu sebelum mencatat transaksi yang ambigu.



Proposed Changes

1\. ConversationContextService — Tambah Mekanisme Pending Confirmation

\[MODIFY] 

ConversationContextService.php

Tambah 3 method baru (mirip mekanisme storePendingTransaction() yang sudah ada):



php



/\*\*

&#x20;\* Store pending transaction confirmation

&#x20;\* When user sends "\[description] \[amount]" without recognized keyword,

&#x20;\* store the extracted data and wait for user to confirm with "YA"

&#x20;\*/

public function storePendingConfirmation(array $transactionData): void

php



/\*\*

&#x20;\* Get pending confirmation (if exists and not expired - 5 min)

&#x20;\*/

public function getPendingConfirmation(): ?array

php



/\*\*

&#x20;\* Clear pending confirmation after processed

&#x20;\*/

public function clearPendingConfirmation(): void

Data yang disimpan di pending\_confirmation:



php



\[

&#x20;   'original\_message' => 'Lipstik 20k',    // Pesan asli user

&#x20;   'description' => 'Lipstik',              // Deskripsi item

&#x20;   'amount' => 20000,                       // Nominal terdeteksi

&#x20;   'type' => 'expense',                     // Default expense

&#x20;   'created\_at' => '2026-05-29T...',

]

2\. ProcessIncomingMessage — Handler Konfirmasi \& Ubah Strict Guard

\[MODIFY] 

ProcessIncomingMessage.php

2a. Tambah handler "YA" di awal processTextMessage() (setelah pending transaction, sebelum typo correction)



Di bagian awal (setelah line \~384, setelah $isOnlyAmount pending transaction handler), tambahkan:



php



// PRIORITY CHECK: Pending confirmation (user replies "ya", "iya", "ok", "y", "yes")

$confirmPatterns = '/^(ya|iya|yaa|iyaa|ok|oke|y|yes|iy|yup|betul|benar|proceed|lanjut|catat|ya dong|iya dong|confirmed)$/i';

if (preg\_match($confirmPatterns, $msgTrimmed)) {

&#x20;   $pendingConfirmation = $contextService->getPendingConfirmation();

&#x20;   

&#x20;   if ($pendingConfirmation) {

&#x20;       Log::info('Processing pending confirmation', \[

&#x20;           'message\_id' => $this->message->id,

&#x20;           'pending' => $pendingConfirmation

&#x20;       ]);

&#x20;       

&#x20;       // Clear pending confirmation

&#x20;       $contextService->clearPendingConfirmation();

&#x20;       

&#x20;       // Process as transaction using the original message

&#x20;       $this->transactionService->handleTransaction(

&#x20;           $pendingConfirmation\['original\_message'], 

&#x20;           null

&#x20;       );

&#x20;       return;

&#x20;   }

}

2b. Ubah Strict Keyword Guard — kirim pesan konfirmasi alih-alih diam



Ada 2 tempat Strict Keyword Guard aktif:



Tempat 1 (line \~1473-1481): FinWa-AI mengklasifikasikan sebagai transaksi tapi tidak ada keyword



php



// SEBELUM:

if (!$hasTransactionKeyword) {

&#x20;   Log::info('...');

&#x20;   return; // Abaikan pesan, tidak dianggap transaksi

}

// SESUDAH:

if (!$hasTransactionKeyword) {

&#x20;   // Cek apakah ada nominal — jika ya, kirim konfirmasi

&#x20;   if ($hasAmount) {

&#x20;       $this->sendTransactionConfirmationPrompt($messageText, $contextService);

&#x20;       return;

&#x20;   }

&#x20;   Log::info('...');

&#x20;   return; // Abaikan pesan, tidak dianggap transaksi

}

Tempat 2 (line \~1592-1600): Fallback AI classifier



php



// SEBELUM:

if (!$hasTransactionKeyword) {

&#x20;   Log::info('...');

&#x20;   return;

}

// SESUDAH:

if (!$hasTransactionKeyword) {

&#x20;   if ($hasAmount) {

&#x20;       $this->sendTransactionConfirmationPrompt($messageText, $contextService);

&#x20;       return;

&#x20;   }

&#x20;   Log::info('...');

&#x20;   return;

}

2c. Tambah method baru sendTransactionConfirmationPrompt()



php



/\*\*

&#x20;\* Send confirmation prompt when message has amount but no recognized keyword

&#x20;\* e.g., "Lipstik 20k" → "Apakah ini pengeluaran Rp 20.000 untuk Lipstik? Balas YA"

&#x20;\*/

protected function sendTransactionConfirmationPrompt(

&#x20;   string $messageText, 

&#x20;   \\App\\Services\\ConversationContextService $contextService

): void {

&#x20;   // Extract amount and description

&#x20;   $amount = $this->transactionExtractor->extractAmountFromText($messageText);

&#x20;   $description = $this->transactionExtractor->extractDescriptionFromLine($messageText);

&#x20;   

&#x20;   if (!$amount || $amount <= 0) {

&#x20;       return; // Gagal extract, abaikan

&#x20;   }

&#x20;   

&#x20;   $formattedAmount = 'Rp ' . number\_format($amount, 0, ',', '.');

&#x20;   

&#x20;   // Store pending confirmation in context

&#x20;   $contextService->storePendingConfirmation(\[

&#x20;       'original\_message' => $messageText,

&#x20;       'description' => $description ?: $messageText,

&#x20;       'amount' => $amount,

&#x20;       'type' => 'expense',

&#x20;   ]);

&#x20;   

&#x20;   // Send confirmation prompt

&#x20;   $descDisplay = $description ?: $messageText;

&#x20;   $this->replyService->sendReply(

&#x20;       "🤔 \*Konfirmasi Transaksi\*\\n\\n" .

&#x20;       "Sepertinya Anda ingin mencatat:\\n" .

&#x20;       "💸 \*Pengeluaran\* {$formattedAmount}\\n" .

&#x20;       "📝 \_{$descDisplay}\_\\n\\n" .

&#x20;       "Balas \*YA\* untuk mencatat transaksi ini.\\n" .

&#x20;       "Atau kirim ulang dengan format yang lebih jelas, contoh:\\n" .

&#x20;       "• \_beli {$descDisplay} {$formattedAmount}\_"

&#x20;   );

&#x20;   

&#x20;   Log::info('Sent transaction confirmation prompt', \[

&#x20;       'message\_id' => $this->message->id,

&#x20;       'amount' => $amount,

&#x20;       'description' => $description,

&#x20;       'original' => $messageText,

&#x20;   ]);

}

IMPORTANT



Perlu diputuskan: Apakah saya perlu menambahkan handler untuk "TIDAK" / "BATAL" juga? Atau cukup diabaikan saja (pending confirmation otomatis expire dalam 5 menit)?



3\. Perbaikan Kategori "Air Minum"

\[MODIFY] 

finwa\_category\_rules.php

Sudah benar. File config sudah memetakan:



'air minum' => 'pengeluaran\_makanan' (line 444)

'air galon' => 'pengeluaran\_makanan' (line 445)

Masalah: Di CategoryInferenceService.php, keyword 'air' di kategori pengeluaran\_utilitas (line 194) match duluan karena str\_contains('air minum', 'air') mengembalikan true.



\[MODIFY] 

CategoryInferenceService.php

Sudah diperbaiki di sesi sebelumnya — step applySemanticContext() (line 742-748) sudah menangani ini:



php



// Context 3b: "air minum" / "air galon" / "galon" / "isi ulang" - drinking water is food/beverage, NOT utilitas

if ($intentType === 'expense' \&\& (str\_contains($text, 'air minum') || str\_contains($text, 'air galon') || str\_contains($text, 'galon') || str\_contains($text, 'isi ulang'))) {

&#x20;   $this->boostCategory('pengeluaran\_makanan', 45, 'semantic: drinking water is food/beverage');

&#x20;   if (isset($this->pipelineData\['scores']\['pengeluaran\_utilitas'])) {

&#x20;       $this->pipelineData\['scores']\['pengeluaran\_utilitas'] = 0;

&#x20;   }

}

Tetapi, fix ini hanya berlaku jika pesan melewati CategoryInferenceService. Masalah sebenarnya: pesan "Air minum 21 rb" yang dikirim tanpa kata kunci transaksi diblokir oleh Strict Keyword Guard dan tidak pernah sampai ke CategoryInferenceService.



Solusi: Dengan implementasi fitur konfirmasi di atas (poin 2), pesan "Air minum 21 rb" akan:



Terdeteksi memiliki nominal (21000) → ✅

Keyword "air" atau "minum" cocok dengan transactionKeywords → ✅ ("minum" sudah ada di line 515)

Akan langsung masuk Fast Path 2 (transaction) → ✅

CategoryInferenceService memprosesnya → semantic context rule mengarahkan ke Makanan \& Minuman → ✅

NOTE



Pesan "Air minum 21 rb" seharusnya sudah bekerja karena "minum" ada di $transactionKeywords (line 515). Jika masih gagal, kemungkinan masalahnya ada di word boundary matching. Perlu verifikasi.



Untuk double safety, tambahkan juga 'air minum' ke $transactionKeywords:



php



// Di $transactionKeywords (sekitar line 515)

'air minum', 'air galon', 'galon', // Drinking water

Alur Baru Setelah Implementasi

Skenario 1: "Lipstik 20k" (keyword tidak dikenal)



User: "Lipstik 20k"

&#x20; → Fast Path: Tidak match (lipstik bukan di transactionKeywords)

&#x20; → AI classifies: catat\_pengeluaran

&#x20; → Strict Keyword Guard: hasAmount=true, hasTransactionKeyword=false

&#x20; → NEW: sendTransactionConfirmationPrompt()

&#x20; → Bot: "🤔 Konfirmasi Transaksi\\n\\nSepertinya Anda ingin mencatat:\\n💸 Pengeluaran Rp 20.000\\n📝 Lipstik\\n\\nBalas YA untuk mencatat."

User: "YA"

&#x20; → PRIORITY CHECK: Match "ya" pattern

&#x20; → getPendingConfirmation() → found!

&#x20; → handleTransaction("Lipstik 20k", null)

&#x20; → Tercatat sebagai Pengeluaran Lainnya Rp 20.000

Skenario 2: "Baju 105rb" (keyword DIKENAL)



User: "Baju 105rb"

&#x20; → Fast Path: "baju" ada di transactionKeywords → MATCH

&#x20; → handleTransaction("Baju 105rb", null)

&#x20; → CategoryInferenceService → pengeluaran\_pakaian

&#x20; → Tercatat sebagai Pakaian Rp 105.000 ✅

Skenario 3: "Air minum 21rb" (keyword "minum" dikenal)



User: "Air minum 21rb"

&#x20; → Fast Path: "minum" ada di transactionKeywords → MATCH

&#x20; → handleTransaction("Air minum 21rb", null)

&#x20; → CategoryInferenceService → semantic rule: air minum → pengeluaran\_makanan

&#x20; → Tercatat sebagai Makanan \& Minuman Rp 21.000 ✅

Open Questions

IMPORTANT



Handler "TIDAK/BATAL": Apakah perlu menambahkan handler khusus ketika user membalas "tidak" atau "batal" setelah konfirmasi? Atau cukup biarkan expire otomatis setelah 5 menit?

Pemasukan vs Pengeluaran: Saat ini konfirmasi hanya menawarkan "Pengeluaran". Apakah perlu opsi untuk user memilih pemasukan juga? Misal: "Balas 1 untuk Pengeluaran, 2 untuk Pemasukan"

Verification Plan

Automated Tests

Kirim pesan "Lipstik 20k" → Harus mendapat pesan konfirmasi

Balas "YA" → Harus tercatat sebagai transaksi

Kirim "Baju 105rb" → Harus langsung tercatat tanpa konfirmasi

Kirim "Air minum 21rb" → Harus langsung tercatat sebagai Makanan \& Minuman

Manual Verification

Test via WhatsApp untuk memastikan alur konfirmasi berjalan mulus

Pastikan pesan "YA" yang bukan jawaban konfirmasi tidak salah terproses (yaitu ketika tidak ada pending confirmation)

