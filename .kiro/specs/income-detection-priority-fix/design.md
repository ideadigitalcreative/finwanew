# Income Detection Priority Fix — Bugfix Design

## Overview

Bug pada `TransactionExtractorService::extractTransactionLocally()` di mana pesan yang diawali income keyword (seperti "masuk", "pemasukan", "gaji") salah dikategorikan sebagai pengeluaran karena expense detection patterns (`expense_detection_patterns` dan regex `\bbayar\b`) diproses terlebih dahulu dan mem-block pengecekan income keywords.

Fix yang dilakukan: menambahkan position-based check menggunakan `str_starts_with()` SEBELUM expense override block. Jika pesan dimulai dengan income keyword, langsung set `$isIncome = true` dan skip seluruh expense override processing.

## Glossary

- **Bug_Condition (C)**: Pesan text yang diawali income keyword DAN mengandung expense override pattern di bagian lain pesan — menyebabkan misclassification sebagai expense
- **Property (P)**: Pesan yang diawali income keyword harus selalu dikategorikan sebagai income (`type = 'income'`, `category_type = 'pendapatan_lainnya'`), terlepas dari expense patterns di tempat lain
- **Preservation**: Semua input yang TIDAK diawali income keyword harus menghasilkan output identik dengan kode sebelum fix
- **extractTransactionLocally()**: Method di `TransactionExtractorService.php` yang menentukan income/expense berdasarkan keyword matching
- **income_detection_keywords**: Array keyword dari config `finwa_category_rules.income_detection_keywords` (contoh: "masuk", "pemasukan", "gaji", "bonus", dll)
- **expense_detection_patterns**: Array patterns dari config `finwa_category_rules.expense_detection_patterns` (contoh: "beli obat", "bayar kos", "gaji karyawan", dll)
- **Position-based check**: Pengecekan apakah pesan dimulai dengan keyword tertentu menggunakan `str_starts_with()` dengan word boundary handling

## Bug Details

### Bug Condition

Bug terjadi ketika pesan diawali income keyword tetapi juga mengandung expense override pattern di bagian lain teks. Urutan pengecekan saat ini (expense override → income keyword) menyebabkan income keyword di awal pesan diabaikan karena expense override sudah men-set `$isExpenseOverride = true` terlebih dahulu.

**Formal Specification:**
```
FUNCTION isBugCondition(input)
  INPUT: input of type string (message text)
  OUTPUT: boolean
  
  textLower ← lowercase(input)
  incomeKeywords ← config('finwa_category_rules.income_detection_keywords')
  expensePatterns ← config('finwa_category_rules.expense_detection_patterns')
  
  startsWithIncomeKeyword ← EXISTS keyword IN incomeKeywords
    WHERE str_starts_with(textLower, keyword)
    AND (length(textLower) == length(keyword)
         OR textLower[length(keyword)] IN [' ', '0'..'9'])
  
  hasExpenseOverride ← (
    EXISTS pattern IN expensePatterns WHERE textLower CONTAINS pattern
  ) OR (textLower MATCHES /\bbayar\b/)
  
  RETURN startsWithIncomeKeyword AND hasExpenseOverride
END FUNCTION
```

### Examples

- **"Pemasukan bayar wifi mardani 150"** → diawali "pemasukan" (income keyword), mengandung "bayar" (expense override). Saat ini: expense. Seharusnya: income.
- **"Masuk 150rb mardani bayar wifi"** → diawali "masuk" (income keyword), mengandung "bayar" (expense override). Saat ini: expense. Seharusnya: income.
- **"Gaji karyawan 5jt"** → diawali "gaji" (income keyword), mengandung "gaji karyawan" (expense pattern). Saat ini: expense. Seharusnya: income.
- **"Terima pembayaran customer 200rb"** → diawali "terima" (income keyword), mengandung "bayar" via "pembayaran". Saat ini: expense. Seharusnya: income.

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- Pesan yang TIDAK diawali income keyword tetapi mengandung expense patterns harus tetap dikategorikan sebagai expense
- Pesan yang TIDAK diawali income keyword tetapi mengandung kata "bayar" harus tetap dikategorikan sebagai expense
- Pesan yang tidak mengandung expense patterns maupun income keyword di awal tetap default expense
- Pesan yang mengandung income keyword di posisi non-awal dan tidak ada expense override tetap dikategorikan income (via existing `\b` word boundary matching)
- Hutang/piutang detection (early return) tidak terpengaruh — tetap diproses sebelum income/expense check
- Amount extraction, date extraction, account name extraction, dan semua field lain di output array tetap identik

**Scope:**
Semua input yang TIDAK dimulai dengan income keyword di posisi awal string tidak terpengaruh oleh perubahan ini. Fix hanya menambahkan satu priority check di awal flow income/expense detection.

