# Script untuk Cek Status Database di VPS
# Penggunaan: .\scripts\check-vps-database.ps1

param(
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsUser = "ubuntu",
    [string]$KeyPath = ".\scripts\vps-key.pem"
)

function Write-ColorOutput($ForegroundColor) {
    $fc = $host.UI.RawUI.ForegroundColor
    $host.UI.RawUI.ForegroundColor = $ForegroundColor
    if ($args) {
        Write-Output $args
    }
    $host.UI.RawUI.ForegroundColor = $fc
}

function Write-Success { Write-ColorOutput Green $args }
function Write-Error { Write-ColorOutput Red $args }
function Write-Info { Write-ColorOutput Cyan $args }
function Write-Warning { Write-ColorOutput Yellow $args }

Write-Info ""
Write-Info "========================================="
Write-Info "  CEK STATUS DATABASE DI VPS"
Write-Info "  Host: $VpsHost"
Write-Info "========================================="
Write-Info ""

# Cek apakah key file ada
if (-not (Test-Path $KeyPath)) {
    Write-Error "ERROR: Key file tidak ditemukan di: $KeyPath"
    Write-Info ""
    Write-Info "Silakan buat file key terlebih dahulu sesuai instruksi di:"
    Write-Info "  scripts\INSTRUKSI-KEY-SSH.md"
    exit 1
}

# Test koneksi SSH
Write-Info "1. Test koneksi SSH ke VPS..."
try {
    $sshTest = ssh -i $KeyPath -o ConnectTimeout=10 -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "echo 'SSH OK'" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Koneksi SSH berhasil"
    } else {
        Write-Error "Gagal koneksi SSH"
        Write-Info "Output: $sshTest"
        exit 1
    }
} catch {
    Write-Error "Error koneksi SSH: $_"
    exit 1
}

# Cek PostgreSQL
Write-Info ""
Write-Info "2. Cek PostgreSQL di VPS..."
try {
    $pgCheck = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "command -v psql" 2>&1
    if ($pgCheck -match "psql") {
        Write-Success "PostgreSQL terinstall"
        $pgVersion = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "psql --version" 2>&1
        Write-Info "  Version: $pgVersion"
    } else {
        Write-Warning "PostgreSQL belum terinstall"
        Write-Info "Jalankan script setup: .\scripts\upload-and-setup.ps1"
    }
} catch {
    Write-Warning "Tidak dapat cek PostgreSQL: $_"
}

# Cek database
Write-Info ""
Write-Info "3. Cek database 'dbku'..."
try {
    $dbCheck = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "sudo -u postgres psql -lqt | cut -d \| -f 1 | grep -qw dbku && echo 'EXISTS' || echo 'NOT EXISTS'" 2>&1
    if ($dbCheck -match "EXISTS") {
        Write-Success "Database 'dbku' sudah ada"
    } else {
        Write-Warning "Database 'dbku' belum ada"
        Write-Info "Jalankan script setup: .\scripts\upload-and-setup.ps1"
    }
} catch {
    Write-Warning "Tidak dapat cek database: $_"
}

# Cek user
Write-Info ""
Write-Info "4. Cek user 'userku'..."
try {
    $userCheck = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "sudo -u postgres psql -t -c \"SELECT 1 FROM pg_user WHERE usename='userku';\" | grep -q 1 && echo 'EXISTS' || echo 'NOT EXISTS'" 2>&1
    if ($userCheck -match "EXISTS") {
        Write-Success "User 'userku' sudah ada"
    } else {
        Write-Warning "User 'userku' belum ada"
        Write-Info "Jalankan script setup: .\scripts\upload-and-setup.ps1"
    }
} catch {
    Write-Warning "Tidak dapat cek user: $_"
}

# Cek port 5432
Write-Info ""
Write-Info "5. Cek port PostgreSQL (5432)..."
try {
    $portCheck = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "sudo netstat -tlnp | grep :5432 || sudo ss -tlnp | grep :5432" 2>&1
    if ($portCheck -match "5432") {
        Write-Success "Port 5432 terbuka"
        Write-Info "  $portCheck"
    } else {
        Write-Warning "Port 5432 tidak terdeteksi atau tidak listening"
    }
} catch {
    Write-Warning "Tidak dapat cek port: $_"
}

# Cek firewall
Write-Info ""
Write-Info "6. Cek firewall..."
try {
    $fwCheck = ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "sudo ufw status | grep 5432 || echo 'NO UFW RULE'" 2>&1
    if ($fwCheck -match "5432") {
        Write-Success "Firewall sudah mengizinkan port 5432"
        Write-Info "  $fwCheck"
    } else {
        Write-Warning "Firewall belum mengizinkan port 5432"
        Write-Info "Jalankan: sudo ufw allow 5432/tcp"
    }
} catch {
    Write-Warning "Tidak dapat cek firewall: $_"
}

# Test koneksi dari lokal
Write-Info ""
Write-Info "7. Test koneksi database dari lokal..."
try {
    if (Get-Command psql -ErrorAction SilentlyContinue) {
        $localTest = psql -h $VpsHost -U userku -d dbku -c "SELECT version();" 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Success "Koneksi database dari lokal berhasil!"
        } else {
            Write-Error "Gagal koneksi database dari lokal"
            Write-Info "Output: $localTest"
        }
    } else {
        Write-Warning "psql client tidak ditemukan di lokal"
        Write-Info "Install PostgreSQL client untuk test koneksi"
    }
} catch {
    Write-Warning "Tidak dapat test koneksi: $_"
}

Write-Info ""
Write-Info "========================================="
Write-Info "  REKOMENDASI"
Write-Info "========================================="
Write-Info ""
Write-Info "Jika database belum di-setup, jalankan:"
Write-Info "  .\scripts\upload-and-setup.ps1"
Write-Info ""
Write-Info "Atau setup manual di VPS:"
Write-Info "  1. Login ke VPS: ssh -i scripts\vps-key.pem ubuntu@114.125.222.8"
Write-Info "  2. Jalankan: ./setup-database-vps.sh"
Write-Info ""

