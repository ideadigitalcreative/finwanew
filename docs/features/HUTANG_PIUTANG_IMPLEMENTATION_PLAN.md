# Rencana Implementasi Hutang & Piutang (FinWa)

Dokumen ini melengkapi [ANALISA_HUTANG_PIUTANG.md](./ANALISA_HUTANG_PIUTANG.md) dengan **urutan kerja bertahap**, **ceklist**, dan status implementasi. Prinsip: **tambah** perilaku baru tanpa menghapus fungsi yang ada; `pengeluaran_pinjaman` / `pengeluaran_cicilan` tetap dipakai untuk skenario pinjaman umum.

## Ringkasan model (empat aliran)

| Aliran | `categories.type` | `transactions.type` | Efek kas (dompet) |
|--------|-------------------|---------------------|-------------------|
| Terima pinjaman (hutang naik, uang masuk) | `pendapatan_hutang` | `income` | + saldo |
| Bayar hutang | `pengeluaran_bayar_hutang` | `expense` | − saldo |
| Beri pinjaman / piutang usaha (uang keluar) | `pengeluaran_piutang` | `expense` | − saldo |
| Terima pelunasan piutang | `pendapatan_terima_piutang` | `income` | + saldo |

**Catatan DB:** pada **MySQL**, kolom `categories.type` memakai `ENUM` sehingga migrasi harus memperluas daftar nilai. Pada **SQLite** (default `.env.example`), kolom setara string — migrasi hanya menambah baris kategori; tidak ada `ALTER ENUM`.

---

## Fase 1 — Data & schema (pondasi)

| # | Item | Status |
|---|------|--------|
| 1.1 | Migrasi: tambah 4 nilai ENUM `categories.type` (MySQL) | [x] |
| 1.2 | Migrasi: `insertOrIgnore` 4 kategori sistem per tenant yang sudah ada | [x] |
| 1.3 | `CategorySeeder`: tambah 4 kategori agar tenant **baru** ikut dapat seed yang sama | [x] |
| 1.4 | `CategorySeeder`: deskripsi `getCategoryDescription()` untuk 4 tipe | [x] |
| 1.5 | Dokumen rencana ini + ceklist | [x] |

**Belum termasuk Fase 1:** perubahan UI, WhatsApp, atau AI (sengaja dipisah).

---

## Fase 2 — Pemetaan kategori & ekstraksi teks

| # | Item | Status |
|---|------|--------|
| 2.1 | `CategoryMappingService`: pemetaan kata kunci khusus (mis. `bayar hutang`, `terima pinjaman`, `pinjam dari`, `kasih pinjam ke`) ke 4 tipe **tanpa** mengorbankan mapping `pinjaman` → `pengeluaran_pinjaman` untuk kasus generik | [x] |
| 2.2 | `TransactionExtractorService` / keyword list: selaraskan frasa hutang/piutang dengan tipe baru (hindari bentrok dengan “piutang lunas” → `pendapatan_lainnya` jika masih diperlukan untuk kompatibilitas) | [x] |
| 2.3 | `config/finwa_keywords.php`: tambah frasa deteksi (opsional, selaras dengan 2.1) | [x] |

---

## Fase 3 — AI / intent (FinWa-AI & gateway)

| # | Item | Status |
|---|------|--------|
| 3.1 | Pastikan intent `catat_hutang`, `catat_piutang`, `bayar_hutang`, `terima_piutang` (sudah ada mapping string di `FinWaAIService`) terhubung ke **satu** alur penyimpanan transaksi yang memilih `category_id` dari 4 tipe di atas | [x] |
| 3.2 | Prompt / schema respons AI (Ai-Finwa atau service terkait): keluarkan `category_type` atau `category_slug` yang valid untuk 4 tipe | [x] |
| 3.3 | Uji end-to-end: pesan WA → transaksi tercatat dengan kategori benar | [x] |

---

## Konvensi `transactions.metadata` (hutang/piutang)

Kolom `metadata` (JSON) sudah ada di tabel `transactions`. Untuk empat tipe hutang/piutang backend mengisi (jika terdeteksi):

| Kunci | Tipe | Keterangan |
|--------|------|------------|
| `counterparty` | string | Nama pihak lawan (tampilan). |
| `counterparty_normalized` | string | Kunci agregasi: lowercase, spasi tunggal. |
| `debt_flow` | string | Salah satu: `terima_hutang`, `bayar_hutang`, `keluar_piutang`, `terima_piutang` (sesuai `categories.type`). |

Sumber nama: (1) entitas FinWa `lawan` / `pihak` / `counterparty` / `nama_pihak` / `nama_lawan`, (2) ekstraksi pola dari deskripsi (`dari` / `ke` / `sama` + nama) lewat `CounterpartyExtractor`.

**Pengingat (WA):** tabel `reminders` memiliki kolom `metadata` (JSON) dengan kunci yang sama bila pengingat dibuat lewat chat untuk frasa hutang/piutang (mis. _ingatkan bayar hutang ke budi tanggal 10 2jt_). Scheduler `finwa:send-reminders` mengirim saran kalimat catat yang selaras dengan aliran hutang/piutang.

---

## Fase 4 — Ledger & entitas (fitur “lengkap” bisnis)

| # | Item | Status |
|---|------|--------|
| 4.1 | Skema atau konvensi metadata (mis. `transactions.metadata` / JSON) untuk **counterparty** (nama pihak: Budi, supplier, dll.) | [x] |
| 4.2 | Agregasi saldo outstanding per counterparty (hutang vs piutang) dari transaksi | [x] |
| 4.3 | Halaman Inertia: ringkasan Hutang & Piutang (read-only dulu) | [x] |
| 4.4 | Reminder terjadwal: tagihan piutang / jatuh tempo hutang (integrasi dengan `Reminder` bila perlu) | [x] |

---

## Fase 5 — API & mobile (opsional)

| # | Item | Status |
|---|------|--------|
| 5.1 | Endpoint API (Sanctum) untuk daftar outstanding / transaksi per tipe hutang-piutang | [ ] |
| 5.2 | Dokumentasi singkat untuk klien Flutter | [ ] |

---

## Risiko & mitigasi

- **MySQL vs SQLite:** migrasi Fase 1 membedakan driver; produksi MySQL wajib menjalankan migrasi sebelum insert kategori baru.
- **Kompatibilitas lama:** transaksi dengan `pengeluaran_pinjaman` tidak diubah; pengguna bisa tetap memakainya untuk angsuran/kredit generik.
- **Duplikasi konsep:** dokumentasikan di UI/panduan perbedaan “Pinjaman (cicilan/umum)” vs “Hutang/piutang (per lawan transaksi)”.

---

## Riwayat

| Tanggal | Perubahan |
|---------|-----------|
| 2026-04-17 | Fase 1: migrasi + `CategorySeeder`; dokumen rencana awal |
| 2026-04-17 | Fase 2–3: mapping + ekstraksi lokal + routing intent + override `TransactionService` |
| 2026-04-17 | Fase 4.1–4.3: `CounterpartyExtractor`, `DebtReceivableLedgerService`, metadata + halaman `/hutang-piutang` |
| 2026-04-17 | Fase 4.4: tag hutang/piutang di `ReminderCommandService`, kolom `reminders.metadata`, saran WA di `SendReminders` |
| 2026-04-17 | Fase 3.2–3.3: `config/finwa_ai_hutang_piutang.php`, payload `core_api_contract`, `FinWaDebtReceivableResponseNormalizer`, tes Pest |
