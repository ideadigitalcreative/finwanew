# Analisa Alur Transaksi & Kategori — Finwa

> Dokumen ini merupakan hasil analisa kode sumber. Tidak ada perubahan yang dilakukan.

---

## 1. Struktur Data

### Tabel `transactions`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `tenant_id` | FK | Multi-tenant isolation |
| `category_id` | FK | Wajib, restrict on delete |
| `message_id` | FK nullable | Link ke pesan WhatsApp asal |
| `balance_id` | FK nullable | Dompet/rekening yang digunakan |
| `type` | enum | `income`, `expense`, `debit_internal`, `kredit_internal` |
| `amount` | decimal(15,2) | Nominal transaksi |
| `transaction_date` | date | Tanggal transaksi (bisa berbeda dari `created_at`) |
| `source` | string nullable | Sumber dana atau tujuan |
| `description` | text | Deskripsi transaksi |
| `reference_number` | string nullable | Nomor invoice/struk |
| `confidence_score` | decimal(3,2) | Skor kepercayaan AI (0.00–1.00) |
| `status` | enum | `pending`, `confirmed`, `review`, `rejected` |
| `reviewed_by` | FK nullable | User yang mereview |
| `reviewed_at` | timestamp nullable | Waktu review |
| `metadata` | json nullable | Data tambahan (counterparty, attachment, group info, dll) |

**Index yang ada:**
- `(tenant_id, transaction_date)`
- `(tenant_id, type, transaction_date)`
- `(tenant_id, category_id)`
- `(status, confidence_score)`

### Tabel `categories`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | Primary key |
| `tenant_id` | FK | Per-tenant — setiap tenant punya set kategori sendiri |
| `type` | enum | Kode kategori sistem (lihat daftar lengkap di bawah) |
| `name` | string | Nama tampilan |
| `slug` | string | URL-friendly identifier |
| `description` | text nullable | Deskripsi |
| `icon` | string nullable | Emoji icon |
| `color` | string nullable | Hex color |
| `is_system` | boolean | `true` = kategori default sistem |

**Unique constraint:** `(tenant_id, type, slug)`

### Tabel `category_corrections` (Feedback Loop)

| Kolom | Keterangan |
|---|---|
| `tenant_id` | Tenant pemilik koreksi |
| `original_text` | Teks transaksi asli (dinormalisasi lowercase) |
| `original_category` | Kategori yang salah (sebelum koreksi) |
| `corrected_category` | Kategori yang benar (setelah koreksi user) |
| `merchant` | Nama merchant (opsional) |
| `amount` | Nominal (opsional) |
| `frequency` | Berapa kali koreksi yang sama dilakukan (auto-increment) |

---

## 2. Daftar Tipe Kategori

### Pendapatan (10 tipe)

| Type | Nama | Icon |
|---|---|---|
| `pendapatan_gaji` | Gaji | 💰 |
| `pendapatan_bonus` | Bonus | 🎁 |
| `pendapatan_investasi` | Investasi | 📈 |
| `pendapatan_transfer` | Transfer Masuk | 📥 |
| `pendapatan_usaha` | Pendapatan Usaha | 🏪 |
| `pendapatan_sewa` | Pendapatan Sewa | 🏘️ |
| `pendapatan_refund` | Refund & Cashback | 💸 |
| `pendapatan_hutang` | Terima Hutang (Pinjaman Masuk) | 📥 |
| `pendapatan_terima_piutang` | Terima Pelunasan Piutang | ✅ |
| `pendapatan_lainnya` | Pendapatan Lainnya | 💵 |

### Pengeluaran (34+ tipe)

