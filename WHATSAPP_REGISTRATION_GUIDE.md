# 📱 Fitur Registrasi via WhatsApp

## 🎯 Tujuan
Memungkinkan user baru untuk mendaftar akun FinWa langsung melalui WhatsApp tanpa perlu mengakses website.

## ✨ Fitur
- ✅ Registrasi otomatis via chat WhatsApp
- ✅ Auto-generate password yang mudah diingat
- ✅ Trial 30 hari otomatis aktif
- ✅ Tidak mengganggu sistem registrasi yang sudah ada
- ✅ State management dengan Cache (30 menit timeout)
- ✅ Email validation
- ✅ Duplicate email check

## 🔄 Flow Registrasi

```
User baru kirim pesan apapun
    ↓
Bot: "👋 Halo! Sepertinya Anda belum terdaftar di FinWa.
      Mau daftar sekarang? Gratis trial 30 hari! 🎉
      Ketik Ya untuk daftar atau Tidak untuk batal."
    ↓
User: "Ya" / "Iya" / "OK" / "Daftar"
    ↓
Bot: "✅ Oke, mari kita daftar!
      Silakan kirim nama lengkap Anda:"
    ↓
User: "John Doe"
    ↓
Bot: "Terima kasih, John Doe! 👍
      Sekarang silakan kirim alamat email Anda:"
    ↓
User: "john@example.com"
    ↓
Bot: "🎉 Akun Berhasil Dibuat!

      📧 Email: john@example.com
      🔑 Password: ABC123
      
      🌐 Login di: https://finwa.web.id
      
      ✨ Trial 30 hari sudah aktif!
      
      Sekarang Anda bisa langsung kirim transaksi ke saya. Contoh:
      • beli makan 25rb
      • terima gaji 5jt
      
      Selamat mencoba! 🚀"
```

## 📁 File yang Dibuat

### 1. `app/Helpers/WhatsAppRegistrationHelper.php`
Helper class yang menangani:
- State management (Cache-based)
- Validation (email, confirmation words)
- Account creation (User, Tenant, Subscription)
- Message templates
- Password generation

### 2. `WHATSAPP_REGISTRATION_INTEGRATION.php`
Kode integrasi yang perlu ditambahkan ke `ProcessIncomingMessage.php`

## 🔧 Cara Implementasi

### Step 1: Import Helper
Tambahkan di bagian atas `app/Jobs/ProcessIncomingMessage.php`:

```php
use App\Helpers\WhatsAppRegistrationHelper as RegHelper;
```

### Step 2: Replace Logic Unregistered User
Cari bagian ini di `ProcessIncomingMessage.php` (sekitar line 230):

```php
// CASE B: REGULAR PHONE NUMBER (Unregistered)
if (!$correctTenantId && !$isLID && $this->message->tenant_id == 1) {
```

Replace seluruh block tersebut dengan kode dari `WHATSAPP_REGISTRATION_INTEGRATION.php`

## 🎨 Customization

### Ubah Durasi Trial
Edit di `WhatsAppRegistrationHelper.php` line 115 dan 147:
```php
'trial_ends_at' => Carbon::now()->addDays(30), // Ganti 30 ke angka lain
```

### Ubah Format Password
Edit method `generatePassword()` di `WhatsAppRegistrationHelper.php`:
```php
public static function generatePassword(): string
{
    // Contoh: 6 karakter random
    return strtoupper(Str::random(6));
}
```

### Ubah Pesan Template
Edit methods di `WhatsAppRegistrationHelper.php`:
- `getWelcomeMessage()` - Pesan awal
- `getAskNameMessage()` - Minta nama
- `getAskEmailMessage()` - Minta email
- `getSuccessMessage()` - Sukses registrasi
- `getCancellationMessage()` - Batal registrasi

### Tambah Kata Konfirmasi
Edit method `isConfirmation()`:
```php
$confirmWords = ['ya', 'iya', 'ok', 'oke', 'daftar', 'yes', 'yup', 'yoi', 'boleh', 'mau', 'siap'];
```

## 🔒 Keamanan

1. **Email Validation**: Menggunakan `filter_var()` PHP
2. **Duplicate Check**: Cek email sudah terdaftar atau belum
3. **Auto Email Verification**: Email langsung terverifikasi
4. **Password Hashing**: Menggunakan `Hash::make()`
5. **Session Timeout**: Flow registrasi expire dalam 30 menit

## 📊 Data yang Dibuat

Saat user berhasil daftar, sistem otomatis membuat:

1. **Tenant** (Business)
   - Name: "{User Name}'s Business"
   - Trial: 30 hari

2. **User** (Account)
   - Name, Email, Password (hashed)
   - Role: admin
   - Email verified: true

3. **UserWhatsAppNumber** (Mapping)
   - Primary number
   - Auto-linked ke user

4. **Subscription** (Trial)
   - Plan: trial
   - Duration: 30 hari
   - Status: active

## 🧪 Testing

### Test Flow Lengkap
1. Kirim pesan dari nomor yang belum terdaftar
2. Tunggu pesan welcome
3. Balas "Ya"
4. Kirim nama: "Test User"
5. Kirim email: "test@example.com"
6. Cek apakah akun berhasil dibuat

### Test Edge Cases
1. **Email invalid**: Kirim "emailsalah" → Harus minta ulang
2. **Email duplicate**: Gunakan email yang sudah ada → Harus reject
3. **Nama terlalu pendek**: Kirim "A" → Harus minta ulang
4. **Timeout**: Tunggu 30 menit → Flow harus reset
5. **Cancel**: Balas "Tidak" → Harus cancel flow

## 📝 Logs

Sistem akan log:
- Registration success: `WhatsApp Registration Success`
- Registration error: `WhatsApp Registration Error`
- Failed to send messages: `Failed to send registration start`, dll

Check logs di `storage/logs/laravel.log`

## ⚠️ Catatan Penting

1. **Tidak Mengganggu Sistem Lama**: Registrasi via website tetap berfungsi normal
2. **Cache Dependency**: Membutuhkan Cache driver (Redis/File)
3. **WhatsApp Service**: Harus tersedia dan berfungsi
4. **Auto-Verify Email**: User tidak perlu verifikasi email manual
5. **Password Dikirim Sekali**: Simpan password yang dikirim, tidak bisa di-recover

## 🚀 Deployment

1. Upload files:
   - `app/Helpers/WhatsAppRegistrationHelper.php`
   - Update `app/Jobs/ProcessIncomingMessage.php`

2. Clear cache:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

3. Test di production dengan nomor test

## 📞 Support

Jika ada masalah:
1. Check logs: `storage/logs/laravel.log`
2. Check cache: `php artisan cache:clear`
3. Check WhatsApp service status
4. Verify database connections

---

**Status**: ✅ Ready to implement
**Version**: 1.0
**Last Updated**: 2025-12-19
