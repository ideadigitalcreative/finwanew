# 🧪 Panduan Uji Coba Koreksi Transaksi Cerdas

Dokumen ini berisi **contoh pesan WhatsApp** yang bisa Anda kirim untuk menguji setiap fitur baru dari Fase 1-4.

## 📋 Sebelum Mulai

Pastikan:
- [x] Aplikasi sudah di-deploy dengan commit terbaru (`0f88c33` atau lebih baru)
- [x] Config sudah di-clear: `php artisan config:clear`
- [x] Queue worker berjalan: `php artisan queue:work`
- [x] Log aktif: `tail -f storage/logs/laravel.log`

---

## 🔬 Skenario Uji Coba

### SKENARIO 1: Fase 1 — Deteksi Income (Bug "Uang Lembur")

**Tujuan:** Membuktikan "Uang lembur" sekarang terdeteksi sebagai **income**, bukan expense.

**Langkah:**
```
Kirim pesan:
"Uang lembur pondok cabe 1,5 juta"
```

**Hasil yang diharapkan:**
```
✅ Berhasil Dicatat! 💸

📥 Pemasukan Rp 1.500.000
📁 Bonus / Pendapatan Tambahan • uang lembur pondok cabe 1,5 juta
```

**❌ Jika masih salah (BUG):**
```
✅ Berhasil Dicatat! 💸

💸 Pengeluaran Rp 1.500.000
🍜 Bahan Makanan & Bumbu Dapur • uang lembur pondok cabe 1,5 juta
```
→ Jalankan `php artisan config:clear` lalu coba lagi.

---

### SKENARIO 2: Fase 2 — Hapus Transaksi dengan "Revisi"

**Tujuan:** Membuktikan kata "Revisi" sekarang dikenali dan bisa hapus transaksi.

**Prasyarat:** Punya transaksi terakhir (dari Skenario 1 atau transaksi lain).

**Langkah:**
```
Kirim pesan:
"Revisi: hapus uang lembur pondok cabe"
```

**Hasil yang diharapkan:**
```
🗑️ Transaksi Dihapus

Transaksi "uang lembur pondok cabe" berhasil dihapus.
```

**Variasi lain yang harus bekerja:**
- `"Perbaiki: hapus beli kue"`
- `"Revisi hapus makan siang"`
- `"Hapus transaksi terakhir"`

---

### SKENARIO 3: Fase 3 — Ubah Tipe Transaksi

**Tujuan:** Membuktikan "Ganti jadi pemasukan" sekarang benar-benar mengubah tipe.

**Prasyarat:** Punya transaksi **expense** (bukan income). Misalnya setelah Skenario 1 dihapus, buat dulu:
```
"beli obat 50rb"
```
→ Ini akan jadi **Pengeluaran**

**Langkah ubah tipe:**
```
Kirim pesan:
"Ganti jadi 'pemasukan'"
```
atau
```
"ubah ke income"
```

**Hasil yang diharapkan:**
```
✅ Transaksi Dikoreksi

🔄 Tipe: ~Pengeluaran~ ➝ *Pemasukan*
📁 Kategori: ~Obat / Perawatan~ ➝ *Lainnya* (auto-remap)

📝 beli obat 50rb
_Data berhasil diperbarui_
```

**⚠️ Penting:** Cek saldo harus berubah!
- Jika awalnya expense 50rb, saldo **berkurang** 50rb
- Setelah ubah ke income, saldo **bertambah** 50rb
- **Total perubahan saldo: +100rb** (cancel -50rb + apply +50rb)

---

### SKENARIO 4: Fase 4A — Ask-Back untuk Perintah Ambigu

**Tujuan:** Membuktikan "Ubah kategori" sekarang **bertanya**, bukan kirim template.

**Prasyarat:** Punya transaksi terakhir.

**Langkah:**
```
Kirim pesan:
"Ubah kategori"
```

**Hasil yang diharapkan (yang BARU ✅):**
```
✏️ Ubah Transaksi

Transaksi terakhir:
• 📁 Bahan Makanan & Bumbu Dapur
• 💰 Rp 50.000
• 🔄 Pengeluaran
━━━━━━━━━━━━━━━

Mau diubah ke kategori apa?

Contoh: _Hiburan_, _Transport_, _Makanan_
```

**❌ Hasil lama (BUG jika masih muncul):**
```
✏️ Edit Transaksi

Anda bisa mengedit transaksi terakhir dengan format:
• Edit nominal: edit jadi [nominal]
• Edit kategori: edit kategori [nama kategori]
• Hapus: hapus transaksi
```

---

### SKENARIO 5: Fase 4B — Jawaban Ask-Back (Konversasi 2 Langkah)

**Tujuan:** Membuktikan bot bisa menerima jawaban dan menerapkannya.

