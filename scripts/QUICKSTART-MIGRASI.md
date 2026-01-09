# Quick Start: Migrasi Database ke VPS 114.125.222.8

Panduan cepat untuk melakukan migrasi database ke VPS.

## Langkah Cepat (5 Menit)

### 1. Setup Database di VPS

Login ke VPS dan jalankan script setup:

```bash
# Upload script ke VPS
scp scripts/setup-vps-database.sh user@114.125.222.8:~/

# Login ke VPS
ssh user@114.125.222.8

# Jalankan script setup
chmod +x setup-vps-database.sh
./setup-vps-database.sh
```

Atau setup manual:

```bash
# Login ke PostgreSQL
sudo -u postgres psql

# Buat database
CREATE DATABASE keuangan_ai;

# Buat user
CREATE USER keuangan_user WITH PASSWORD 'password_anda';

# Berikan hak akses
GRANT ALL PRIVILEGES ON DATABASE keuangan_ai TO keuangan_user;
\q

# Konfigurasi remote access
sudo nano /etc/postgresql/*/main/postgresql.conf
# Ubah: listen_addresses = '*'

sudo nano /etc/postgresql/*/main/pg_hba.conf
# Tambahkan: host all all 0.0.0.0/0 md5

# Restart PostgreSQL
sudo systemctl restart postgresql

# Buka firewall
sudo ufw allow 5432/tcp
```

### 2. Migrasi Database dari Lokal

#### Opsi A: Migrasi Lengkap (Dengan Data)

```powershell
.\scripts\migrate-to-vps.ps1 `
  -LocalDbName "keuangan_ai" `
  -LocalDbUser "postgres" `
  -LocalDbPassword "password_lokal" `
  -VpsDbName "keuangan_ai" `
  -VpsDbUser "keuangan_user" `
  -VpsDbPassword "password_vps"
```

#### Opsi B: Migrasi Struktur Saja (Tanpa Data)

```powershell
.\scripts\migrate-structure-only.ps1 `
  -VpsDbName "keuangan_ai" `
  -VpsDbUser "keuangan_user" `
  -VpsDbPassword "password_vps"
```

### 3. Update Konfigurasi .env

```powershell
.\scripts\update-env-vps.ps1 `
  -VpsDbName "keuangan_ai" `
  -VpsDbUser "keuangan_user" `
  -VpsDbPassword "password_vps"
```

Atau edit manual file `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=114.125.222.8
DB_PORT=5432
DB_DATABASE=keuangan_ai
DB_USERNAME=keuangan_user
DB_PASSWORD=password_vps
```

### 4. Test Koneksi

```bash
php artisan db:show
```

### 5. Jalankan Migrations (Jika perlu)

```bash
php artisan migrate --force
```

## Troubleshooting Cepat

### Error: "connection refused"

**Solusi:**
1. Pastikan PostgreSQL di VPS sudah dikonfigurasi untuk remote connection
2. Pastikan firewall mengizinkan port 5432
3. Test dengan: `telnet 114.125.222.8 5432`

### Error: "authentication failed"

**Solusi:**
1. Periksa username dan password
2. Pastikan user memiliki hak akses: `GRANT ALL PRIVILEGES ON DATABASE keuangan_ai TO keuangan_user;`

### Error: "database does not exist"

**Solusi:**
1. Buat database terlebih dahulu: `CREATE DATABASE keuangan_ai;`

## Restore Konfigurasi Lokal

Jika ingin kembali ke database lokal:

```powershell
.\scripts\update-env-vps.ps1 -Restore
```

## File-file Script

- `migrate-to-vps.ps1` - Migrasi lengkap (data + struktur)
- `migrate-structure-only.ps1` - Migrasi struktur saja (menggunakan Laravel migrations)
- `update-env-vps.ps1` - Update file .env dengan konfigurasi VPS
- `setup-vps-database.sh` - Setup database di VPS (jalankan di VPS)
- `README-MIGRASI.md` - Dokumentasi lengkap

## Catatan Keamanan

1. **Jangan commit file .env** ke repository
2. **Gunakan password yang kuat** untuk database
3. **Batasi akses** dengan firewall (whitelist IP)
4. **Gunakan SSL/TLS** untuk koneksi database jika memungkinkan
5. **Backup database** secara berkala

## Support

Jika mengalami masalah, lihat dokumentasi lengkap di `scripts/README-MIGRASI.md`

