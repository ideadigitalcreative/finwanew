# Rencana Implementasi — Koreksi & Revisi Transaksi yang Cerdas

Dokumen ini adalah rencana implementasi teknis untuk memperbaiki kegagalan pemrosesan pesan koreksi/revisi transaksi, berdasarkan analisa log chat WhatsApp user Sausan Dhiyya (17/9, 12.53–13.02).

---

## 1. Latar Belakang

Empat pesan user dalam satu percakapan gagal diproses. Keempatnya bukan satu bug, melainkan empat lubang pada arsitektur yang sama.

| # | Pesan user | Respons sistem | Gejala |
|---|---|---|---|
| 1 | `Uang lembur pondok cabe 1,5 juta` | Tercatat **Pengeluaran** — Bahan Makanan & Bumbu Dapur | Salah tipe & kategori |
| 2 | `Revisi: hapus uang lembur pondok cabe` | Template `✏️ Edit Transaksi` | Ide hapus tidak dieksekusi |
| 3 | `Ganti jadi 'pemasukan'` | Template `✏️ Edit Transaksi` | Perubahan tipe tidak mungkin dipenuhi |
| 4 | `Ubah kategori` | Template `✏️ Edit Transaksi` | Tidak bertanya balik, terjadi loop |

### Akar masalah (ringkas)

**A. Tidak ada satu sumber kebenaran untuk deteksi income/expense.**
Logika terduplikasi di dua service dengan urutan cek berbeda:

