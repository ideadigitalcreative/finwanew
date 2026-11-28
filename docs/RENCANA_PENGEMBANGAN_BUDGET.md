# Rencana Pengembangan Fitur Budgeting & Analisa

## Status Saat Ini

Sistem budgeting FinWA sudah berjalan dengan fitur:
- CRUD anggaran via web (Vue 3 + Inertia.js)
- Multi-periode (daily/weekly/monthly/yearly)
- Alert threshold per anggaran
- WhatsApp management (set/check/add/delete budget)
- Alert proaktif harian via WhatsApp (jam 19:00)
- Dashboard visual (donut chart, progress bar)
- Insight AI via Groq LLM
- Prediksi pengeluaran akhir bulan
- Deteksi anomali (3x rata-rata)
- Perbandingan bulan-ke-bulan
- Laporan PDF

---

## Daftar Temuan Teknis (Issues)

### 1. Duplikasi Kode — BudgetAlertService vs BudgetCommandService

**File terdampak:**
- `app/Services/Budget/BudgetAlertService.php` (758 baris)
- `app/Services/Budget/BudgetCommandService.php` (628 baris)

**Metode yang terduplikasi hampir identik:**
- `handleCheckBudget()`
- `handleSetBudget()`
- `handleAddBudget()`

**Risiko:** Bug inkonsisten, kesulitan pemeliharaan, perubahan di satu service tidak otomatis ter-sync ke service lain.

### 2. N+1 Query pada Model Budget

**File:** `app/Models/Budget.php` → method `getCurrentSpending()`

Setiap pemanggilan method ini menjalankan 1 query ke tabel `transactions`. Saat dipanggil dalam loop (BudgetController::index line 42-46, BudgetController::summary line 159), menghasilkan N query terpisah.

**Contoh dampak:** Jika user punya 10 anggaran aktif, halaman budget akan menjalankan minimal 20 query (spending + remaining per budget).

### 3. API Dashboard Tidak Konsisten

**File:** `app/Http/Controllers/Api/DashboardController.php`

API tidak menyertakan data budget, berbeda dengan web dashboard di `DashboardController.php` yang menyertakan `budgetItems` dan `budgetSummary`.

### 4. Pemetaan Kategori Hardcoded

**File:** `app/Services/Budget/BudgetAlertService.php` dan `BudgetCommandService.php`

Pemetaan nama kategori Indonesia ke ID dilakukan secara hardcoded. Penambahan kategori baru membutuhkan perubahan kode.

### 5. Single Alert Threshold

**File:** `app/Models/Budget.php` → `shouldTriggerAlert()`

Hanya ada satu threshold per anggaran (default 80%). Tidak ada tiered alert (info/warning/critical).

### 6. Tidak Ada Budget Global

Anggaran hanya bersifat per-kategori. Tidak ada opsi untuk mengatur batas total pengeluaran tanpa terikat kategori tertentu.

### 7. Tidak Ada Rollover

Sisa anggaran tidak terpakai tidak dialihkan ke periode berikutnya.

### 8. Tidak Ada Export Spreadsheet

Hanya ada export PDF. Belum ada export CSV/Excel.

### 9. Tidak Ada Budget History/Audit Trail

Perubahan anggaran tidak dicatat (siapa mengubah, dari nominal berapa ke berapa).

---

## Rencana Implementasi

### Fase 1 — Perbaikan Fondasi (Prioritas Tinggi)

> Tujuan: Perbaiki masalah teknis yang mengganggu performa dan maintainability.

#### 1.1 Refaktorisasi Duplikasi Kode

**Apa yang dilakukan:**
- Buat class `BudgetCommandHandler` baru yang menjadi satu-satunya entry point untuk semua perintah budget via WhatsApp.
- Pindahkan logika yang sudah ada di `BudgetCommandService` (yang lebih lengkap karena punya `handleDeleteBudget`) ke `BudgetCommandHandler`.
- Hapus `BudgetCommandService` dan buat `BudgetAlertService` hanya menangani alert/check yang sudah aktif.
- Pastikan semua method hanya ada di satu tempat.

**File yang diubah:**
- `app/Services/Budget/BudgetCommandHandler.php` (baru)
- `app/Services/Budget/BudgetAlertService.php` (hapus metode duplikat, sisakan hanya `checkBudgetAlert` dan `generateProactiveBudgetAlert`)
- `app/Services/Budget/BudgetCommandService.php` (hapus)
- Semua file yang memanggil `BudgetCommandService` → arahkan ke `BudgetCommandHandler`

