# Bugfix Requirements Document

## Introduction

Bug pada `TransactionExtractorService::extractTransactionLocally()` yang menyebabkan pesan berawalan income keyword ("Masuk", "Pemasukan", dll) salah dikategorikan sebagai pengeluaran. Hal ini terjadi karena expense detection patterns (khususnya kata "bayar") diproses lebih dulu dan mem-block pengecekan income keywords, meskipun intent pesan jelas merupakan pemasukan berdasarkan posisi keyword di awal pesan.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN pesan diawali income keyword "Pemasukan" tetapi mengandung kata "bayar" di bagian lain pesan (contoh: "Pemasukan bayar wifi mardani 150") THEN the system mengkategorikan transaksi sebagai pengeluaran (expense) karena kata "bayar" men-trigger expense override terlebih dahulu

1.2 WHEN pesan diawali income keyword "Masuk" tetapi mengandung kata "bayar" di bagian lain pesan (contoh: "Masuk 150rb mardani bayar wifi") THEN the system mengkategorikan transaksi sebagai pengeluaran (expense) karena kata "bayar" men-trigger expense override terlebih dahulu

1.3 WHEN pesan diawali income keyword tetapi mengandung salah satu expense_detection_patterns di bagian lain pesan THEN the system mengkategorikan transaksi sebagai pengeluaran (expense) karena expense pattern match di-check sebelum income keyword position

### Expected Behavior (Correct)

2.1 WHEN pesan diawali income keyword "Pemasukan" dan mengandung kata "bayar" di bagian lain pesan THEN the system SHALL mengkategorikan transaksi sebagai pemasukan (income) karena posisi income keyword di awal pesan menunjukkan intent yang jelas

2.2 WHEN pesan diawali income keyword "Masuk" dan mengandung kata "bayar" di bagian lain pesan THEN the system SHALL mengkategorikan transaksi sebagai pemasukan (income) karena posisi income keyword di awal pesan menunjukkan intent yang jelas

2.3 WHEN pesan diawali salah satu income_detection_keywords dan mengandung expense_detection_patterns di bagian lain pesan THEN the system SHALL mengkategorikan transaksi sebagai pemasukan (income) karena posisi income keyword di awal pesan memiliki prioritas lebih tinggi daripada expense patterns

### Unchanged Behavior (Regression Prevention)

3.1 WHEN pesan TIDAK diawali income keyword tetapi mengandung expense_detection_patterns THEN the system SHALL CONTINUE TO mengkategorikan transaksi sebagai pengeluaran (expense)

3.2 WHEN pesan TIDAK diawali income keyword tetapi mengandung kata "bayar" THEN the system SHALL CONTINUE TO mengkategorikan transaksi sebagai pengeluaran (expense)

3.3 WHEN pesan TIDAK mengandung expense patterns maupun income keyword di awal THEN the system SHALL CONTINUE TO mengkategorikan transaksi sebagai pengeluaran (expense) secara default

3.4 WHEN pesan mengandung income keyword tetapi TIDAK di posisi awal dan tidak ada expense patterns THEN the system SHALL CONTINUE TO mengkategorikan transaksi sebagai pemasukan (income) sesuai logic existing

3.5 WHEN pesan mengandung income keyword di posisi manapun dan tidak ada expense override THEN the system SHALL CONTINUE TO mengkategorikan transaksi sebagai pemasukan (income)

3.6 WHEN pesan terdeteksi sebagai hutang/piutang THEN the system SHALL CONTINUE TO memproses transaksi sebagai hutang/piutang tanpa terpengaruh perubahan ini (early return sebelum income/expense check)

---

## Bug Condition (Formal)

```pascal
FUNCTION isBugCondition(X)
  INPUT: X of type MessageText
  OUTPUT: boolean
  
  // Returns true when message starts with an income keyword
  // AND contains expense override pattern or "bayar" elsewhere in the text
  textLower ← lowercase(X.text)
  incomeKeywords ← config('finwa_category_rules.income_detection_keywords')
  
  startsWithIncomeKeyword ← EXISTS keyword IN incomeKeywords 
    WHERE textLower STARTS WITH keyword
    
  hasExpenseOverride ← (
    EXISTS pattern IN config('finwa_category_rules.expense_detection_patterns')
      WHERE textLower CONTAINS pattern
  ) OR (textLower MATCHES /\bbayar\b/)
  
  RETURN startsWithIncomeKeyword AND hasExpenseOverride
END FUNCTION
```

### Fix Checking Property

```pascal
// Property: Fix Checking — Income keyword di awal pesan harus diprioritaskan
FOR ALL X WHERE isBugCondition(X) DO
  result ← extractTransactionLocally'(X)
  ASSERT result.type = 'income'
  ASSERT result.category_type = 'pendapatan_lainnya'
END FOR
```

### Preservation Checking Property

```pascal
// Property: Preservation Checking — Non-buggy inputs behave identically
FOR ALL X WHERE NOT isBugCondition(X) DO
  ASSERT extractTransactionLocally(X) = extractTransactionLocally'(X)
END FOR
```