- [TransactionExtractorService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionExtractorService.php#L377-L419) — sudah memakai **prioritas berbasis posisi** (income keyword di awal pesan menang).
- [CategoryInferenceService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/CategoryInferenceService.php#L111-L154) — memeriksa **expense lebih dulu**, tanpa prioritas posisi, dan **default-nya expense**. Service inilah yang **menimpa** hasil extractor di [TransactionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L527-L567).

**B. Dua daftar config income tidak sinkron.**
`income_keywords` sudah memetakan `lembur` → `pendapatan_bonus` di [baris 1934-1935](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php#L1928-L1942), tetapi `income_detection_keywords` di [baris 2425-2451](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php#L2425-L2451) **tidak memuat `lembur`/`uang lembur`**. Akibatnya tipe jatuh ke default expense, dan `matchKeywords()` tidak pernah mempertimbangkan kategori income — sehingga [`'cabe' => 'pengeluaran_bahan_makanan'`](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php#L367) menang.

**C. Semua jalur edit/hapus mensyaratkan nominal (`$hasAmount`).**
- [isEditContext()](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/IntentDetectionService.php#L225-L246) langsung `return false` bila tidak ada nominal.
- [isUndoOrDelete()](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/IntentDetectionService.php#L251-L270) hanya mengenali `hapus` bila string persis `"hapus"`.
- Fast-path di [ProcessIncomingMessage baris 1250-1262](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1250-L1277) memakai `&& $hasAmount`.
- [MessageRouterService baris 229-242](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/MessageRouterService.php#L229-L242) mensyaratkan `$hasAmount` untuk `hapus_transaksi` dan `koreksi_transaksi`.

**D. Kata "revisi" tidak dikenal & prefix merusak anchor.**
Hasil pencarian `revisi` di seluruh `app/` → **nol kemunculan**. Selain itu semua regex hapus memakai anchor awal pesan, mis. [baris 1222](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1219-L1230) `^(hapus|delete|batal)\s+...` — prefix `Revisi:` mematahkannya.

**E. Perubahan tipe transaksi (income ↔ expense) tidak diimplementasikan.**
Tidak ada satu pun penulisan `$transaction->type` di [handleEditWithContext()](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L2179-L2348) maupun [handleEditTransaction()](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L1815-L2085). Keduanya hanya menulis `amount`, `category_id`, `transaction_date`.

**F. Tidak ada state sesi edit multi-turn.**
`ConversationContextService` punya `pending_transaction` dan `pending_confirmation` (expiry 5 menit) di [baris 474-696](file:///c:/Users/melis/Herd/finwa/app/Services/ConversationContextService.php#L474-L696), tetapi tidak ada `pending_edit`/`edit_session`.

---

## 2. Tujuan & Non-Tujuan

### Tujuan
1. `"Uang lembur pondok cabe 1,5 juta"` tercatat sebagai **income** dengan kategori pendapatan yang tepat.
2. `"Revisi: hapus uang lembur pondok cabe"` mengeksekusi penghapusan transaksi terkait.
3. `"Ganti jadi 'pemasukan'"` benar-benar mengubah tipe transaksi **beserta penyesuaian saldo**.
4. `"Ubah kategori"` memicu **pertanyaan balik**, bukan template statis.
5. Deteksi income/expense menjadi **satu sumber kebenaran** sehingga kelas bug ini tidak terulang.

### Non-Tujuan
- Tidak memigrasikan arsitektur ke LLM-first.
- Tidak mengubah skema tabel (tidak ada migrasi baru) — memakai kolom JSON `entities` yang sudah ada.
- Tidak menyentuh frontend Inertia/Vue.
- Tidak merombak seluruh `ProcessIncomingMessage` (hanya menyisipkan hook di titik yang teridentifikasi).

---

## 3. Ringkasan Perubahan File

| File | Jenis | Ringkasan |
|---|---|---|
| `app/Services/Transaction/TransactionTypeDetector.php` | **BARU** | Satu sumber kebenaran deteksi income/expense |
| `config/finwa_category_rules.php` | MODIFY | Sinkronisasi `income_detection_keywords`; tambah `command_prefix_labels`, `type_change_keywords`, `edit_ask_back_patterns` |
| `app/Services/Transaction/TransactionExtractorService.php` | MODIFY | Delegasikan deteksi tipe ke `TransactionTypeDetector` |
| `app/Services/Transaction/CategoryInferenceService.php` | MODIFY | Delegasikan `detectIntentType()` + guard konsistensi tipe/kategori |
| `app/Services/Transaction/TransactionService.php` | MODIFY | Normalisasi prefix; dukung perubahan `type`; perbaiki regex kategori; ask-back |
| `app/Services/WhatsApp/IntentDetectionService.php` | MODIFY | Lepaskan syarat `$hasAmount` untuk niat tegas; kenali `revisi` |
| `app/Services/ConversationContextService.php` | MODIFY | State `pending_edit` (store/get/clear) |
| `app/Jobs/ProcessIncomingMessage.php` | MODIFY | Hook prefix normalizer; fast-path tipe & ask-back; lepas syarat nominal |
| `app/Services/WhatsApp/MessageRouterService.php` | MODIFY/HAPUS | Selaraskan dengan perubahan, atau tandai sebagai dead code |
| `tests/Unit/Services/Transaction/TransactionCorrectionIntelligenceTest.php` | **BARU** | Test regresi dari log nyata |
| `tests/Unit/Services/Transaction/TransactionTypeDetectorTest.php` | **BARU** | Property test prioritas posisi & preservation |

---

## 4. Fase Implementasi

### FASE 1 — Fondasi: Satu Sumber Kebenaran Deteksi Tipe

> Menyelesaikan Kasus 1 sekaligus mematikan kelas bug yang sudah pernah muncul (lihat `.kiro/specs/income-detection-priority-fix/`).

#### Task 1.1 — Sinkronkan config income detection
**File:** [config/finwa_category_rules.php](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php#L2425-L2451)

Tambahkan ke array `income_detection_keywords`:

```php
'lembur', 'uang lembur', 'insentif', 'tunjangan', 'reward',
'cashback', 'refund', 'dividen', 'fee', 'komisi',
```

Alasan: seluruh kata ini sudah dipetakan ke kategori `pendapatan_*` di `income_keywords`, tetapi absen dari daftar deteksi — persis penyebab `lembur` gagal.

**Kriteria penerimaan:** setiap key di `income_keywords` yang mengarah ke `pendapatan_*` juga ada di `income_detection_keywords` (divalidasi test pada Task 1.4).

#### Task 1.2 — Buat `TransactionTypeDetector`
**File baru:** `app/Services/Transaction/TransactionTypeDetector.php`

Mengembalikan `income|expense` dengan urutan aturan eksplisit berikut:

```php
<?php

namespace App\Services\Transaction;

class TransactionTypeDetector
{
    /**
     * Deteksi tipe transaksi dari teks.
     * Urutan aturan (prioritas menurun):
     *   1. Income keyword di AWAL pesan (position-based) → income
     *   2. Expense override pattern / kata "bayar"        → expense
     *   3. Income keyword via word boundary               → income
     *   4. Default                                        → expense
     */
    public function detect(string $text): string
    {
        $textLower = mb_strtolower(trim($text));
        $incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);

        // Aturan 1 — prioritas posisi (selaras TransactionExtractorService)
        foreach ($incomeKeywords as $keyword) {
            if (! str_starts_with($textLower, $keyword)) {
                continue;
            }
            $after = strlen($keyword);
            if ($after >= strlen($textLower)
                || $textLower[$after] === ' '
                || ctype_digit($textLower[$after])) {
                return 'income';
            }
        }

        // Aturan 2 — expense override
        $isExpenseOverride = false;
        foreach (config('finwa_category_rules.expense_detection_patterns', []) as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $isExpenseOverride = true;
                break;
            }
        }
        if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $textLower)) {
            $isExpenseOverride = true;
        }
        if ($isExpenseOverride) {
            return 'expense';
        }

        // Aturan 3 — income via word boundary
        foreach ($incomeKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                return 'income';
            }
        }

        // Aturan 4 — default
        return 'expense';
    }
}
```

**Catatan penting:** salin **persis** semantik `str_starts_with` + boundary dari [TransactionExtractorService baris 382-393](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionExtractorService.php#L377-L419), termasuk syarat `ctype_digit` (agar `"masukan data 50rb"` tidak dianggap income lewat kata `"masuk"`).

#### Task 1.3 — Delegasikan kedua service ke detector
**File:** [TransactionExtractorService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionExtractorService.php#L377-L419)
Ganti blok baris 377-419 menjadi pemanggilan detector (perilaku identik, kode tidak lagi terduplikasi).

**File:** [CategoryInferenceService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/CategoryInferenceService.php#L111-L154)
Ubah `detectIntentType()`:

```php
protected function detectIntentType(?bool $isIncomeHint = null): string
{
    if ($isIncomeHint !== null) {
        return $isIncomeHint ? 'income' : 'expense';
    }

    return app(TransactionTypeDetector::class)->detect($this->messageLower);
}
```

Ini adalah **perbaikan inti Kasus 1**: `CategoryInferenceService` akan berhenti menyimpulkan expense untuk pesan yang diawali income keyword.

#### Task 1.4 — Guard konsistensi tipe vs kategori (pertahanan berlapis)
**File:** [CategoryInferenceService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/CategoryInferenceService.php#L156-L161), di `matchKeywords()`

Tambahkan validasi: bila `$intentType === 'income'` tetapi kategori hasil pencocokan bertipe `pengeluaran_*` (atau sebaliknya), **buang kandidat tersebut** dan turunkan confidence ke bawah ambang `0.4`. Ini mencegah `cabe` kembali memenangkan transaksi income lewat jalur lain (mis. context boost di [`applyContextBoosts()`](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/CategoryInferenceService.php#L238-L301)).

#### Task 1.5 — Test Phase 1
**File baru:** `tests/Unit/Services/Transaction/TransactionTypeDetectorTest.php`

Wajib berisi:
- **Bug condition (harus GAGAL sebelum fix, LULUS sesudah):**
  - `"Uang lembur pondok cabe 1,5 juta"` → `income`
  - `"Lembur 500rb"` → `income`
  - `"Insentif proyek 2jt"` → `income`
  - `"Pemasukan bayar wifi 150rb"` → `income` (regresi spec lama)
- **Preservation (harus LULUS sebelum & sesudah):**
  - `"beli obat 50rb"` → `expense`
  - `"bayar kos 500rb"` → `expense`
  - `"makan siang 25rb"` → `expense`
  - `"dapat bonus 1jt"` → `income`
  - `"masukan data 50rb"` → `expense` (boundary: bukan income)
  - `"hutang ke budi 100rb"` → tetap diproses sebagai hutang/piutang

**Verifikasi:**
```powershell
php artisan test --filter=TransactionTypeDetectorTest
php artisan test --filter=IncomeDetectionPriorityTest
php artisan test --filter=CategoryKeywordDeduplicationTest
```

---

### FASE 2 — Normalisasi Perintah & Pelepasan Syarat Nominal

> Menyelesaikan Kasus 2.

#### Task 2.1 — Normalisasi prefix label perintah
**File:** [ProcessIncomingMessage.php](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L608-L647)

Sisipkan **setelah** blok typo correction (baris 637) dan **sebelum** `addContext`:

```php
// COMMAND PREFIX NORMALIZATION
// "Revisi: hapus X" / "Koreksi: ganti jadi Y" → strip label agar regex ber-anchor tetap match
$prefixLabels = config('finwa_category_rules.command_prefix_labels', []);
foreach ($prefixLabels as $label) {
    $stripped = preg_replace(
        '/^\s*'.preg_quote($label, '/').'\s*[:\-]\s*/iu',
        '',
        $messageText
    );
    if ($stripped !== null && $stripped !== $messageText) {
        Log::info('Command prefix normalized', [
            'original' => $messageText,
            'normalized' => $stripped,
        ]);
        $messageText = $stripped;
        $textLower = strtolower($messageText);
        break;
    }
}
```

**Config baru** di [finwa_category_rules.php](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php):

```php
'command_prefix_labels' => ['revisi', 'koreksi', 'ralat', 'perbaikan', 'edit', 'revisi transaksi'],
```

Dengan ini `"Revisi: hapus uang lembur pondok cabe"` menjadi `"hapus uang lembur pondok cabe"`, yang langsung cocok dengan fast-path [baris 1222](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1219-L1230) → `handleDeleteTransactionByKeyword()`. Subjek transaksi dicari dari deskripsi, bukan dari nominal.

#### Task 2.2 — Kenali kata "revisi" sebagai sinyal edit
**File:** [IntentDetectionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/IntentDetectionService.php#L225-L246)

Tambahkan `'revisi'` dan `'perbaikan'` ke `$editContextKeywords` di `isEditContext()`.

#### Task 2.3 — Lepaskan syarat `$hasAmount` untuk niat hapus/edit yang tegas
**File:** [IntentDetectionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/IntentDetectionService.php#L225-L270)

Ubah `isUndoOrDelete()` agar mengenali niat hapus yang **merujuk objek**, meski tanpa nominal:

```php
// Niat hapus tegas dengan objek: "hapus <sesuatu>", "revisi: hapus <sesuatu>"
if (preg_match('/^(hapus|delete|hilangkan|batalin|batalkan|revisi)\s+\S+/u', $textLower)) {
    return true;
}
```

**File:** [ProcessIncomingMessage.php](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1250-L1262)
Ubah kondisi baris 1257 dari `&& $hasAmount` menjadi:

```php
if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $textLower)
    && ($hasAmount || $this->isObjectReferringCorrection($textLower))) {
```

dengan helper privat kecil yang mengenali pola `(salah|koreksi|harusnya|ubah jadi|ganti jadi|bukan|ralat)\s+\S+`.

**File:** [MessageRouterService.php](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/MessageRouterService.php#L229-L242)
Selaraskan: untuk `hapus_transaksi`, teruskan bila ada nominal **atau** ada objek setelah kata perintah. Lihat juga Task 5.3 (status file ini).

**Verifikasi:**
```powershell
php artisan test --filter=TransactionCorrectionIntelligenceTest
php artisan test --filter=IntentDetectionServiceTest
```

---

### FASE 3 — Dukungan Perubahan Tipe Transaksi

> Menyelesaikan Kasus 3. Ini fitur yang saat ini **tidak ada sama sekali**, bukan sekadar tidak terdeteksi.

#### Task 3.1 — Deteksi perintah perubahan tipe
**Config baru** di [finwa_category_rules.php](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php):

```php
'type_change_keywords' => [
    'pemasukan'  => 'income',
    'pendapatan' => 'income',
    'income'     => 'income',
    'uang masuk' => 'income',
    'pengeluaran' => 'expense',
    'expense'     => 'expense',
    'uang keluar' => 'expense',
],
```

**File:** [TransactionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L2179-L2348)
Di awal `handleEditWithContext()`, parse tipe target:

```php
// Deteksi permintaan ubah TIPE: "ganti jadi pemasukan", "ubah ke pengeluaran"
$newType = null;
if (preg_match(
    '/^(?:ganti|ubah|edit|koreksi|jadikan)\s+(?:jadi|ke|menjadi)?\s*[\'"]?([a-z\s]+?)[\'"]?\s*$/i',
    $textLower,
    $typeMatch
)) {
    $candidate = trim($typeMatch[1]);
    $newType = config('finwa_category_rules.type_change_keywords')[$candidate] ?? null;
}
```

#### Task 3.2 — Terapkan perubahan tipe + penyesuaian saldo
**File:** [TransactionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L2259-L2336)

Tambahkan blok sebelum penulisan `$replyMsg` akhir:

```php
if ($newType && $newType !== $transaction->type) {
    $balanceService = app(BalanceService::class);
    $oldType = $transaction->type;

    // 1. Batalkan efek tipe lama pada saldo
    $balanceService->reverseBalanceUpdate($transaction);

    // 2. Ubah tipe + remap kategori agar prefix konsisten
    $transaction->type = $newType;
    $transaction->category_id = $this->remapCategoryForType($transaction, $newType);
    $transaction->save();

    // 3. Terapkan efek tipe baru pada saldo
    $balanceService->updateBalanceFromTransaction($transaction);

    $typeLabel = fn (string $t) => $t === 'income' ? 'Pemasukan' : 'Pengeluaran';
    $replyMsg .= "🔄 Tipe: ~{$typeLabel($oldType)}~ ➝ *{$typeLabel($newType)}*\n";
}
```

`reverseBalanceUpdate()` di [BalanceService baris 286-323](file:///c:/Users/melis/Herd/finwa/app/Services/BalanceService.php#L286-L323) sudah menangani pembalikan per tipe — tidak perlu menulis logika saldo baru.

#### Task 3.3 — Helper remap kategori lintas tipe
**File:** [TransactionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php)

```php
/**
 * Kategori harus ikut berpindah prefix saat tipe berubah.
 * Bila padanan tidak tersedia di enum, jatuh ke kategori "lainnya" yang sesuai.
 */
protected function remapCategoryForType(Transaction $transaction, string $newType): int
{
    $category = $transaction->category;
    if (! $category) {
        return $this->defaultCategoryIdForType($newType);
    }

    $oppositePrefix = $newType === 'income' ? 'pengeluaran_' : 'pendapatan_';
    $targetPrefix   = $newType === 'income' ? 'pendapatan_'    : 'pengeluaran_';

    if (! str_starts_with($category->type, $oppositePrefix)) {
        return $category->id; // sudah konsisten, tidak perlu diubah
    }

    $candidateType = $targetPrefix . substr($category->type, strlen($oppositePrefix));

    $target = Category::where('tenant_id', $this->message->tenant_id)
        ->where('type', $candidateType)
        ->first();

    return $target?->id ?? $this->defaultCategoryIdForType($newType);
}
```

**RISIKO PENTING:** `categories.type` adalah kolom **enum** dengan daftar terbatas (lihat migrasi `add_*_category_type_to_categories_enum` di [database/migrations](file:///c:/Users/melis/Herd/finwa/database/migrations)). Padanan seperti `pendapatan_bahan_makanan` **kemungkinan tidak ada** di enum. Karena itu fallback ke `pendapatan_lainnya` / `pengeluaran_lainnya` bersifat **wajib**, bukan opsional. Jangan pernah menulis `category->type` sembarangan tanpa cek keberadaan.

#### Task 3.4 — Jalur masuk perintah ubah tipe tanpa nominal
Perintah `"Ganti jadi 'pemasukan'"` tidak punya nominal, jadi seluruh fast-path edit yang ada akan gugur. Tambahkan fast-path khusus.

**File:** [ProcessIncomingMessage.php](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1232-L1236), sebelum blok `1.6af2.5`:

```php
// 1.6af2.4: Ubah TIPE transaksi terakhir — "ganti jadi pemasukan", "ubah ke pengeluaran"
if (preg_match('/^(?:ganti|ubah|edit|koreksi)\s+(?:jadi|ke|menjadi)\s*[\'"]?(pemasukan|pendapatan|income|uang masuk|pengeluaran|expense|uang keluar)[\'"]?\s*$/i', $textLower)) {
    $this->transactionService->handleEditWithContext($messageText);
    return;
}
```

**Prasyarat:** transaksi terakhir harus bisa ditemukan dari konteks. `storeLastTransactionId()` dipanggil setelah transaksi dibuat di [TransactionService baris 1100-1106](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L1100-L1106) dan dibaca via `getLastTransactionId()` di [ConversationContextService baris 154-192](file:///c:/Users/melis/Herd/finwa/app/Services/ConversationContextService.php#L154-L192) (window 60 menit). Ini sudah tersedia.

**Verifikasi:**
```powershell
php artisan test --filter=TransactionCorrectionIntelligenceTest
```
Wajib menguji saldo: catat saldo sebelum, kirim `"ganti jadi pemasukan"`, pastikan saldo naik sebesar `2 × amount` dibanding kondisi expense (satu kali pembatalan efek expense, satu kali penerapan efek income).

---

### FASE 4 — State Sesi Edit & Ask-Back

> Menyelesaikan Kasus 4 dan menjadi kunci lompatan persepsi "bot paham".

#### Task 4.1 — Tambah `pending_edit` ke ConversationContextService
**File:** [ConversationContextService.php](file:///c:/Users/melis/Herd/finwa/app/Services/ConversationContextService.php#L474-L696)

Ikuti pola `pending_confirmation` yang sudah terbukti. Tambahkan tiga method:

```php
public function storePendingEdit(int $transactionId, string $awaitingField, array $meta = []): void
{
    $pending = [
        'transaction_id'  => $transactionId,
        'awaiting_field'  => $awaitingField, // 'type' | 'category' | 'amount' | 'date'
        'created_at'      => now()->toIso8601String(),
        'meta'            => $meta,
    ];
    // simpan ke entities['pending_edit'] pada context terbaru
}

public function getPendingEdit(): ?array   // expiry 5 menit, pola sama dengan getPendingConfirmation()
public function clearPendingEdit(): void   // pola sama dengan clearPendingConfirmation()
```

**Konstanta tambahan** di [atas file](file:///c:/Users/melis/Herd/finwa/app/Services/ConversationContextService.php#L16-L19):

```php
const PENDING_EDIT_EXPIRY_MINUTES = 5;
```

#### Task 4.2 — Deteksi perintah edit ambigu & tanya balik
**File:** [ProcessIncomingMessage.php](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L1250-L1277)

Tambahkan fast-path sebelum blok edit context:

```php
// 1.6af2.6: Perintah edit ambigu → tanya balik, jangan kirim template
$ambiguousEditPatterns = [
    '/^(ubah|ganti|edit|pindah(?:in)?)\s+(?:ke\s+)?kategori\s*$/i'   => 'category',
    '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:nominal|jumlah|harga)\s*$/i'  => 'amount',
    '/^(ubah|ganti|edit)\s+(?:ke\s+)?(?:tanggal|tgl)\s*$/i'           => 'date',
    '/^(ubah|ganti|edit)\s+(?:ke\s+)?tipe\s*$/i'                      => 'type',
];
foreach ($ambiguousEditPatterns as $pattern => $field) {
    if (preg_match($pattern, $textLower)) {
        $this->transactionService->askBackForEdit($field);
        return;
    }
}
```

#### Task 4.3 — Implementasi `askBackForEdit()` + konsumsi jawaban
**File:** [TransactionService.php](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php)

```php
public function askBackForEdit(string $field): void
{
    $transaction = $this->resolveLastTransaction();
    if (! $transaction) {
        $this->sendReply("⚠️ Tidak ada transaksi terakhir yang bisa diubah.");
        return;
    }

    $contextService = new ConversationContextService(
        $this->message->tenant_id,
        $this->getAttributionSenderId()
    );
    $contextService->storePendingEdit($transaction->id, $field);

    $amount = number_format($transaction->amount, 0, ',', '.');
    $category = $transaction->category->name ?? 'Lainnya';
    $labels = [
        'category' => "Mau diubah ke kategori apa?\n\nContoh: _Hiburan_, _Transport_, _Makanan_",
        'amount'   => "Mau diubah jadi berapa?\n\nContoh: _50rb_, _1,5jt_",
        'date'     => "Mau diubah ke tanggal berapa?\n\nContoh: _kemarin_, _11 des 2025_",
        'type'     => "Mau diubah jadi *Pemasukan* atau *Pengeluaran*?",
    ];

    $this->sendReply(
        "️ *Ubah Transaksi*\n\n".
        "Transaksi terakhir:\n".
        "• 📁 {$category}\n".
        "• 💰 Rp {$amount}\n\n".
        "━━━━━━━━━━━━━━━\n\n".
        ($labels[$field] ?? 'Silakan sebutkan perubahan yang diinginkan.')
    );
}
```

Lalu, di **awal** `handleEditWithContext()`, baca `pending_edit` lebih dulu:

```php
$pendingEdit = $contextService->getPendingEdit();
if ($pendingEdit && $this->isAnswerOnly($messageText)) {
    $transaction = Transaction::find($pendingEdit['transaction_id']);
    $contextService->clearPendingEdit();
    // petakan jawaban ke field, lalu langsung terapkan perubahan
    // "Hiburan" → category, "pemasukan" → type, "50rb" → amount
}
```

`isAnswerOnly()` = pesan pendek (< 40 karakter) tanpa kata perintah, sehingga tidak salah menangkap pesan transaksi baru.

**Perilaku akhir yang diharapkan:**

```
User: Ubah kategori
Bot : Transaksi terakhir:  Bahan Makanan • 💰 Rp 1.500.000
      Mau diubah ke kategori apa?
User: Hiburan
Bot : ✅ Kategori: ~Bahan Makanan~ ➝ *Hiburan*
```

#### Task 4.4 — Persempit regex kategori yang menangkap kata perintahnya sendiri
**File:** [TransactionService.php baris 1876-1890](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L1872-L1910) dan [baris 2239-2245](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L2237-L2245)

Pada `"Ubah kategori"`, regex menangkap kata `"kategori"` sebagai kandidat nama kategori. Tambahkan kata perintah ke exclusion yang sudah ada:

```php
// baris 1881 — lengkapi daftar exclusion
if (! preg_match('/^(\d+|rp|rupiah|tanggal|tgl|harga|nominal|kategori|tipe|type|transaksi|jumlah)\b/i', $candidate)
    && strlen($candidate) > 2) {
    // ... cari kategori
}
```

**Verifikasi:**
```powershell
php artisan test --filter=TransactionCorrectionIntelligenceTest
```

---

### FASE 5 — Pengerasan & Observability

#### Task 5.1 — Decision trace terpadu
**File:** [ProcessIncomingMessage.php](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php)

Tambahkan satu `Log::info` per pesan yang merangkum: rule pemenang, intent akhir, `hasAmount`, sumber keputusan (`fast_path`/`extractor`/`category_inference`/`finwa_ai`). Saat ini enam lapis keputusan tanpa jejak tunggal hampir mustahil di-debug. Sebagian log sudah ada (mis. `CategoryInference: Applied to transaction`) — cukup dibakukan dengan satu `trace_id` per pesan.

#### Task 5.2 — Test regresi dari log nyata
**File baru:** `tests/Unit/Services/Transaction/TransactionCorrectionIntelligenceTest.php`

Empat kasus ini wajib jadi test mati:

| Input | Assertion |
|---|---|
| `"Uang lembur pondok cabe 1,5 juta"` | `type = income`, `category_type = pendapatan_bonus` |
| `"Revisi: hapus uang lembur pondok cabe"` | transaksi terakhir terhapus |
| `"Ganti jadi 'pemasukan'"` | `type` berubah + saldo disesuaikan |
| `"Ubah kategori"` | bot bertanya balik, **bukan** template `Edit Transaksi` |

#### Task 5.3 — Putuskan status `MessageRouterService`
**File:** [MessageRouterService.php](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/MessageRouterService.php)

File ini berisi fast-path yang mirip `ProcessIncomingMessage` tetapi **tidak pernah dipanggil** — `$this->messageRouter` hanya di-assign di [ProcessIncomingMessage baris 175](file:///c:/Users/melis/Herd/finwa/app/Jobs/ProcessIncomingMessage.php#L175) dan tidak dipakai lagi. Ini berbahaya: orang bisa "memperbaiki bug" di file mati.

**Pilih satu:**
- **A (disarankan):** hapus file + dependensinya, sehingga hanya ada satu tempat routing.
- **B:** aktifkan sebagai router tunggal dengan memindahkan fast-path dari `ProcessIncomingMessage` ke sana (refactor besar, di luar cakupan rencana ini).

Jika memilih A, pastikan [MessageRouterTest.php](file:///c:/Users/melis/Herd/finwa/tests/Unit/Services/WhatsApp/MessageRouterTest.php) juga dihapus/disesuaikan.

#### Task 5.4 — Checkpoint akhir
```powershell
php artisan test
```
Pastikan tidak ada regresi. Jalankan juga test tetangga yang menyentuh area ini:
```powershell
php artisan test --filter=CategoryInferenceServiceTest
php artisan test --filter=ComprehensiveIntentTest
php artisan test --filter=TypoAmountExtractionTest
```

---

## 5. Peta Ketergantungan Task

```json
{
  "waves": [
    ["1.1", "1.2"],
    ["1.3", "1.4"],
    ["1.5"],
    ["2.1", "2.2", "2.3"],
    ["3.1", "3.2", "3.3"],
    ["3.4"],
    ["4.1", "4.4"],
    ["4.2", "4.3"],
    ["5.1", "5.2", "5.3"],
    ["5.4"]
  ]
}
```

- Task 1.3 bergantung pada 1.2 (detector harus ada lebih dulu).
- Task 3.4 bergantung pada 3.1–3.3 (logika perubahan tipe harus ada sebelum jalur masuknya dibuka).
- Task 4.3 bergantung pada 4.1 (state harus ada sebelum dikonsumsi).

---

## 6. Urutan Prioritas Eksekusi

| Prioritas | Task | Alasan |
|---|---|---|
| **P0** | 1.1, 1.2, 1.3 | Memperbaiki Kasus 1 + mematikan kelas bug lama |
| **P0** | 2.1, 2.3 | Memperbaiki Kasus 2 (hapus gagal) |
| **P0** | 3.1, 3.2, 3.3, 3.4 | Menutup permintaan yang saat ini mustahil dipenuhi |
| **P1** | 4.1, 4.2, 4.3, 4.4 | Membuat bot bertanya balik, bukan mengulang template |
| **P1** | 1.4 | Pertahanan berlapis agar Kasus 1 tidak kembali |
| **P2** | 5.1, 5.3 | Observability & pembersihan kode mati |
| **P2** | 2.2 | Pelengkap normalisasi |

Jika hanya bisa mengerjakan satu gelombang: **Fase 1** — perbaikan berdampak terbesar dengan risiko terkecil (tanpa perubahan skema, tanpa perilaku baru, murni menyatukan logika).

---

## 7. Risiko & Mitigasi

| Risiko | Dampak | Mitigasi |
|---|---|---|
| `categories.type` enum tidak punya padanan lintas tipe | Transaksi income berkategori expense, atau error saat update | Fallback wajib ke `*_lainnya` (Task 3.3); cek keberadaan sebelum menulis |
| Perubahan tipe ganda merusak saldo | Saldo tidak konsisten | Selalu `reverseBalanceUpdate()` sebelum ubah & `updateBalanceFromTransaction()` sesudah; test saldo eksplisit di Task 3.4 |
| State `pending_edit` salah menangkap pesan transaksi baru | Transaksi baru tertelan jadi jawaban edit | `isAnswerOnly()` (pendek, tanpa kata perintah) + expiry 5 menit + `clearPendingEdit()` segera setelah dikonsumsi |
| Pelepasan syarat `$hasAmount` membuat pesan biasa dianggap hapus | Pesan seperti "hapus rumput" dianggap hapus transaksi | Batasi ke pola ber-anchor `^(hapus|delete|...)\s+\S+`; pertahankan exclusion `target`/`tabungan`/`semua` yang sudah ada |
| Sinkronisasi config income menambah false positive | Pesan expense jadi income | Jalankan preservation test Task 1.5; aturan posisi hanya berlaku bila keyword ada di **awal** pesan |

### Rollback
Tidak ada migrasi database pada rencana ini, sehingga rollback cukup dengan `git revert` per fase. Fase bersifat independen: menonaktifkan Fase 3–4 tidak merusak Fase 1–2.

---

## 8. Definisi Selesai (Definition of Done)

1. Keempat baris log chat menghasilkan perilaku yang diharapkan (tabel Task 5.2).
2. `php artisan test` hijau, termasuk test preservation.
3. Deteksi income/expense hanya ada di satu tempat (`TransactionTypeDetector`).
4. Tidak ada penulisan `categories.type` tanpa pengecekan keberadaan kategori.
5. Setiap pesan menghasilkan satu decision trace yang bisa dilacak.

---

## 9. Referensi

- [TransactionExtractorService.php — prioritas posisi](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionExtractorService.php#L377-L419)
- [CategoryInferenceService.php — detectIntentType](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/CategoryInferenceService.php#L111-L154)
- [finwa_category_rules.php — income_detection_keywords](file:///c:/Users/melis/Herd/finwa/config/finwa_category_rules.php#L2425-L2451)
- [IntentDetectionService.php — isEditContext & isUndoOrDelete](file:///c:/Users/melis/Herd/finwa/app/Services/WhatsApp/IntentDetectionService.php#L225-L270)
- [TransactionService.php — handleEditWithContext](file:///c:/Users/melis/Herd/finwa/app/Services/Transaction/TransactionService.php#L2179-L2348)
- [ConversationContextService.php — pola pending state](file:///c:/Users/melis/Herd/finwa/app/Services/ConversationContextService.php#L474-L696)
- [BalanceService.php — reverseBalanceUpdate](file:///c:/Users/melis/Herd/finwa/app/Services/BalanceService.php#L286-L323)
- Spec terkait: `.kiro/specs/income-detection-priority-fix/bugfix.md`, `.kiro/specs/category-keyword-deduplication-fix/bugfix.md`