| Type | Nama | Icon |
|---|---|---|
| `pengeluaran_makanan` | Makanan & Minuman | 🍽️ |
| `pengeluaran_transport` | Transport | 🚗 |
| `pengeluaran_hunian` | Hunian | 🏠 |
| `pengeluaran_utilitas` | Utilitas | ⚡ |
| `pengeluaran_kesehatan` | Kesehatan | 🏥 |
| `pengeluaran_pendidikan` | Pendidikan | 📚 |
| `pengeluaran_belanja` | Belanja | 🛒 |
| `pengeluaran_hiburan` | Hiburan | 🎬 |
| `pengeluaran_pulsa_token` | Pulsa & Token | 📱 |
| `pengeluaran_tagihan` | Tagihan | 📄 |
| `pengeluaran_investasi` | Investasi | 💼 |
| `pengeluaran_pinjaman` | Pinjaman | 💳 |
| `pengeluaran_bayar_hutang` | Bayar Hutang | 💸 |
| `pengeluaran_piutang` | Piutang (Pinjaman Keluar) | 🤝 |
| `pengeluaran_cicilan` | Cicilan | 🏦 |
| `pengeluaran_asuransi` | Asuransi | 🛡️ |
| `pengeluaran_pajak` | Pajak | 📊 |
| `pengeluaran_donasi` | Donasi | ❤️ |
| `pengeluaran_gaji` | Gaji Karyawan | 👷 |
| `pengeluaran_keluarga` | Keluarga | 👨‍👩‍👧‍👦 |
| `pengeluaran_baby` | Baby & Anak | 👶 |
| `pengeluaran_langganan` | Langganan | 🔄 |
| `pengeluaran_pakaian` | Pakaian & Fashion | 👕 |
| `pengeluaran_perawatan_diri` | Perawatan Diri | 💇 |
| `pengeluaran_acara` | Acara & Hajatan | 🎊 |
| `pengeluaran_otomotif` | Otomotif | 🔧 |
| `pengeluaran_sosial` | Sosial & Kondangan | 🤝 |
| `pengeluaran_hadiah` | Hadiah & Bingkisan | 🎁 |
| `pengeluaran_hewan` | Hewan Peliharaan | 🐾 |
| `pengeluaran_gadget` | Gadget & Elektronik | 📱 |
| `pengeluaran_modal` | Modal & Stok | 📦 |
| `pengeluaran_operasional` | Operasional | ⚙️ |
| `pengeluaran_transfer` | Transfer Keluar | 📤 |
| `pengeluaran_lainnya` | Pengeluaran Lainnya | 📝 |

### Internal Transfer (2 tipe)

| Type | Nama |
|---|---|
| `debit_internal` | Debit Antar Dompet |
| `kredit_internal` | Kredit Antar Dompet |

> **Catatan:** Migration awal hanya mendefinisikan 19 tipe di enum, namun `CategoryManagerService::createCategoriesForTenant()` membuat 46+ kategori. Ada **ketidaksesuaian** antara enum di migration dan kategori yang dibuat di runtime — kategori tambahan dibuat via `Category::firstOrCreate()` tanpa constraint enum di DB.

---

## 3. Relasi Antar Model

```
Tenant ──< Category ──< Transaction >── Balance
                              │
                              ├── Message (WhatsApp asal)
                              └── User (reviewer)

Tenant ──< CategoryCorrection (feedback loop)
```

- `Transaction` → belongs to `Category`, `Tenant`, `Balance`, `Message`, `User` (reviewer)
- `Category` → has many `Transaction`, belongs to `Tenant`
- `CategoryCorrection` → belongs to `Tenant`

---

## 4. Alur Pembuatan Transaksi

### A. Manual via Dashboard (Web)

```
User → POST /transactions/parse (text preview)
     → TransactionController::parse()
     → TransactionParserService::parse()
         ├── extractAmountFromText()
         ├── detectType() → income/expense
         ├── CategoryMappingService::resolveCategoryWithConfidence()
         └── return: amount, category, date, balances, alternatives

User → POST /transactions/store-json (simpan)
     → TransactionController::storeJson()
         ├── Validasi input
         ├── SubscriptionLimitService::canCreateTransaction()
         ├── Transaction::create()
         └── Balance::increment/decrement (jika status=confirmed)
```