**Cara verifikasi:**
- Jalankan semua test yang ada terkait budget.
- Test manual via WhatsApp: `cek budget`, `set budget makan 500rb`, `hapus budget makan`.

#### 1.2 Optimasi N+1 Query

**Apa yang dilakukan:**
- Tambahkan method statis `Budget::bulkGetCurrentSpendings(int $tenantId, string $period): array` yang menjalankan satu query untuk menghitung spending semua anggaran aktif sekaligus.
- Tambahkan method `loadCurrentSpending()` pada model `Budget` yang menerima array hasil bulk query.
- Refactor `BudgetController::index()` dan `BudgetController::summary()` untuk menggunakan bulk query.

**Query baru (satu kali untuk semua budget):**
```php
Transaction::where('tenant_id', $tenantId)
    ->where('type', 'expense')
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->selectRaw('category_id, SUM(amount) as total_spending')
    ->groupBy('category_id')
    ->pluck('total_spending', 'category_id');
```

**File yang diubah:**
- `app/Models/Budget.php`
- `app/Http/Controllers/BudgetController.php`
- `app/Http/Controllers/DashboardController.php`

**Cara verifikasi:**
- Jalankan `php artisan tinker` dan hitung jumlah query sebelum & sesudah.
- Bandingkan halaman budget sebelum (N+1) dan sesudah (bulk query).

#### 1.3 Konsistensi API Dashboard

**Apa yang dilakukan:**
- Sertakan data `budgetItems` dan `budgetSummary` di `Api\DashboardController` menggunakan metode bulk query dari 1.2.

**File yang diubah:**
- `app/Http/Controllers/Api/DashboardController.php`

**Cara verifikasi:**
- Panggil API endpoint dashboard dan pastikan field `budgetItems` dan `budgetSummary` muncul.

---

### Fase 2 — Fitur Baru Budget (Prioritas Menengah)

> Tujuan: Tambahkan fitur budgeting yang paling banyak diminta.

#### 2.1 Tiered Alert System

**Apa yang dilakukan:**
- Ubah field `alert_threshold` (integer tunggal) menjadi `alert_thresholds` (JSON) yang menyimpan 3 level:
  ```json
  {
    "info": 50,
    "warning": 70,
    "critical": 90
  }
  ```
- Buat migration untuk menambah kolom `alert_thresholds` (JSON) dan migrate data dari `alert_threshold` lama.
- Update method `shouldTriggerAlert()` → `getAlertLevel()` yang mengembalikan `null|'info'|'warning'|'critical'`.
- Update semua UI (Vue, WhatsApp messages) untuk menampilkan level alert yang sesuai.

**File yang diubah:**
- `database/migrations/xxxx_add_alert_thresholds_to_budgets.php` (baru)
- `app/Models/Budget.php`
- `app/Services/Budget/BudgetCommandHandler.php`
- `app/Services/Budget/BudgetAlertService.php`
- `resources/js/pages/Budgets/Index.vue`
- `resources/js/components/dashboard/BudgetOverviewCard.vue`

**Cara verifikasi:**
- Buat anggaran dengan threshold info=50, warning=70, critical=90.
- Verifikasi alert trigger di level yang benar.

#### 2.2 Budget Global (Total Cap)

**Apa yang dilakukan:**
- Buat migration baru untuk tabel `budget_globals`:
  ```
  tenant_id, period (monthly/yearly), amount, start_date, end_date,
  is_active, alert_thresholds (JSON), created_at, updated_at
  ```
- Buat model `BudgetGlobal` dengan method bisnis (sama seperti `Budget` tapi tanpa `category_id`).
- Tambah route `GET /budgets/global`, `POST /budgets/global`, `PUT /budgets/global/{budget}`.
- Tambah komponen Vue `BudgetGlobalCard.vue` di halaman budget.
- Sertakan budget global di Dashboard dan alert WhatsApp.

**File yang diubah:**
- `database/migrations/xxxx_create_budget_globals_table.php` (baru)
- `app/Models/BudgetGlobal.php` (baru)
- `app/Http/Controllers/BudgetController.php`
- `resources/js/pages/Budgets/Index.vue`
- `app/Services/Budget/BudgetAlertService.php`

