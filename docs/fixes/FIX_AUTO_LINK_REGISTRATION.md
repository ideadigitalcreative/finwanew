# Fix untuk Auto-Link pada Registrasi Website

## Masalah
Saat user mendaftar via website:
1. User daftar dan langsung menerima pesan "Akun Aktif" via WhatsApp ✅
2. Tapi saat membalas, diminta kirim nomor lagi untuk "menghubungkan perangkat"

## Akar Masalah
WhatsApp Desktop/Web menggunakan **LID (Linked ID)** bukan nomor telepon.
Saat sistem mengirim pesan welcome, sistem tahu nomor tujuan (`6285159205506`).
Tapi saat user membalas, gateway hanya tahu LID (`218442590343379@lid`).
Gateway **tidak menyertakan** nomor telepon asli dalam payload pesan masuk.

## Solusi yang Diimplementasi

### ✅ Modifikasi WhatsApp Gateway (SUDAH DONE)
File: `services/whatsapp-gateway/index.js`

Saat gateway **mengirim** pesan ke nomor telepon, Baileys mungkin mengembalikan 
LID di response (`result.key.remoteJid`). Gateway sekarang akan:

1. Capture LID dari response saat berhasil mengirim
2. Jika target adalah nomor telepon (bukan LID) dan response berisi LID
3. Panggil backend `/api/webhooks/whatsapp/lid-mapping` untuk menyimpan mapping

```javascript
// AUTO LID MAPPING (Sudah ditambahkan di index.js line ~754-820)
if (isLidResult && isPhoneNumberTarget) {
    // Save mapping to backend
    axios.post(lidMappingUrl, {
        lid: lidValue,
        phoneNumber: normalizedPhone,
        sessionId: sessionId
    });
}
```

### ✅ Pesan Reconnect yang Lebih User-Friendly (SUDAH DONE)
File: `app/Services/WhatsAppWebhookService.php`

Pesan "Update Sistem FinWa" diubah menjadi "Selamat Datang di FinWa" yang lebih
ramah untuk user baru.

## Deployment Instructions

### 1. Deploy Gateway ke Server
```bash
# SSH ke server
ssh user@finwa.web.id

# Masuk ke direktori gateway
cd /var/www/finwaweb/data/www/finwa.web.id/services/whatsapp-gateway

# Backup file lama
cp index.js index.js.backup

# Upload file baru (dari local)
# ATAU copy paste konten yang sudah diupdate

# Restart gateway
pm2 restart whatsapp-gateway
# atau
pm2 restart 2
```

### 2. Verify Gateway Running
```bash
pm2 logs whatsapp-gateway --lines 50
```

### 3. Test Flow
1. Daftar user baru via website
2. User akan menerima pesan welcome
3. Cek log gateway untuk melihat apakah LID mapping terkirim
4. User balas dengan transaksi → seharusnya langsung diproses (tanpa minta link lagi)

## Status Implementasi
- [x] Gateway dimodifikasi untuk auto-report LID mapping saat mengirim pesan
- [x] Pesan reconnect sudah diubah menjadi lebih user-friendly
- [ ] Deploy ke server production (PERLU DILAKUKAN MANUAL)