## Hypothesized Root Cause

Berdasarkan analisis kode (`extractTransactionLocally()` line ~330-360):

1. **Urutan Pengecekan yang Salah**: Expense override patterns di-check terlebih dahulu (`foreach $expenseOverridePatterns`), diikuti pengecekan `\bbayar\b` regex. Baru setelah itu income keywords di-check — tetapi hanya jika `$isExpenseOverride` masih `false`. Ketika pesan mengandung expense pattern di manapun dalam teks, income check sepenuhnya di-skip.

2. **Tidak Ada Position Awareness**: Expense override check menggunakan `str_contains()` yang hanya melihat keberadaan pattern di manapun dalam string, tanpa mempertimbangkan posisi relatif terhadap income keywords. Pesan "Pemasukan bayar wifi" memiliki "bayar" yang match `str_contains()` meskipun intent jelas income berdasarkan kata pertama.

3. **Asumsi Desain yang Kurang Tepat**: Desain awal mengasumsikan expense patterns selalu lebih spesifik dan harus menang. Ini tidak berlaku ketika income keyword ada di posisi awal pesan — posisi awal menunjukkan intent utama pengguna.

## Correctness Properties

Property 1: Bug Condition - Income Keyword di Awal Pesan Harus Diprioritaskan

_For any_ input di mana pesan dimulai dengan income keyword (isBugCondition returns true), fungsi `extractTransactionLocally()` yang sudah di-fix SHALL mengembalikan `type = 'income'` dan `category_type = 'pendapatan_lainnya'`, terlepas dari adanya expense override patterns di bagian lain pesan.

**Validates: Requirements 2.1, 2.2, 2.3**

Property 2: Preservation - Non-Income-Start Inputs Tetap Identik

_For any_ input di mana pesan TIDAK dimulai dengan income keyword (isBugCondition returns false), fungsi `extractTransactionLocally()` yang sudah di-fix SHALL menghasilkan output yang identik persis dengan fungsi sebelum fix, mempertahankan semua behavior expense detection, default categorization, dan income detection via word boundary matching.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.6**

## Fix Implementation

### Changes Required

Asumsi root cause analysis benar:

**File**: `app/Services/Transaction/TransactionExtractorService.php`

**Function**: `extractTransactionLocally()`

**Specific Changes**:

1. **Tambah Position-Based Income Check (SEBELUM expense override block)**:
   - Setelah hutang/piutang early return dan sebelum expense override check
   - Loop melalui `config('finwa_category_rules.income_detection_keywords')`
   - Untuk setiap keyword, check: `str_starts_with($textLower, $keyword)`
   - Word boundary handling: keyword di awal harus diikuti spasi, angka, atau end-of-string
   - Jika match: set `$isIncome = true` dan skip expense override processing sepenuhnya

2. **Skip Expense Override Block**:
   - Jika position-based income check sudah match, wrap expense override block dalam `if (!$isIncome)` atau gunakan early assignment yang langsung loncat ke category determination

3. **Skip Existing Income Keyword Check**:
   - Jika `$isIncome` sudah `true` dari position check, existing income keyword loop (`foreach $incomeKeywords`) tidak perlu dijalankan lagi

4. **Word Boundary Handling**:
   - Gunakan check: setelah keyword match di awal, karakter berikutnya harus spasi, digit, atau string habis
   - Ini mencegah false positive seperti "masukan" (bukan "masuk") — keyword "masuk" tidak boleh match "masukan" karena diikuti huruf

5. **Tidak Ada Perubahan Lain**:
   - Amount extraction tetap sama
   - Date extraction tetap sama
   - Hutang/piutang early return tetap sama
   - Category type assignment tetap sama (`pendapatan_lainnya` untuk income)
   - Return array structure tetap sama

### Pseudocode Perubahan

```php
// === POSITION-BASED INCOME CHECK (NEW - BEFORE expense override) ===
$incomeKeywords = config('finwa_category_rules.income_detection_keywords', []);
$isIncome = false;

foreach ($incomeKeywords as $keyword) {
    if (str_starts_with($textLower, $keyword)) {
        $afterKeyword = strlen($keyword);
        // Word boundary: keyword diikuti spasi, angka, atau end-of-string
        if ($afterKeyword >= strlen($textLower) 
            || $textLower[$afterKeyword] === ' ' 
            || ctype_digit($textLower[$afterKeyword])) {
            $isIncome = true;
            break;
        }
    }
}

// === EXISTING EXPENSE OVERRIDE (only if not already income from position check) ===
if (! $isIncome) {
    $expenseOverridePatterns = config('finwa_category_rules.expense_detection_patterns', []);
    $isExpenseOverride = false;
    foreach ($expenseOverridePatterns as $pattern) {
        if (str_contains($textLower, $pattern)) {
            $isExpenseOverride = true;
            break;
        }
    }
    if (! $isExpenseOverride && preg_match('/\bbayar\b/u', $textLower)) {
        $isExpenseOverride = true;
    }

    // EXISTING income keyword check (word boundary, any position)
    if (! $isExpenseOverride) {
        foreach ($incomeKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/u', $textLower)) {
                $isIncome = true;
                break;
            }
        }
    }
}
```