**Validasi `storeJson`:**
- `type`: required, in: `income`/`expense`
- `amount`: required, numeric, min:100
- `description`: required, max:255
- `category_id`: nullable, exists:categories
- `balance_id`: nullable, exists:balances
- `transaction_date`: nullable, date
- `status`: nullable, in: `confirmed`/`review`

### B. Otomatis via WhatsApp

```
Pesan WhatsApp masuk
    → POST /api/webhooks/whatsapp/message
    → WhatsAppWebhookController
    → Message::create() (simpan ke DB)
    → ProcessIncomingMessage::dispatch() [Queue Job async]
         │
         ├── [type=text]  → processTextMessage()
         ├── [type=image] → OcrProcessorService (scan struk via Gemini/OCR worker)
         └── [type=audio] → SttProcessorService → transcribe → processTextMessage()
```

### C. Alur Detail `processTextMessage()` di Job

```
processTextMessage(text)
    │
    ├── KeywordNormalizer::normalize() — normalisasi variasi kata
    ├── ConversationContextService — cek context percakapan sebelumnya
    ├── Cek pending transaction (user kirim deskripsi tanpa nominal sebelumnya)
    ├── Cek pending confirmation (user balas "ya"/"iya"/"ok")
    ├── Typo correction (hapud→hapus, edif→edit, dll)
    ├── BatchTransaction check → handleBatchTransactions()
    │
    ├── Fast Path (tanpa AI):
    │   ├── Help/Greeting → GreetingService
    │   ├── Query keyword + period → FinancialQueryHandler
    │   ├── Reminder commands → ReminderCommandService
    │   ├── Budget commands → BudgetCommandService
    │   ├── Savings target → SavingsGoalService
    │   ├── Wallet commands → WalletCommandService
    │   ├── Delete/Edit transaction → TransactionService
    │   └── Transaction keyword + amount → TransactionService::handleTransaction()
    │
    └── AI Path (jika fast path tidak cocok):
        ├── FinWaAIService::classifyIntent() — intent + entity extraction
        └── AIProcessorService::classifyIntent() — fallback
```

### D. Alur Detail `handleTransaction()` di TransactionService

```
handleTransaction(messageText, finwaEntities?)
    │
    ├── [1] FinWa-AI Fast Path (jika finwaEntities ada & nominal > 0):
    │       ├── Deteksi income/expense:
    │       │   ├── Expense override patterns (config)
    │       │   ├── "bayar" keyword (word boundary)
    │       │   ├── FinWa intent: catat_pemasukan / catat_pengeluaran
    │       │   └── Income keywords (config)
    │       ├── Hutang/piutang intent validation (keyword guard)
    │       ├── AI category override (config: ai_category_overrides, ai_income_overrides)
    │       ├── Map kategori AI → category_type
    │       └── Build transaction array (source='finwa_ai', confidence=0.95)
    │
    ├── [2] Local Extraction Fallback (tanpa AI):
    │       → TransactionExtractorService::extractTransactionLocally()
    │           ├── extractAmountFromText() — support: 15rb, 50.000, 1jt, Rp 100000
    │           ├── detectHutangPiutangLocalExtraction() — frasa eksplisit
    │           ├── Expense override patterns
    │           ├── Income keywords
    │           └── extractDateFromText() — kemarin, tgl 15, 15/12, dll
    │           (source='local_extraction', confidence=0.85)
    │
    ├── [3] AIProcessorService Fallback (external microservice):
    │       → POST http://ai-processor:8001/extract-transaction
    │       → timeout 120s
    │
    ├── [4] CategoryInferenceService (selalu dijalankan setelah extract):
    │       → infer(description)
    │           ├── detectIntentType() — income/expense hint
    │           ├── matchKeywords() — dari config finwa_category_rules
    │           ├── applyContextBoosts() — regex patterns (+skor)
    │           ├── decide() — scoring, ambil kategori tertinggi
    │           └── validateWithGemini() — opsional, jika confidence < 0.85
    │       → Override category_type jika confidence >= 0.4
    │
    └── [5] createTransaction() → simpan ke DB
```