#### 2.3 Budget Rollover

**Apa yang dilakukan:**
- Tambah field `enable_rollover` (boolean, default false) pada tabel `budgets`.
- Buat method `getRolledOverAmount()` yang menghitung sisa anggaran dari periode sebelumnya.
- Buat command `budget:rollover` yang dijadwalkan otomatis setiap pergantian periode:
  - Ambil semua budget dengan `enable_rollover = true`
  - Hitung sisa dari periode sebelumnya
  - Tambahkan ke anggaran periode baru (update `amount` atau simpan di `metadata.rollover_amount`)
- Update UI untuk menampilkan jumlah rollover.

**File yang diubah:**
- `database/migrations/xxxx_add_rollover_to_budgets.php` (baru)
- `app/Models/Budget.php`
- `app/Console/Commands/BudgetRollover.php` (baru)
- `routes/console.php` (jadwalkan command)
- `resources/js/pages/Budgets/Index.vue`

---

### Fase 3 — Analisa & Reporting Lanjutan (Prioritas Menengah)

> Tujuan: Tingkatkan kedalaman analisa dan variasi output.

#### 3.1 Budget vs Actual Chart

**Apa yang dilakukan:**
- Tambah endpoint `GET /budgets/analysis/chart` yang mengembalikan data JSON untuk chart perbandingan budget vs aktual per kategori.
- Buat komponen Vue `BudgetVsActualChart.vue` menggunakan bar chart (horizontal grouped bar).
- Tampilkan di halaman budget di bawah ringkasan.

**File yang diubah:**
- `app/Http/Controllers/BudgetController.php`
- `resources/js/pages/Budgets/Index.vue`
- `resources/js/components/budgets/BudgetVsActualChart.vue` (baru)

#### 3.2 Export CSV/Excel

**Apa yang dilakukan:**
- Tambah route `GET /budgets/export` yang menghasilkan file CSV berisi:
  - Kategori, Anggaran, Terpakai, Sisa, Persentase, Status
- Gunakan `league/csv` atau response stream untuk export tanpa library tambahan.
- Tambah tombol "Export CSV" di halaman budget.

**File yang diubah:**
- `app/Http/Controllers/BudgetController.php` (tambah method `export()`)
- `routes/web.php` (tambah route)
- `resources/js/pages/Budgets/Index.vue` (tambah tombol export)

#### 3.3 Budget History/Audit Trail

**Apa yang dilakukan:**
- Buat tabel `budget_histories`:
  ```
  budget_id, tenant_id, action (created/updated/deleted/toggled),
  old_values (JSON), new_values (JSON), performed_by, created_at
  ```
- Buat model `BudgetHistory`.
- Tambah event listener atau observer pada model `Budget` yang mencatat perubahan.
- Tampilkan history di halaman budget (accordion atau tab terpisah).

**File yang diubah:**
- `database/migrations/xxxx_create_budget_histories_table.php` (baru)
- `app/Models/BudgetHistory.php` (baru)
- `app/Observers/BudgetObserver.php` (baru)
- `app/Providers/AppServiceProvider.php` (register observer)
- `resources/js/pages/Budgets/Index.vue` (tambah tab history)

---

### Fase 4 — Smart Features (Prioritas Rendah)

> Tujuan: Fitur cerdas berbasis data historis.

#### 4.1 Smart Budget Suggestion

**Apa yang dilakukan:**
- Buat method `BudgetSuggestionService::suggest(int $tenantId, int $categoryId): array` yang:
  - Mengambil data spending 3-6 bulan terakhir untuk kategori tersebut
  - Menghitung rata-rata, median, dan tren
  - Mengembalikan saran: minimal (median), nyaman (rata-rata), agresif (70% rata-rata)
- Tampilkan saran di modal create budget sebagai rekomendasi.

**File yang diubah:**
- `app/Services/Budget/BudgetSuggestionService.php` (baru)
- `app/Http/Controllers/BudgetController.php` (tambah endpoint suggestion)
- `resources/js/pages/Budgets/Index.vue` (tampilkan rekomendasi di form)

#### 4.2 Template Budget

**Apa yang dilakukan:**
- Buat tabel `budget_templates`:
  ```
  tenant_id, name, budgets (JSON array of {category_id, amount, period, alert_thresholds}),
  created_at, updated_at
  ```
