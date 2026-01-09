#!/bin/bash
# Script untuk Setup Database di VPS 114.125.222.8
# Jalankan script ini di VPS untuk mempersiapkan database

set -e

# Warna output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

function print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

function print_error() {
    echo -e "${RED}ERROR: $1${NC}"
}

function print_info() {
    echo -e "${CYAN}$1${NC}"
}

function print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Konfigurasi
DB_NAME="${DB_NAME:-keuangan_ai}"
DB_USER="${DB_USER:-keuangan_user}"
DB_PASSWORD="${DB_PASSWORD:-}"

echo ""
print_info "========================================="
print_info "  SETUP DATABASE DI VPS"
print_info "========================================="
echo ""

# Cek apakah script dijalankan sebagai root atau dengan sudo
if [ "$EUID" -ne 0 ]; then 
    print_warning "Script ini memerlukan akses sudo untuk beberapa operasi"
    echo ""
fi

# Input database name
read -p "Nama database [default: $DB_NAME]: " input_db_name
DB_NAME=${input_db_name:-$DB_NAME}

# Input database user
read -p "Username database [default: $DB_USER]: " input_db_user
DB_USER=${input_db_user:-$DB_USER}

# Input database password
if [ -z "$DB_PASSWORD" ]; then
    read -sp "Password database: " DB_PASSWORD
    echo ""
    read -sp "Konfirmasi password: " DB_PASSWORD_CONFIRM
    echo ""
    
    if [ "$DB_PASSWORD" != "$DB_PASSWORD_CONFIRM" ]; then
        print_error "Password tidak cocok!"
        exit 1
    fi
fi

print_info ""
print_info "Konfigurasi:"
print_info "  Database: $DB_NAME"
print_info "  User: $DB_USER"
print_info ""

# Cek apakah PostgreSQL terinstall
if ! command -v psql &> /dev/null; then
    print_error "PostgreSQL tidak ditemukan. Install PostgreSQL terlebih dahulu."
    print_info "Install dengan: sudo apt-get install postgresql postgresql-contrib"
    exit 1
fi

print_success "PostgreSQL ditemukan"

# Buat database
print_info ""
print_info "Membuat database..."
sudo -u postgres psql <<EOF
-- Buat database jika belum ada
SELECT 'CREATE DATABASE $DB_NAME'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$DB_NAME')\gexec

-- Buat user jika belum ada
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_user WHERE usename = '$DB_USER') THEN
    CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';
  END IF;
END
\$\$;

-- Berikan hak akses
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
ALTER DATABASE $DB_NAME OWNER TO $DB_USER;
EOF

if [ $? -eq 0 ]; then
    print_success "Database dan user berhasil dibuat"
else
    print_error "Gagal membuat database atau user"
    exit 1
fi

# Berikan hak akses ke schema public
print_info ""
print_info "Mengatur hak akses schema public..."
sudo -u postgres psql -d $DB_NAME <<EOF
GRANT ALL ON SCHEMA public TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $DB_USER;
EOF

print_success "Hak akses schema public berhasil diatur"

# Konfigurasi PostgreSQL untuk remote connection
print_info ""
print_info "Mengonfigurasi PostgreSQL untuk remote connection..."

# Cari versi PostgreSQL
PG_VERSION=$(sudo -u postgres psql -t -c "SELECT version();" | grep -oP '\d+' | head -1)

if [ -z "$PG_VERSION" ]; then
    PG_VERSION=$(psql --version | grep -oP '\d+' | head -1)
fi

PG_CONF="/etc/postgresql/$PG_VERSION/main/postgresql.conf"
PG_HBA="/etc/postgresql/$PG_VERSION/main/pg_hba.conf"

if [ ! -f "$PG_CONF" ]; then
    # Coba lokasi alternatif
    PG_CONF="/var/lib/pgsql/data/postgresql.conf"
    PG_HBA="/var/lib/pgsql/data/pg_hba.conf"
fi

if [ -f "$PG_CONF" ]; then
    # Update postgresql.conf
    if ! grep -q "listen_addresses = '*'" "$PG_CONF"; then
        print_info "Mengupdate postgresql.conf..."
        sudo sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/g" "$PG_CONF"
        print_success "postgresql.conf diupdate"
    else
        print_success "postgresql.conf sudah dikonfigurasi"
    fi
else
    print_warning "File postgresql.conf tidak ditemukan di $PG_CONF"
    print_info "Silakan konfigurasi manual: listen_addresses = '*'"
fi

if [ -f "$PG_HBA" ]; then
    # Update pg_hba.conf
    if ! grep -q "host    all             all             0.0.0.0/0" "$PG_HBA"; then
        print_info "Mengupdate pg_hba.conf..."
        echo "host    all             all             0.0.0.0/0               md5" | sudo tee -a "$PG_HBA" > /dev/null
        print_success "pg_hba.conf diupdate"
    else
        print_success "pg_hba.conf sudah dikonfigurasi"
    fi
else
    print_warning "File pg_hba.conf tidak ditemukan di $PG_HBA"
    print_info "Silakan tambahkan manual: host all all 0.0.0.0/0 md5"
fi

# Restart PostgreSQL
print_info ""
print_info "Merestart PostgreSQL..."
if command -v systemctl &> /dev/null; then
    sudo systemctl restart postgresql
    print_success "PostgreSQL direstart"
elif command -v service &> /dev/null; then
    sudo service postgresql restart
    print_success "PostgreSQL direstart"
else
    print_warning "Tidak dapat restart PostgreSQL secara otomatis"
    print_info "Silakan restart manual: sudo systemctl restart postgresql"
fi

# Konfigurasi firewall
print_info ""
print_info "Mengonfigurasi firewall..."
if command -v ufw &> /dev/null; then
    sudo ufw allow 5432/tcp
    print_success "Firewall UFW dikonfigurasi"
elif command -v firewall-cmd &> /dev/null; then
    sudo firewall-cmd --permanent --add-port=5432/tcp
    sudo firewall-cmd --reload
    print_success "Firewall firewalld dikonfigurasi"
else
    print_warning "Firewall tool tidak ditemukan"
    print_info "Pastikan port 5432 terbuka di firewall VPS"
fi

# Test koneksi
print_info ""
print_info "Menguji koneksi database..."
if sudo -u postgres psql -d $DB_NAME -c "\q" 2>/dev/null; then
    print_success "Koneksi database berhasil!"
else
    print_error "Gagal terkoneksi ke database"
    exit 1
fi

echo ""
print_success "========================================="
print_success "  SETUP DATABASE SELESAI!"
print_success "========================================="
echo ""
print_info "Informasi Database:"
print_info "  Host: $(hostname -I | awk '{print $1}')"
print_info "  Port: 5432"
print_info "  Database: $DB_NAME"
print_info "  User: $DB_USER"
print_info "  Password: ***"
echo ""
print_info "Catatan penting:"
print_info "  1. Pastikan firewall VPS mengizinkan koneksi ke port 5432"
print_info "  2. Jika menggunakan cloud provider, pastikan security group sudah dikonfigurasi"
print_info "  3. Untuk keamanan, pertimbangkan untuk menggunakan SSL/TLS"
print_info "  4. Batasi akses dengan IP whitelist jika memungkinkan"
echo ""
print_info "Untuk test koneksi dari lokal:"
print_info "  psql -h $(hostname -I | awk '{print $1}') -U $DB_USER -d $DB_NAME"
echo ""