### E. Alur `createTransaction()`

```
createTransaction(txData, needsReview)
    │
    ├── SubscriptionLimitService::canCreateTransaction() — cek limit bulanan
    ├── Guard: pastikan prefix category_type sesuai type (income/expense)
    ├── Category::where('tenant_id', ..., 'type', category_type)->first()
    │   ├── Jika tidak ada → createCategoriesForTenant() (self-healing)
    │   ├── Jika masih tidak ada → fallback ke pengeluaran_lainnya / pendapatan_lainnya
    │   └── CategoryManagerService::ensureCategoryMetadata() (self-heal nama/icon)
    ├── BalanceService::findOrCreateBalance() atau getDefaultBalance()
    ├── Build metadata (group info, counterparty untuk hutang/piutang)
    ├── Transaction::create()
    │   └── status = 'review' jika needsReview=true ATAU confidence_score < 0.7
    ├── ConversationContextService::storeLastTransactionId()
    ├── BalanceService::updateBalanceFromTransaction() (jika status=confirmed)
    ├── BudgetAlertService::checkBudgetAlert() (jika expense + confirmed)
    └── AchievementService::checkAfterTransaction() (jika confirmed)
```

---

## 5. Bagaimana Kategori Ditentukan

### Pipeline Penentuan Kategori (6 Lapisan, Prioritas Tertinggi ke Terendah)

```
Prioritas 1 — User Feedback Loop (CategoryCorrectionService)
    → Cek category_corrections berdasarkan teks yang dinormalisasi
    → Jika tidak ada, cek berdasarkan merchant
    → Confidence: 0.95

Prioritas 2 — AI Category (FinWa-AI / AIProcessorService)
    → Map via config finwa_category_rules.ai_category_map
    → Confidence: 0.90

Prioritas 3 — Merchant-based Lookup
    → config merchant_categories.merchants
    → Weighted match (keyword terpanjang menang)
    → Confidence: 0.85

Prioritas 4 — Weighted Keyword Matching
    → config finwa_category_rules.expense_keywords / income_keywords
    → Keyword terpanjang yang cocok menang (anti first-match-wins bug)
    → Confidence: 0.50–0.80

Prioritas 5 — Nominal-based Disambiguation
    → Hanya aktif jika kategori masih generic (pengeluaran_belanja/lainnya)
    → amount >= 500k + kata "bayar" → pengeluaran_tagihan
    → amount <= 15k + pengeluaran_belanja → pengeluaran_makanan
    → Confidence: 0.55

Prioritas 6 — Default Fallback
    → pendapatan_lainnya / pengeluaran_lainnya
    → Confidence: 0.30
```

### Context Boosts di CategoryInferenceService

`applyContextBoosts()` menambah skor untuk pola regex spesifik:

| Pattern | Kategori | Boost |
|---|---|---|
| `bayar hutang/utang` | `pengeluaran_bayar_hutang` | +60 |
| `bayar cicilan/angsuran` | `pengeluaran_cicilan` | +55 |
| `bayar asuransi/premi/bpjs` | `pengeluaran_asuransi` | +50 |
| `bayar pajak/pph/ppn` | `pengeluaran_pajak` | +55 |
| `bayar pinjaman/pinjol/paylater` | `pengeluaran_pinjaman` | +55 |
| `servis/service motor/mobil` | `pengeluaran_otomotif` | +45 |
| `ganti oli/ban/aki` | `pengeluaran_otomotif` | +45 |
| `beli popok/susu bayi/pampers` | `pengeluaran_baby` | +45 |
| `beli pakan/kucing/anjing` | `pengeluaran_hewan` | +45 |
| `beli hp/laptop/charger` | `pengeluaran_gadget` | +45 |
| `terima gaji/honor` | `pendapatan_gaji` | +55 |
| `terima piutang/pelunasan` | `pendapatan_terima_piutang` | +50 |
| `kasih undangan/hajatan` | `pengeluaran_acara` | +50 |
| `kasih gaji/upah` | `pengeluaran_gaji` | +55 |