- Tambah route CRUD untuk template.
- Tambah tombol "Simpan sebagai Template" dan "Terapkan Template" di UI.
- Template tersimpan per tenant, bisa diterapkan ulang.

**File yang diubah:**
- `database/migrations/xxxx_create_budget_templates_table.php` (baru)
- `app/Models/BudgetTemplate.php` (baru)
- `app/Http/Controllers/BudgetTemplateController.php` (baru)
- `routes/web.php` (tambah route)
- `resources/js/pages/Budgets/Index.vue` (tambah UI template)

---

## Urutan Implementasi yang Disarankan

```
Fase 1.2 (Optimasi Query)     ←  mulai di sini, paling kritis untuk performa
    ↓
Fase 1.1 (Refaktorisasi Duplikasi)  ←  bersihkan kode sebelum tambah fitur baru
    ↓
Fase 1.3 (Konsistensi API)
    ↓
Fase 2.2 (Budget Global)      ←  fitur paling diminta pengguna
    ↓
Fase 2.1 (Tiered Alert)
    ↓
Fase 3.1 (Budget vs Actual Chart)
    ↓
Fase 3.2 (Export CSV)
    ↓
Fase 2.3 (Budget Rollover)
    ↓
Fase 3.3 (Budget History)
    ↓
Fase 4.1 (Smart Suggestion)
    ↓
Fase 4.2 (Template Budget)
```

---

## File yang Terdampak (Lengkap)

| File | Fase | Aksi |
|------|------|------|
| `app/Models/Budget.php` | 1.2, 2.1, 2.3 | Modifikasi |
| `app/Http/Controllers/BudgetController.php` | 1.2, 2.2, 3.1, 3.2 | Modifikasi |
| `app/Http/Controllers/Api/DashboardController.php` | 1.3 | Modifikasi |
| `app/Http/Controllers/DashboardController.php` | 1.2 | Modifikasi |
| `app/Services/Budget/BudgetCommandHandler.php` | 1.1 | Baru |
| `app/Services/Budget/BudgetAlertService.php` | 1.1, 2.1 | Modifikasi |
| `app/Services/Budget/BudgetCommandService.php` | 1.1 | Hapus |
| `app/Services/Budget/BudgetSuggestionService.php` | 4.1 | Baru |
| `app/Models/BudgetGlobal.php` | 2.2 | Baru |
| `app/Models/BudgetHistory.php` | 3.3 | Baru |
| `app/Models/BudgetTemplate.php` | 4.2 | Baru |
| `app/Observers/BudgetObserver.php` | 3.3 | Baru |
| `app/Console/Commands/BudgetRollover.php` | 2.3 | Baru |
| `resources/js/pages/Budgets/Index.vue` | 2.1-2.3, 3.1-3.3, 4.1-4.2 | Modifikasi |
| `resources/js/components/budgets/BudgetVsActualChart.vue` | 3.1 | Baru |
| `database/migrations/xxxx_add_alert_thresholds_to_budgets.php` | 2.1 | Baru |
| `database/migrations/xxxx_create_budget_globals_table.php` | 2.2 | Baru |
| `database/migrations/xxxx_add_rollover_to_budgets.php` | 2.3 | Baru |
| `database/migrations/xxxx_create_budget_histories_table.php` | 3.3 | Baru |
| `database/migrations/xxxx_create_budget_templates_table.php` | 4.2 | Baru |
| `routes/web.php` | 2.2, 3.2, 4.2 | Modifikasi |
| `routes/console.php` | 2.3 | Modifikasi |

---

## Estimasi Kompleksitas

| Fase | Komponen | Kompleksitas |
|------|----------|-------------|
| 1.1 | Refaktorisasi Duplikasi | Sedang |
| 1.2 | Optimasi N+1 Query | Rendah |
| 1.3 | Konsistensi API | Rendah |
| 2.1 | Tiered Alert System | Sedang |
| 2.2 | Budget Global | Tinggi |
| 2.3 | Budget Rollover | Sedang |
| 3.1 | Budget vs Actual Chart | Sedang |
| 3.2 | Export CSV | Rendah |
| 3.3 | Budget History | Sedang |
| 4.1 | Smart Suggestion | Tinggi |
| 4.2 | Template Budget | Sedang |