**Langkah 1:** Trigger ask-back (sama seperti Skenario 4)
```
"Ubah kategori"
```
→ Bot bertanya: "Mau diubah ke kategori apa?"

**Langkah 2:** Jawab dengan nama kategori
```
"Hiburan"
```

**Hasil yang diharapkan:**
```
✅ Kategori Diperbarui

📁 ~Bahan Makanan & Bumbu Dapur~ ➝ *Hiburan*

_Data berhasil diperbarui_
```

---

### SKENARIO 6: Fase 4C — Ask-Back untuk Nominal

**Langkah 1:**
```
"Ganti nominal"
```
→ Bot bertanya: "Mau diubah jadi berapa?"

**Langkah 2:**
```
"75rb"
```

**Hasil:**
```
✅ Nominal Diperbarui

💰 ~50.000~ ➝ *75.000*

_Data berhasil diperbarui_
```

---

### SKENARIO 7: Fase 4D — Ask-Back untuk Tipe

**Langkah 1:**
```
"Ubah tipe"
```
→ Bot bertanya: "Mau diubah jadi *Pemasukan* atau *Pengeluaran*?"

**Langkah 2:**
```
"pemasukan"
```

**Hasil:**
```
✅ Tipe Diperbarui

🔄 ~Pengeluaran~ ➝ *Pemasukan*

_Data berhasil diperbarui_
```

---

## 🎯 Quick Test Sequence (Copy-Paste Semua)

Urutan cepat untuk menguji semua fitur dalam satu sesi:

```
1. "Uang lembur pondok cabe 1,5 juta"
   → Harus: 📥 Pemasukan (bukan 💸 Pengeluaran)

2. "Ganti jadi pengeluaran"
   → Harus: 🔄 Tipe berubah + kategori remap

3. "Ubah kategori"
   → Harus: Bot tanya "Mau diubah ke kategori apa?"

4. "Hiburan"
   → Harus: 📁 Kategori → Hiburan

5. "Ganti nominal"
   → Harus: Bot tanya "Mau diubah jadi berapa?"

6. "100rb"
   → Harus: 💰 Nominal → 100.000

7. "Revisi: hapus ini"
   → Harus: 🗑️ Transaksi dihapus
```

---

## 🔍 Debugging Jika Gagal

### Cek Log Real-time:
```bash
# Buka terminal baru
tail -f storage/logs/laravel.log | grep -E "(Fast-path|Pending edit|Ask-back|Type change)"
```

### Cek Config Sudah Terload:
```bash
php artisan tinker
>>> config('finwa_category_rules.income_detection_keywords')
// Harus ada: 'lembur', 'uang lembur', 'insentif', dll

>>> config('finwa_category_rules.type_change_keywords')
// Harus ada: 'pemasukan' => 'income', 'pengeluaran' => 'expense'
```

### Cek Test Lagi:
```bash
php artisan test --filter=TransactionCorrectionIntelligenceTest
// Harus: 15 passed
```

---

## 📊 Checklist Uji Coba

| # | Skenario | Pesan Test | Hasil ✅/❌ | Catatan |
|---|----------|------------|------------|---------|
| 1 | Income Detection | "Uang lembur pondok cabe 1,5 juta" | ⬜ | Harus income |
| 2 | Revisi:Hapus | "Revisi: hapus <nama>" | ⬜ | Harus terhapus |
| 3 | Ubah Tipe | "Ganti jadi pemasukan" | ⬜ | Tipe berubah |
| 4 | Ask-Back Kategori | "Ubah kategori" | ⬜ | Bot tanya |
| 5 | Jawab Kategori | "<nama kategori>" | ⬜ | Kategori berubah |
| 6 | Ask-Back Nominal | "Ganti nominal" | ⬜ | Bot tanya |
| 7 | Jawab Nominal | "<nominal>" | ⬜ | Nominal berubah |
| 8 | Ask-Back Tipe | "Ubah tipe" | ⬜ | Bot tanya |
| 9 | Jawab Tipe | "pemasukan" / "pengeluaran" | ⬜ | Tipe berubah |

---

## 💡 Tips Pengujian

1. **Tunggu 1-2 detik** antara pesan agar queue diproses
2. **Cek saldo** sebelum dan sesudah ubah tipe (harus ada perubahan 2x amount)
3. **Jangan kirim terlalu cepat** antara trigger ask-back dan jawaban (max 5 menit)
4. **Jika ask-back expired**, ulangi dari langkah trigger
5. **Gunakan log** untuk melihat fast-path mana yang aktif:
   ```
   Fast-path 1.6af2.4: Perintah ubah tipe...
   Fast-path 1.6af2.6: Perintah edit ambigu terdeteksi...
   ```

---

**Selamat menguji! 🚀**

Jika ada skenario yang tidak bekerja seperti harapan, cek log dan laporkan pesan errornya.