### Feedback Loop (Self-Learning)

Ketika user mengedit kategori transaksi di dashboard atau via WhatsApp:

```
TransactionController::update() atau handleEditTransaction()
    → Deteksi perubahan category_id
    → CategoryCorrectionService::recordCorrection()
        → Jika sudah ada record yang sama → increment frequency
        → Jika belum ada → buat record baru
    → Berikutnya, teks yang sama → langsung pakai corrected_category (confidence 0.95)
```

### Validasi Gemini AI (Opsional)

Aktif jika `finwa_category_rules.gemini_validation_enabled = true`:
- Dipanggil jika confidence < 0.85 dan source bukan `debt_flow_detection`
- `GeminiAIService::validateCategory()` → validasi/koreksi kategori
- Jika Gemini mengoreksi → source = `gemini_ai_validation`

---

## 6. Business Rules & Validasi

### Subscription Limit
- `SubscriptionLimitService::canCreateTransaction()` dicek sebelum setiap create
- Jika limit tercapai → transaksi ditolak, user diberi notifikasi upgrade
- OCR (scan struk foto) hanya untuk paket Grow/Pro

### Balance Sync
- Setiap perubahan status transaksi ke/dari `confirmed` → balance di-sync
- `BalanceService::updateBalanceFromTransaction()` → increment/decrement
- `BalanceService::reverseBalanceUpdate()` → rollback jika status berubah dari confirmed
- Saat delete transaksi confirmed → balance di-reverse dulu
- Saat hapus semua transaksi → semua balance di-reset ke 0

### Tenant Isolation
- Semua query selalu di-filter `tenant_id`
- Kategori per-tenant: setiap tenant punya salinan kategori sendiri (bukan shared)
- Middleware `EnsureTenantAccess` memastikan user hanya akses tenant miliknya

### Hutang/Piutang Guard
- Intent hutang/piutang dari AI hanya dipercaya jika teks mengandung keyword eksplisit
- Keywords guard: `hutang`, `utang`, `piutang`, `pinjam`, `pinjaman`, `pinjem`, `lunas`, dll
- Mencegah misklasifikasi seperti "Nasi kuning 32rb" → `catat_piutang`

### Status Workflow Transaksi
```
pending  → (jarang digunakan)
confirmed → status default untuk transaksi dari WhatsApp dengan confidence >= 0.7
review    → confidence < 0.7 ATAU needsReview=true (perlu ditinjau manual)
rejected  → ditolak oleh reviewer
```

### Pending Transaction Context
- User kirim "naik ojek" (tanpa nominal) → disimpan sebagai pending di cache
- Pesan berikutnya berupa nominal saja ("15rb") → digabung → diproses sebagai satu transaksi
- Pending confirmation: user kirim pesan ambigu → sistem tanya konfirmasi → user balas "ya"

### Category Self-Healing
- Jika kategori tidak ditemukan saat create transaksi → `createCategoriesForTenant()` dipanggil otomatis
- Jika masih tidak ada → fallback ke `pengeluaran_lainnya` / `pendapatan_lainnya`
- `ensureCategoryMetadata()` memperbaiki nama/icon kategori yang corrupt di runtime

---

## 7. Routes Transaksi

### Web Routes (middleware: auth + tenant + subscription)

| Method | Path | Action |
|---|---|---|
| GET | `/transactions` | List + filter transaksi |
| POST | `/transactions/parse` | Parse text → preview sebelum simpan |
| POST | `/transactions/parse-receipt` | OCR struk foto via Gemini |
| POST | `/transactions/store-json` | Simpan transaksi baru |
| GET | `/transactions/{id}` | Detail transaksi |
| PATCH | `/transactions/{id}/status` | Update status (confirmed/review/rejected) |
| PUT | `/transactions/{id}` | Update transaksi (amount, category, date, dll) |
| DELETE | `/transactions/{id}` | Hapus transaksi |
| POST | `/transactions/{id}/upload-attachment` | Upload lampiran |
| DELETE | `/transactions/{id}/delete-attachment` | Hapus lampiran |

