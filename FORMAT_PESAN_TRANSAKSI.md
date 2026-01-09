# 📝 FORMAT PESAN TRANSAKSI YANG DIDUKUNG FINWA

## ✅ **1. TRANSAKSI TUNGGAL**

### Format Dasar:
```
[action] [deskripsi] [jumlah]
```

### Contoh:
```
beli makan siang 25rb
bayar parkir 5000
makan nasi goreng 15.000
jajan gorengan 2rb
terima gaji 5jt
dapat bonus 500k
```

### Variasi Amount:
- `25rb` atau `25ribu` → 25.000
- `5k` → 5.000
- `2jt` atau `2juta` → 2.000.000
- `15.000` → 15.000
- `50000` → 50.000

---

## ✅ **2. BATCH TRANSAKSI (BANYAK SEKALIGUS)**

### Format A: Simple Multi-Line (RECOMMENDED)
```
Makan siang gado gado 13rb
Gorengan 2rb
Kopi 5rb
```

**Keuntungan:** Paling simple, tidak perlu numbering

### Format B: Numbered List
```
1. Makan siang 25rb
2. Transport 15rb
3. Parkir 5rb
```

### Format C: Bullet List
```
- Sarapan 10rb
- Bensin 50rb
- Pulsa 25rb
```

### Format D: Dengan Header + Tanggal
```
Pengeluaran tanggal 19 Desember 2025
1. Makan malam 17.000
2. Grab pulang 13.500
3. Parkir 5.000
```

### Format E: Dengan Header Kategori
```
Belanja hari ini:
• Sayur 25rb
• Daging 75rb
• Buah 30rb
```

---

## ✅ **3. ACTION KEYWORDS YANG DIDUKUNG**

### Pengeluaran:
- **Makanan:** `makan`, `sarapan`, `siang`, `malam`, `jajan`, `gorengan`, `snack`, `cemilan`
- **Belanja:** `beli`, `belanja`, `bayar`
- **Transport:** `ongkos`, `ongkir`, `grab`, `gojek`, `ojek`
- **Utilitas:** `bensin`, `parkir`, `pulsa`, `token`, `listrik`, `air`
- **Donasi:** `sedekah`, `infaq`, `zakat`, `sumbangan`, `donasi`
- **Transfer:** `kirim`, `transfer`, `tf`, `kasih`, `ngasih`

### Pemasukan:
- `terima`, `dapat`, `dapet`, `gaji`, `bonus`, `honor`

---

## ✅ **4. FORMAT KHUSUS**

### Transfer/Kasih ke Orang:
```
kasih mama 100rb
transfer adik 50rb
kirimin istri 200rb
```

### Pemasukan dari Orang:
```
terima dari Budi 500rb
dapat dari client 2jt
gaji bulan ini 5jt
```

### Dengan Merchant/Lokasi:
```
makan di warteg 15rb
belanja di indomaret 50rb
bensin di pertamina 100rb
```

---

## ✅ **5. TIPS MENULIS TRANSAKSI**

### ✅ DO (Disarankan):
```
✅ makan siang 25rb
✅ Makan siang gado gado 13rb
✅ 1. Sarapan 10rb
✅ beli makan siang 38.000
✅ terima gaji 5jt
```

### ❌ DON'T (Hindari):
```
❌ 25rb makan (amount di depan)
❌ makan (tanpa amount)
❌ 25000 (hanya amount tanpa deskripsi)
❌ mkn sgn (terlalu singkat/typo)
```

---

## ✅ **6. CONTOH REAL-WORLD**

### Pengeluaran Harian:
```
Sarapan nasi uduk 10rb
Kopi pagi 5rb
Makan siang warteg 15rb
Gorengan sore 3rb
Makan malam 25rb
```

### Belanja Bulanan:
```
Belanja bulanan:
1. Beras 10kg 150rb
2. Minyak goreng 50rb
3. Gula 25rb
4. Telur 30rb
5. Sayuran 40rb
```

### Transport Harian:
```
1. Grab pagi ke kantor 25rb
2. Makan siang 20rb
3. Grab pulang 30rb
4. Parkir 5rb
```

### Tagihan Bulanan:
```
Bayar tagihan Desember:
- Listrik 350rb
- Air 75rb
- Internet 300rb
- Pulsa 50rb
```

---

## ✅ **7. FITUR OTOMATIS**

### Auto-Detection:
- ✅ **Kategori otomatis** (makanan, transport, dll)
- ✅ **Tipe otomatis** (income/expense)
- ✅ **Tanggal otomatis** (hari ini)
- ✅ **Batch detection** (2+ transaksi)

### Smart Parsing:
- ✅ Support berbagai format amount (rb, k, jt, ribu, juta)
- ✅ Support dengan/tanpa titik pemisah (25.000 atau 25000)
- ✅ Support multi-line tanpa numbering
- ✅ Support emoji dan karakter khusus

---

## ✅ **8. QUERY/CEK SALDO**

### Format Query:
```
saldo
cek saldo
total pengeluaran bulan ini
pengeluaran hari ini
pemasukan minggu ini
```

---

## 📊 **SUMMARY**

**Format Paling Mudah:**
```
[action] [deskripsi] [amount]

Contoh:
makan siang 25rb
```

**Untuk Banyak Transaksi:**
```
[action] [deskripsi] [amount]
[action] [deskripsi] [amount]
[action] [deskripsi] [amount]

Contoh:
Sarapan 10rb
Kopi 5rb
Transport 15rb
```

**Sistem akan otomatis:**
1. ✅ Deteksi kategori
2. ✅ Deteksi tipe (income/expense)
3. ✅ Parse amount (rb, k, jt)
4. ✅ Create transaksi terpisah untuk batch
5. ✅ Sum total untuk konfirmasi

---

**Status:** ✅ Production Ready
**Last Updated:** 2025-12-19