## Testing Strategy

### Validation Approach

Strategi testing mengikuti dua fase: pertama, surface counterexamples yang mendemonstrasikan bug pada kode unfixed, kemudian verifikasi fix bekerja dengan benar dan mempertahankan behavior existing.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples yang mendemonstrasikan bug SEBELUM implementasi fix. Konfirmasi atau bantah root cause analysis.

**Test Plan**: Tulis unit tests yang memanggil `extractTransactionLocally()` dengan pesan yang diawali income keyword dan mengandung expense patterns. Jalankan pada kode UNFIXED untuk observasi kegagalan.

**Test Cases**:
1. **Pemasukan + bayar**: Input "Pemasukan bayar wifi mardani 150000" → expected income, actual expense (will fail on unfixed code)
2. **Masuk + bayar**: Input "Masuk 150000 mardani bayar wifi" → expected income, actual expense (will fail on unfixed code)
3. **Gaji + expense pattern**: Input "Gaji karyawan 5000000" → expected income, actual expense karena "gaji karyawan" ada di expense patterns (will fail on unfixed code)
4. **Terima + pembayaran**: Input "Terima pembayaran customer 200000" → expected income, actual expense karena "bayar" terkandung dalam "pembayaran" (will fail on unfixed code)

**Expected Counterexamples**:
- `result.type` = 'expense' padahal expected 'income'
- Root cause: expense override di-check sebelum income position check

### Fix Checking

**Goal**: Verifikasi bahwa untuk semua input di mana bug condition terpenuhi, fungsi yang di-fix menghasilkan behavior yang benar.

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition(input) DO
  result := extractTransactionLocally_fixed(input)
  ASSERT result.type = 'income'
  ASSERT result.category_type = 'pendapatan_lainnya'
END FOR
```

### Preservation Checking

**Goal**: Verifikasi bahwa untuk semua input di mana bug condition TIDAK terpenuhi, fungsi yang di-fix menghasilkan hasil identik dengan fungsi asli.

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT extractTransactionLocally_original(input) = extractTransactionLocally_fixed(input)
END FOR
```

**Testing Approach**: Property-based testing direkomendasikan untuk preservation checking karena:
- Menghasilkan banyak test case secara otomatis di seluruh input domain
- Menangkap edge cases yang manual unit tests mungkin terlewat
- Memberikan jaminan kuat bahwa behavior tidak berubah untuk semua non-buggy inputs

**Test Plan**: Observasi behavior pada kode UNFIXED terlebih dahulu untuk pesan tanpa income keyword di awal, kemudian tulis property-based tests yang capture behavior tersebut.

**Test Cases**:
1. **Expense Pattern Preservation**: Verifikasi "beli obat 50000" tetap expense setelah fix
2. **Bayar Standalone Preservation**: Verifikasi "bayar wifi 100000" tetap expense setelah fix
3. **Default Expense Preservation**: Verifikasi "makan siang 25000" tetap expense setelah fix
4. **Non-Start Income Preservation**: Verifikasi "dapat bonus 1000000" tetap income (keyword "dapat" ada di non-start tapi match word boundary)
5. **Word Boundary Edge Case**: Verifikasi "masukan data 50000" TIDAK match "masuk" karena diikuti huruf (bukan space/digit/end)

### Unit Tests

- Test position-based income detection untuk setiap keyword populer ("masuk", "pemasukan", "gaji", "terima", dll)
- Test word boundary handling ("masuk 100rb" ✓ vs "masukan 100rb" ✗)
- Test expense override masih bekerja untuk pesan tanpa income keyword di awal
- Test hutang/piutang early return tetap berfungsi
- Test default expense behavior tetap ada

### Property-Based Tests

- Generate random message texts yang diawali income keyword + mengandung expense pattern → assert selalu income
- Generate random message texts yang TIDAK diawali income keyword → assert output identik dengan kode unfixed
- Generate random keyword + suffix combinations untuk test word boundary logic

### Integration Tests

- Test full flow: pesan masuk via WhatsApp → extraction → categorization untuk "Pemasukan bayar wifi 150000"
- Test bahwa CategoryInferenceService downstream masih menerima `category_type = 'pendapatan_lainnya'` dengan benar
- Test batch transaction support tetap berfungsi dengan position-based check
