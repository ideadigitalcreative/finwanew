# Telegram Config Troubleshooting

## Problem
Error: `Cannot assign null to property App\Services\TelegramService::$botToken of type string`

## Diagnosis

### 1. Check Current Directory di Server Production
Pastikan Anda berada di root project Laravel:
```bash
pwd  # Harusnya: ~/www/finwa.web.id
ls -la .env  # Harusnya muncul
```

### 2. Check .env File
```bash
cat .env | grep TELEGRAM
```

Output yang benar:
```
TELEGRAM_BOT_TOKEN=8523096414:AAHOrklpDf9h1MmvaTwkx70oESGMMGqqCtg
TELEGRAM_SECRET_TOKEN=1b3614b749fda13678235f9baf0f08a6
TELEGRAM_WEBHOOK_SECRET=1b3614b749fda13678235f9baf0f08a6
```

### 3. Check Config Cache
```bash
# Clear semua cache
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Re-cache
php artisan config:cache
php artisan route:cache
```

### 4. Test Config Value
```bash
# Via tinker
php artisan tinker --execute="var_dump(config('services.telegram.bot_token'));"

# Atau jalankan test script
php test-telegram.php
```

### 5. Check File Permissions
```bash
# .env harus readable oleh PHP
ls -la .env

# Jika perlu, fix permissions
chmod 640 .env
chown finwaweb:finwaweb .env
```

### 6. Check PHP Version
```bash
php -v
# Laravel 11 butuh PHP 8.2+
```

## Common Issues

### Issue: Wrong Working Directory
**Solution:** Pastikan berada di `~/www/finwa.web.id`, bukan di subdirectory

### Issue: Config Cache Stale
**Solution:** Jalankan `php artisan config:clear` dan `php artisan config:cache`

### Issue: .env File Not Found
**Solution:** Pastikan .env ada di root project, bukan di subdirectory

### Issue: File Permission Denied
**Solution:** Fix permissions dengan chmod/chown

## Verification Checklist

- [ ] Berada di root project (`pwd` menunjuk ke ~/www/finwa.web.id)
- [ ] File .env ada dan readable
- [ ] TELEGRAM_BOT_TOKEN terisi di .env
- [ ] Config cache sudah di-clear
- [ ] test-telegram.php berjalan tanpa error

## Quick Fix Commands
```bash
cd ~/www/finwa.web.id
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php test-telegram.php
```
