# Panduan Login ke VPS 114.125.222.8

## Informasi Koneksi

- **Host**: 114.125.222.8
- **User**: ubuntu
- **Password**: Sembarang123
- **Port SSH**: 22 (default)

## Cara Login

### Menggunakan PowerShell (Windows)

```powershell
ssh ubuntu@114.125.222.8
```

Ketika diminta password, ketik: `Sembarang123`

### Menggunakan Command Prompt (Windows)

```cmd
ssh ubuntu@114.125.222.8
```

### Menggunakan PuTTY

1. Download PuTTY: https://www.putty.org/
2. Buka PuTTY
3. Masukkan:
   - Host Name: `114.125.222.8`
   - Port: `22`
   - Connection Type: `SSH`
4. Klik "Open"
5. Login dengan:
   - Username: `ubuntu`
   - Password: `Sembarang123`

## Troubleshooting

### Error: "Connection timed out"

**Kemungkinan penyebab:**
1. VPS belum fully online (tunggu beberapa menit)
2. Firewall VPS memblokir SSH
3. Port SSH bukan 22
4. Network issue

**Solusi:**
1. Cek status VPS di control panel hosting
2. Pastikan firewall VPS mengizinkan port 22
3. Coba port alternatif (jika menggunakan custom port)
4. Cek koneksi internet Anda

### Error: "Connection refused"

**Kemungkinan penyebab:**
1. SSH service belum running di VPS
2. Port SSH ditutup

**Solusi:**
1. Cek status SSH di VPS (jika sudah punya akses)
2. Pastikan SSH service running: `sudo systemctl status ssh`

### Error: "Permission denied"

**Kemungkinan penyebab:**
1. Username atau password salah
2. User tidak memiliki akses SSH

**Solusi:**
1. Pastikan username dan password benar
2. Hubungi administrator VPS

## Setup Database Setelah Login

Setelah berhasil login ke VPS, jalankan perintah berikut:

### 1. Update System

```bash
sudo apt-get update
```

### 2. Install PostgreSQL (jika belum ada)

```bash
sudo apt-get install -y postgresql postgresql-contrib
```

### 3. Setup Database

```bash
# Login ke PostgreSQL
sudo -u postgres psql

# Buat database
CREATE DATABASE dbku;

# Buat user
CREATE USER userku WITH PASSWORD 'passwordku123';

# Berikan hak akses
GRANT ALL PRIVILEGES ON DATABASE dbku TO userku;
ALTER DATABASE dbku OWNER TO userku;
\q
```

### 4. Berikan Hak Akses Schema

```bash
sudo -u postgres psql -d dbku
```

Kemudian jalankan:

```sql
GRANT ALL ON SCHEMA public TO userku;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO userku;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO userku;
\q
```

### 5. Konfigurasi PostgreSQL untuk Remote Access

```bash
# Edit postgresql.conf
sudo nano /etc/postgresql/*/main/postgresql.conf
```

Cari dan ubah:
```
listen_addresses = '*'
```

Simpan dengan `Ctrl+X`, lalu `Y`, lalu `Enter`.

### 6. Edit pg_hba.conf

```bash
sudo nano /etc/postgresql/*/main/pg_hba.conf
```

Tambahkan di akhir file:
```
host    all             all             0.0.0.0/0               md5
```

Simpan dengan `Ctrl+X`, lalu `Y`, lalu `Enter`.

### 7. Restart PostgreSQL

```bash
sudo systemctl restart postgresql
```

### 8. Konfigurasi Firewall

```bash
# UFW
sudo ufw allow 5432/tcp

# Atau iptables
sudo iptables -A INPUT -p tcp --dport 5432 -j ACCEPT
```

### 9. Verifikasi Setup

```bash
# Test koneksi lokal
sudo -u postgres psql -d dbku -c "\l"
```

## Menggunakan Script Otomatis

Jika sudah berhasil login, Anda bisa menggunakan script otomatis:

### 1. Upload Script ke VPS

Dari komputer lokal (PowerShell):

```powershell
scp scripts\vps-setup-auto.sh ubuntu@114.125.222.8:~/
```

### 2. Jalankan Script di VPS

Setelah login ke VPS:

```bash
chmod +x vps-setup-auto.sh
./vps-setup-auto.sh
```

## Informasi Database Setelah Setup

- **Host**: 114.125.222.8
- **Port**: 5432
- **Database**: dbku
- **User**: userku
- **Password**: passwordku123

## Catatan Penting

1. **Keamanan**: Ganti password default dengan password yang lebih kuat
2. **Firewall**: Pastikan hanya IP yang dipercaya yang bisa akses database
3. **Backup**: Lakukan backup database secara berkala
4. **Monitoring**: Monitor koneksi dan aktivitas database

## Langkah Selanjutnya

Setelah database sudah di-setup di VPS, lanjutkan dengan:

1. **Update file .env** di komputer lokal dengan konfigurasi VPS
2. **Test koneksi** dari lokal ke VPS database
3. **Jalankan migrations** atau import database

Lihat file `QUICKSTART-MIGRASI.md` untuk langkah selanjutnya.

