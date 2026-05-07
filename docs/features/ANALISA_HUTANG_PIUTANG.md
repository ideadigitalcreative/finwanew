# Analisa Implementasi Fitur Hutang - Piutang (FinWa)

Dokumen ini berisi analisa teknis untuk penambahan kategori Hutang dan Piutang guna mendukung pencatatan keuangan yang lebih komprehensif.

## 1. Konsep Dasar Keuangan
Dalam pencatatan arus kas (Cashflow), arah dana ditentukan oleh perspektif pengguna:

| Transaksi | Kategori | Aliran Dana | Efek Saldo |
|-----------|----------|-------------|------------|
| **Menerima Pinjaman** (Hutang) | `pendapatan_hutang` | Masuk | **(+)** |
| **Membayar Hutang** | `pengeluaran_bayar_hutang` | Keluar | **(-)** |
| **Memberi Pinjaman** (Piutang) | `pengeluaran_piutang` | Keluar | **(-)** |
| **Menerima Pelunasan** (Piutang) | `pendapatan_terima_piutang` | Masuk | **(+)** |

## 2. Rencana Perubahan Database
Perlu dilakukan update pada `ENUM` kolom `type` di tabel `categories`.

### Migrasi Baru:
Menambahkan tipe kategori berikut:
- `pendapatan_hutang`
- `pengeluaran_bayar_hutang`
- `pengeluaran_piutang`
- `pendapatan_terima_piutang`

## 3. Logika Deteksi & Ekstraksi (NLP)
Pembaruan pada `TransactionExtractorService` dan `CategoryMappingService` untuk mendeteksi keywords:

### Keywords Hutang (Debt Payable):
- **Terima Hutang:** `pinjam`, `berhutang`, `kasih pinjaman ke saya`, `hutang ke`, `dapat pinjaman`.
- **Bayar Hutang:** `bayar hutang`, `balikin pinjaman`, `pelunasan hutang`.

### Keywords Piutang (Loan Receivable):
- **Kasih Piutang:** `kasih pinjam`, `pijamin duit`, `dipinjam`, `piutang ke`.
- **Terima Pelunasan:** `balikin duit saya`, `terima bayar hutang`, `piutang lunas`.

## 4. Alur Kerja Sistem
1. **Detection:** Bot mendeteksi niat (intent) pengguna melalui pesan teks.
2. **Classification:** Pesan diklasifikasikan ke salah satu dari 4 sub-kategori di atas.
3. **Balance Update:** `BalanceService` secara otomatis akan menambah saldo jika kategori berawalan `pendapatan_` dan mengurangi jika `pengeluaran_`.
4. **Transaction Record:** Transaksi disimpan dengan `category_type` yang sesuai untuk laporan bulanan.

## 5. Pengembangan Tahap Lanjut (Advanced)
Setelah fitur dasar (pencatatan) berjalan, dapat ditambahkan fitur tracking:
- **Entity Tracking:** Ekstraksi nama orang (misal: "pinjam 100rb dari *Budi*").
- **Hutang Ledger:** Menu khusus untuk melihat daftar saldo hutang/piutang yang belum lunas (Outstanding).
- **Reminders:** Pengingat otomatis untuk menagih piutang atau membayar hutang.

---
*Dibuat pada: 26 Maret 2026*
*Status: Analisis Selesai (Siap Implementasi)*

**Implementasi bertahap & ceklist:** [HUTANG_PIUTANG_IMPLEMENTATION_PLAN.md](./HUTANG_PIUTANG_IMPLEMENTATION_PLAN.md)
