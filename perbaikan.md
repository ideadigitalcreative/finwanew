Rencana Implementasi Perbaikan Klasifikasi Pesan Pakaian (Fashion)

Pesan seperti "Baju 105 rb" saat ini tidak dicatat sebagai transaksi, melainkan memicu "Ringkasan Keuangan". Hal ini terjadi karena kata kunci terkait pakaian/fashion (seperti "baju", "pakaian", dll) tidak terdaftar dalam daftar kata kunci transaksi cepat ($transactionKeywords dan $txKeywords) pada berkas ProcessIncomingMessage.php dan AIProcessorService.php, sehingga diblokir oleh Strict Keyword Guard dan dialihkan ke pencarian informasi keuangan (query / cek\_cashflow) yang secara bawaan (default) menampilkan ringkasan bulanan.



Proposed Changes

1\. app/Jobs/ProcessIncomingMessage.php

\[MODIFY] 

ProcessIncomingMessage.php

Menambahkan kelompok kata kunci baru Pakaian \& Fashion ke dalam array $transactionKeywords di fungsi processTextMessage:

php



// Pakaian \& Fashion - formal \& informal

'pakaian', 'baju', 'celana', 'sepatu', 'tas', 'jaket', 'jilbab', 'kerudung', 'sandal', 'sendal', 'kaos', 'kemeja', 'gamis', 'hijab', 'fashion',

Menambahkan kata kunci terkait pakaian ke dalam array $txKeywords di fungsi processTextMessage untuk memastikan intent dipaksa menjadi catat\_pengeluaran ketika kata kunci ini terdeteksi:

php



$txKeywords = \['beli', 'bayar', 'naik', 'jajan', 'makan', 'minum', 'ngopi', 'isi', 'topup', 'transfer', 'ongkos', 'parkir', 'tol', 'ojek', 'grab', 'gojek', 'baju', 'pakaian', 'celana', 'sepatu', 'tas', 'jaket', 'jilbab', 'kerudung', 'sandal', 'sendal', 'kaos', 'kemeja', 'gamis', 'hijab', 'fashion'];

2\. app/Services/AIProcessorService.php

\[MODIFY] 

AIProcessorService.php

Menambahkan kelompok kata kunci baru Pakaian \& Fashion ke dalam array $transactionActionKeywords di fungsi classifyIntentFallback:

php



// Pakaian \& Fashion

'pakaian', 'baju', 'celana', 'sepatu', 'tas', 'jaket', 'jilbab', 'kerudung', 'sandal', 'sendal', 'kaos', 'kemeja', 'gamis', 'hijab', 'fashion',

Verification Plan

Automated Tests

Kita dapat membuat skrip verifikasi PHP mandiri di dalam workspace untuk menyimulasikan klasifikasi intent fallback dan ekstraksi lokal dengan teks "Baju 105 rb".



Jalankan skrip verifikasi tersebut menggunakan CLI PHP untuk memastikan "Baju 105 rb" diekstrak dengan sukses sebagai tipe transaksi expense dengan kategori pengeluaran\_pakaian.

Manual Verification

Lakukan pengujian langsung di sistem WhatsApp FinWa jika terhubung.

Kirim pesan "Baju 105 rb".

Verifikasi respons WhatsApp FinWa yang harus berupa konfirmasi penyimpanan transaksi, bukan menampilkan Ringkasan Keuangan bulanan.

