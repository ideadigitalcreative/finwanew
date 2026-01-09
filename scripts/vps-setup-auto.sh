#!/bin/bash
# Auto-setup script untuk database di VPS

echo "=== SETUP DATABASE DI VPS ==="
echo ""

# Update system
echo "1. Update system packages..."
sudo apt-get update -qq

# Install PostgreSQL jika belum ada
if ! command -v psql &> /dev/null; then
    echo "2. Install PostgreSQL..."
    sudo apt-get install -y postgresql postgresql-contrib
else
    echo "2. PostgreSQL sudah terinstall"
fi

# Buat database
echo ""
echo "3. Setup database..."
sudo -u postgres psql <<EOF
-- Buat database
CREATE DATABASE dbku;

-- Buat user
CREATE USER userku WITH PASSWORD 'passwordku123';

-- Berikan hak akses
GRANT ALL PRIVILEGES ON DATABASE dbku TO userku;
ALTER DATABASE dbku OWNER TO userku;
\q
EOF

# Berikan hak akses schema
sudo -u postgres psql -d dbku <<EOF
GRANT ALL ON SCHEMA public TO userku;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO userku;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO userku;
\q
EOF

# Konfigurasi PostgreSQL untuk remote
echo ""
echo "4. Konfigurasi PostgreSQL untuk remote access..."

PG_VERSION=psql --version | grep -oP '\d+' | head -1
PG_CONF="/etc/postgresql/$PG_VERSION/main/postgresql.conf"
PG_HBA="/etc/postgresql/$PG_VERSION/main/pg_hba.conf"

if [ -f "$PG_CONF" ]; then
    sudo sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '*'/g" $PG_CONF
    sudo sed -i "s/listen_addresses = 'localhost'/listen_addresses = '*'/g" $PG_CONF
fi

if [ -f "$PG_HBA" ]; then
    if ! grep -q "host    all             all             0.0.0.0/0" $PG_HBA; then
        echo "host    all             all             0.0.0.0/0               md5" | sudo tee -a $PG_HBA > /dev/null
    fi
fi

# Restart PostgreSQL
echo ""
echo "5. Restart PostgreSQL..."
sudo systemctl restart postgresql

# Konfigurasi firewall
echo ""
echo "6. Konfigurasi firewall..."
if command -v ufw &> /dev/null; then
    sudo ufw allow 5432/tcp
    echo "Firewall UFW dikonfigurasi"
fi

echo ""
echo "=== SETUP SELESAI ==="
echo ""
echo "Database Information:"
echo "  Host: $(hostname -I | awk '{print $1}')"
echo "  Port: 5432"
echo "  Database: dbku"
echo "  User: userku"
echo "  Password: passwordku123"
echo ""