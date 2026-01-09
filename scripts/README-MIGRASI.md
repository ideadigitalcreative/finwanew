# Panduan Migrasi Database ke VPS 114.125.222.8

Dokumen ini menjelaskan langkah-langkah untuk melakukan migrasi database dari lokal ke VPS dengan IP `114.125.222.8`.

## Prasyarat

1. **PostgreSQL Client Tools** terinstall di komputer lokal
   - Download: https://www.postgresql.org/download/windows/
   - Pastikan `pg_dump`, `pg_restore`, dan `psql` tersedia di PATH

2. **Akses ke VPS**
   - IP VPS: `114.125.222.8`
   - Port PostgreSQL: `5432` (default)
   - Database sudah dibuat di VPS
   - User database sudah dibuat dan memiliki hak akses

3. **Konfigurasi PostgreSQL di VPS**
   - PostgreSQL sudah dikonfigurasi untuk menerima remote connection
   - File `postgresql.conf`: `listen_addresses = '*'`
   - File `pg_hba.conf`: Tambahkan rule untuk remote access
   - Firewall VPS sudah mengizinkan koneksi ke port 5432

## Metode Migrasi

### Metode 1: Migrasi Lengkap (Data + Struktur)

Gunakan script `migrate-to-vps.ps1` untuk melakukan dump database lokal dan import ke VPS.

#### Langkah-langkah:

1. **Persiapkan database di VPS**

   ```bash
   # Login ke VPS
   ssh user@114.125.222.8
   
   # Login ke PostgreSQL
   sudo -u postgres psql
   
   # Buat database
   CREATE DATABASE keuangan_ai;
   
   # Buat user (jika belum ada)
   CREATE USER keuangan_user WITH PASSWORD 'password_anda';
   
   # Berikan hak akses
   GRANT ALL PRIVILEGES ON DATABASE keuangan_ai TO keuangan_user;
   \q
   ```

2. **Jalankan script migrasi**

   ```powershell
   # Dengan parameter lengkap
   .\scripts\migrate-to-vps.ps1 `
     -LocalDbName "keuangan_ai" `
     -LocalDbUser "postgres" `
     -LocalDbPassword "password_lokal" `
     -VpsDbName "keuangan_ai" `
     -VpsDbUser "keuangan_user" `
     -VpsDbPassword "password_vps"
   
   # Atau, jika sudah ada di .env, cukup:
   .\scripts\migrate-to-vps.ps1 `
     -VpsDbName "keuangan_ai" `
     -VpsDbUser "keuangan_user" `
     -VpsDbPassword "password_vps"
   ```

3. **Update file .env**

   Edit file `.env` dan update konfigurasi database:

   ```env
   DB_CONNECTION=pgsql
   DB_HOST=114.125.222.8
   DB_PORT=5432
   DB_DATABASE=keuangan_ai
   DB_USERNAME=keuangan_user
   DB_PASSWORD=password_vps
   ```

4. **Test koneksi**

   ```bash
   php artisan db:show
   ```

### Metode 2: Migrasi Struktur Saja (Tanpa Data)

Gunakan script `migrate-structure-only.ps1` jika Anda hanya ingin membuat struktur database di VPS menggunakan Laravel migrations.

#### Langkah-langkah:

1. **Persiapkan database di VPS** (sama seperti Metode 1)

2. **Jalankan script**

   ```powershell
   .\scripts\migrate-structure-only.ps1 `
     -VpsDbName "keuangan_ai" `
     -VpsDbUser "keuangan_user" `
     -VpsDbPassword "password_vps"
   ```

   Script ini akan:
   - Backup file `.env`
   - Update `.env` dengan konfigurasi VPS
   - Test koneksi database
   - Jalankan Laravel migrations
   - File `.env` tetap menggunakan konfigurasi VPS

3. **Jika ingin mengembalikan konfigurasi lokal**

   ```powershell
   Copy-Item '.env.backup.*' '.env' -Force
   ```

### Metode 3: Manual Migration (Menggunakan Laravel Migrations)

Jika Anda ingin melakukan migrasi manual:

1. **Update file .env** dengan konfigurasi VPS

2. **Test koneksi**
   ```bash
   php artisan db:show
   ```

3. **Jalankan migrations**
   ```bash
   php artisan migrate --force
   ```

4. **Jika ada data yang perlu di-import**, gunakan `pg_dump` dan `pg_restore` secara manual:

   ```bash
   # Dump database lokal
   pg_dump -h localhost -U postgres -d keuangan_ai -F c -f backup.dump
   
   # Import ke VPS
   pg_restore -h 114.125.222.8 -U keuangan_user -d keuangan_ai -v backup.dump
   ```

## Konfigurasi PostgreSQL di VPS

### 1. Edit postgresql.conf

```bash
sudo nano /etc/postgresql/*/main/postgresql.conf
```

Cari dan ubah:
```conf
listen_addresses = '*'
```

### 2. Edit pg_hba.conf

```bash
sudo nano /etc/postgresql/*/main/pg_hba.conf
```

Tambahkan di akhir file:
```conf
# Allow remote connections
host    all             all             0.0.0.0/0               md5
```

### 3. Restart PostgreSQL

```bash
sudo systemctl restart postgresql
```

### 4. Konfigurasi Firewall

```bash
# UFW
sudo ufw allow 5432/tcp

# atau iptables
sudo iptables -A INPUT -p tcp --dport 5432 -j ACCEPT
```

## Troubleshooting

### Error: "connection to server at 114.125.222.8, port 5432 failed"

**Solusi:**
1. Pastikan PostgreSQL di VPS sudah dikonfigurasi untuk remote connection
2. Pastikan firewall VPS mengizinkan koneksi ke port 5432
3. Pastikan IP Anda tidak diblokir di VPS

### Error: "authentication failed for user"

**Solusi:**
1. Pastikan username dan password benar
2. Pastikan user memiliki hak akses ke database
3. Periksa file `pg_hba.conf` di VPS

### Error: "database does not exist"

**Solusi:**
1. Buat database terlebih dahulu di VPS
2. Pastikan nama database benar di parameter script

### Error: "permission denied"

**Solusi:**
1. Pastikan user memiliki hak akses yang cukup
2. Jalankan: `GRANT ALL PRIVILEGES ON DATABASE keuangan_ai TO keuangan_user;`
3. Untuk schema public: `GRANT ALL ON SCHEMA public TO keuangan_user;`

## Keamanan

1. **Gunakan SSL/TLS** untuk koneksi database jika memungkinkan
2. **Gunakan password yang kuat** untuk user database
3. **Batasi akses** dengan firewall (hanya IP tertentu yang bisa akses)
4. **Gunakan VPN** atau SSH tunnel untuk koneksi yang lebih aman

## Backup & Restore

### Backup Database di VPS

```bash
pg_dump -h 114.125.222.8 -U keuangan_user -d keuangan_ai -F c -f backup_vps.dump
```

### Restore Database

```bash
pg_restore -h 114.125.222.8 -U keuangan_user -d keuangan_ai -v backup_vps.dump
```

## Catatan Penting

1. **Backup database lokal** sebelum melakukan migrasi
2. **Test koneksi** sebelum menjalankan migrasi
3. **Pastikan semua migration files** sudah di-commit ke repository
4. **Update file .env** setelah migrasi selesai
5. **Test aplikasi** setelah migrasi untuk memastikan semua berfungsi

## Support

Jika mengalami masalah, periksa:
1. Log PostgreSQL di VPS: `/var/log/postgresql/`
2. Log aplikasi Laravel: `storage/logs/laravel.log`
3. Test koneksi manual dengan `psql`

