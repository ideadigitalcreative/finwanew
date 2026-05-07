# PANDUAN: Cara Input Transaksi dengan Multiple Items

## ❌ Format yang TIDAK DIDUKUNG (saat ini):

```
beli 
- tahu petis 10k
- pecel ayam 10k
```
**Hasil:** Hanya 10k yang tercatat (item pertama saja)

---

## ✅ SOLUSI - 3 Cara Input yang Benar:

### **Cara 1: Tulis Total Langsung** (RECOMMENDED)
```
beli tahu petis dan pecel ayam 20rb
```
**Hasil:** 20rb tercatat ✅

### **Cara 2: Pisah Jadi 2 Pesan**
Pesan 1:
```
tahu petis 10k
```
Pesan 2:
```
pecel ayam 10k
```
**Hasil:** 2 transaksi terpisah (10k + 10k) ✅

### **Cara 3: Format dengan Kata Kunci**
```
belanja 20rb untuk tahu petis dan pecel ayam
```
**Hasil:** 20rb tercatat ✅

---

## 📝 Tips Lainnya:

- **Gunakan total** jika belanja banyak item sekaligus
- **Pisah pesan** jika ingin track item terpisah
- **Tulis deskripsi** di bagian belakang nominal

---

## 🔮 Coming Soon:

Fitur batch transaction (auto-sum multiple amounts) sedang dalam development dan akan segera tersedia!

