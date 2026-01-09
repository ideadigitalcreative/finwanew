#!/bin/bash
# Script untuk Setup Database di VPS
# Jalankan setelah login ke VPS

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
DB_NAME="${DB_NAME:-dbku}"
DB_USER="${DB_USER:-userku}"
DB_PASSWORD="${DB_PASSWORD:-passwordku123}"

echo ""
print_info "========================================="
print_info "  SETUP DATABASE DI VPS"
print_info "========================================="
echo ""

# Update system
print_info "1. Update system packages..."
sudo apt-get update -qq
print_success "System updated"

# Install PostgreSQL jika belum ada
print_info ""
print_info "2. Cek PostgreSQL installation..."
if ! command -v psql &> /dev/null; then
    print_info "   Install PostgreSQL..."
    sudo apt-get install -y postgresql postgresql-contrib
    print_success "PostgreSQL installed"
else
    print_success "PostgreSQL sudah terinstall"
    psql --version
fi

# Buat database
print_info ""
print_info "3. Setup database..."
sudo -u postgres psql <<EOF
-- Buat database jika belum ada
SELECT 'CREATE DATABASE $DB_NAME'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '$DB_NAME')\gexec

-- Buat user jika belum ada
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_user WHERE usename = '$DB_USER') THEN
    CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD';
  ELSE
    ALTER USER $DB_USER WITH PASSWORD '$DB_PASSWORD';
  END IF;
END
\$\$;

-- Berikan hak akses
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
ALTER DATABASE $DB_NAME OWNER TO $DB_USER;
\q
EOF

if [ $? -eq 0 ]; then
    print_success "Database dan user berhasil dibuat"
else
    print_error "Gagal membuat database atau user"
    exit 1
fi

# Berikan hak akses ke schema public
print_info ""
print_info "4. Mengatur hak akses schema public..."
sudo -u postgres psql -d $DB_NAME <<EOF
GRANT ALL ON SCHEMA public TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $DB_USER;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON FUNCTIONS TO $DB_USER;
\q
EOF

print_success "Hak akses schema public berhasil diatur"

# Konfigurasi PostgreSQL untuk remote connection
print_info ""
print_info "5. Konfigurasi PostgreSQL untuk remote access..."

# Cari versi PostgreSQL
PG_VERSION=$(sudo -u postgres psql -t -c "SHOW server_version_num;" 2>/dev/null | grep -oP '\d+' | head -c 2)
if [ -z "$PG_VERSION" ]; then
    PG_VERSION=$(psql --version | grep -oP '\d+' | head -1)
fi

# Cari file konfigurasi
PG_CONF=""
PG_HBA=""

# Cek beberapa lokasi umum
for path in "/etc/postgresql/$PG_VERSION/main/postgresql.conf" \
            "/etc/postgresql/*/main/postgresql.conf" \
            "/var/lib/pgsql/data/postgresql.conf"; do
    if [ -f $path ] 2>/dev/null; then
        PG_CONF=$(ls $path 2>/dev/null | head -1)
        PG_HBA=$(echo $PG_CONF | sed 's/postgresql.conf/pg_hba.conf/')
        break
    fi
done

# Jika tidak ditemukan, cari dengan find
if [ -z "$PG_CONF" ]; then
    PG_CONF=$(sudo find /etc -name "postgresql.conf" 2>/dev/null | head -1)
    PG_HBA=$(sudo find /etc -name "pg_hba.conf" 2>/dev/null | head -1)
fi

if [ -n "$PG_CONF" ] && [ -f "$PG_CONF" ]; then
    print_info "   File konfigurasi: $PG_CONF"
    
    # Update postgresql.conf
    if ! grep -q "^listen_addresses = '*'" "$PG_CONF"; then
        print_info "   Update listen_addresses..."
        sudo sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/g" "$PG_CONF"
        sudo sed -i "s/listen_addresses = 'localhost'/listen_addresses = '*'/g" "$PG_CONF"
        if ! grep -q "^listen_addresses" "$PG_CONF"; then
            echo "listen_addresses = '*'" | sudo tee -a "$PG_CONF" > /dev/null
        fi
        print_success "postgresql.conf diupdate"
    else
        print_success "postgresql.conf sudah dikonfigurasi"
    fi
else
    print_warning "File postgresql.conf tidak ditemukan"
    print_info "Silakan konfigurasi manual: listen_addresses = '*'"
fi

if [ -n "$PG_HBA" ] && [ -f "$PG_HBA" ]; then
    print_info "   File pg_hba.conf: $PG_HBA"
    
    # Update pg_hba.conf
    if ! grep -q "host    all             all             0.0.0.0/0" "$PG_HBA"; then
        print_info "   Update pg_hba.conf..."
        echo "host    all             all             0.0.0.0/0               md5" | sudo tee -a "$PG_HBA" > /dev/null
        print_success "pg_hba.conf diupdate"
    else
        print_success "pg_hba.conf sudah dikonfigurasi"
    fi
else
    print_warning "File pg_hba.conf tidak ditemukan"
    print_info "Silakan tambahkan manual: host all all 0.0.0.0/0 md5"
fi

# Restart PostgreSQL
print_info ""
print_info "6. Restart PostgreSQL..."
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
print_info "7. Konfigurasi firewall..."
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
print_info "8. Menguji koneksi database..."
if sudo -u postgres psql -d $DB_NAME -c "\q" 2>/dev/null; then
    print_success "Koneksi database berhasil!"
else
    print_error "Gagal terkoneksi ke database"
    exit 1
fi

# Tampilkan informasi
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
print_info "  Password: $DB_PASSWORD"
echo ""
print_info "Test koneksi dari lokal:"
print_info "  psql -h $(hostname -I | awk '{print $1}') -U $DB_USER -d $DB_NAME"
echo ""
print_info "Atau dari aplikasi Laravel, update .env:"
print_info "  DB_CONNECTION=pgsql"
print_info "  DB_HOST=114.125.222.8"
print_info "  DB_PORT=5432"
print_info "  DB_DATABASE=$DB_NAME"
print_info "  DB_USERNAME=$DB_USER"
print_info "  DB_PASSWORD=$DB_PASSWORD"
echo ""