### API Routes (middleware: Sanctum auth + subscription)

| Method | Path | Action |
|---|---|---|
| GET | `/api/auth/transactions` | List transaksi |
| POST | `/api/auth/transactions` | Create transaksi |
| POST | `/api/auth/transactions/parse` | Parse text |

### Webhook Routes (middleware: API Key)

| Method | Path | Action |
|---|---|---|
| POST | `/api/webhooks/whatsapp/message` | Terima pesan WhatsApp |
| POST | `/api/webhooks/whatsapp/attachment` | Terima attachment |
| POST | `/api/webhooks/whatsapp/from-engine` | Terima dari WA blast engine |

---

## 8. AI Processing Summary

| Service | Fungsi | Timeout |
|---|---|---|
| `FinWaAIService` | Klasifikasi intent + ekstraksi entitas (internal) | — |
| `AIProcessorService` | Ekstraksi transaksi dari teks (external microservice port 8001) | 120s |
| `CategoryInferenceService` | Keyword matching + scoring (lokal, tanpa AI) | — |
| `GeminiAIService` | Validasi kategori + OCR struk (opsional) | — |

**Urutan prioritas AI untuk ekstraksi transaksi:**
1. FinWa-AI entities (jika tersedia dan nominal > 0) → confidence 0.95
2. Local extraction (regex + keyword) → confidence 0.85
3. AIProcessorService (external LLM) → confidence dari response
4. CategoryInferenceService selalu dijalankan di atas hasil manapun untuk override/refine kategori

---

## 9. Temuan & Catatan

### Potensi Masalah

1. **Ketidaksesuaian enum migration vs runtime**
   - Migration `create_categories_table` mendefinisikan 19 tipe enum
   - `CategoryManagerService::createCategoriesForTenant()` membuat 46+ kategori
   - Kategori tambahan (seperti `pengeluaran_gaji`, `pengeluaran_baby`, dll) tidak ada di enum migration
   - Ini bisa menyebabkan error jika DB strict mode aktif atau jika migration dijalankan ulang

2. **Callback hell di TransactionService constructor**
   - Constructor menerima 8 callable sebagai parameter
   - Ini membuat dependency injection sangat kompleks dan sulit di-test
   - Refactoring ke interface/contract akan lebih bersih

3. **`createTransactionFromData()` tidak menggunakan `category_id`**
   - Method ini menyimpan `category_type` langsung ke kolom `category` (yang tidak ada di schema)
   - Berbeda dengan `createTransaction()` yang benar-benar lookup `Category` model
   - Kemungkinan ada bug atau kolom yang tidak konsisten

4. **Timeout AI processor 120 detik**
   - Queue job bisa tertahan lama jika AI processor lambat
   - Tidak ada circuit breaker yang terlihat

5. **Category correction tidak di-invalidate**
   - Jika user mengoreksi kategori berkali-kali untuk teks yang sama, semua record disimpan
   - Hanya yang `frequency` tertinggi yang dipakai, tapi tidak ada cleanup untuk record lama

### Hal yang Sudah Baik

- **Multi-layer fallback** untuk ekstraksi transaksi (FinWa-AI → local → external AI)
- **Self-healing** untuk kategori yang hilang atau corrupt
- **Feedback loop** yang belajar dari koreksi user
- **Keyword guard** untuk hutang/piutang mencegah misklasifikasi
- **Pending transaction context** untuk UX yang lebih natural di WhatsApp
- **Subscription limit check** di semua jalur create transaksi
- **Balance sync** yang konsisten di semua operasi (create, update, delete)
