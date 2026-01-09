# Script Migrasi Database ke VPS 114.125.222.8
# Penggunaan: .\scripts\migrate-to-vps.ps1

param(
    [string]$LocalDbHost = "127.0.0.1",
    [string]$LocalDbPort = "5432",
    [string]$LocalDbName = "",
    [string]$LocalDbUser = "",
    [string]$LocalDbPassword = "",
    
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsPort = "5432",
    [string]$VpsDbName = "",
    [string]$VpsDbUser = "",
    [string]$VpsDbPassword = "",
    
    [switch]$SkipDump = $false,
    [switch]$SkipImport = $false,
    [string]$BackupPath = ".\storage\backups"
)

# Warna output
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

# Cek apakah pg_dump dan psql tersedia
function Test-PostgreSQLTools {
    $pgDump = Get-Command pg_dump -ErrorAction SilentlyContinue
    $psql = Get-Command psql -ErrorAction SilentlyContinue
    
    if (-not $pgDump) {
        Write-Error "ERROR: pg_dump tidak ditemukan. Pastikan PostgreSQL client tools terinstall."
        Write-Info "Download dari: https://www.postgresql.org/download/windows/"
        return $false
    }
    
    if (-not $psql) {
        Write-Error "ERROR: psql tidak ditemukan. Pastikan PostgreSQL client tools terinstall."
        Write-Info "Download dari: https://www.postgresql.org/download/windows/"
        return $false
    }
    
    Write-Success "✓ PostgreSQL tools ditemukan"
    return $true
}

# Baca konfigurasi dari .env jika tersedia
function Read-EnvConfig {
    $envFile = ".\.env"
    if (Test-Path $envFile) {
        Write-Info "Membaca konfigurasi dari .env..."
        $envContent = Get-Content $envFile
        
        foreach ($line in $envContent) {
            if ($line -match "^DB_CONNECTION=(.+)$") {
                $script:DbConnection = $matches[1].Trim()
            }
            if ($line -match "^DB_HOST=(.+)$") {
                if (-not $script:LocalDbHost) { $script:LocalDbHost = $matches[1].Trim() }
            }
            if ($line -match "^DB_PORT=(.+)$") {
                if (-not $script:LocalDbPort) { $script:LocalDbPort = $matches[1].Trim() }
            }
            if ($line -match "^DB_DATABASE=(.+)$") {
                if (-not $script:LocalDbName) { $script:LocalDbName = $matches[1].Trim() }
            }
            if ($line -match "^DB_USERNAME=(.+)$") {
                if (-not $script:LocalDbUser) { $script:LocalDbUser = $matches[1].Trim() }
            }
            if ($line -match "^DB_PASSWORD=(.+)$") {
                if (-not $script:LocalDbPassword) { $script:LocalDbPassword = $matches[1].Trim() }
            }
        }
        
        Write-Success "✓ Konfigurasi database lokal dibaca dari .env"
        Write-Info "  Host: $LocalDbHost"
        Write-Info "  Port: $LocalDbPort"
        Write-Info "  Database: $LocalDbName"
        Write-Info "  User: $LocalDbUser"
    } else {
        Write-Warning "File .env tidak ditemukan. Menggunakan parameter yang diberikan."
    }
}

# Validasi parameter
function Test-Parameters {
    $errors = @()
    
    if (-not $LocalDbName) {
        $errors += "LocalDbName harus diisi"
    }
    if (-not $LocalDbUser) {
        $errors += "LocalDbUser harus diisi"
    }
    if (-not $VpsDbName) {
        $errors += "VpsDbName harus diisi"
    }
    if (-not $VpsDbUser) {
        $errors += "VpsDbUser harus diisi"
    }
    
    if ($errors.Count -gt 0) {
        Write-Error "ERROR: Parameter yang diperlukan tidak lengkap:"
        foreach ($error in $errors) {
            Write-Error "  - $error"
        }
        Write-Info ""
        Write-Info "Contoh penggunaan:"
        Write-Info "  .\scripts\migrate-to-vps.ps1 -LocalDbName 'keuangan_ai' -LocalDbUser 'postgres' -VpsDbName 'keuangan_ai' -VpsDbUser 'postgres' -VpsDbPassword 'password123'"
        return $false
    }
    
    return $true
}

# Buat backup directory
function New-BackupDirectory {
    if (-not (Test-Path $BackupPath)) {
        New-Item -ItemType Directory -Path $BackupPath -Force | Out-Null
        Write-Success "✓ Directory backup dibuat: $BackupPath"
    }
}

# Dump database lokal
function Export-LocalDatabase {
    if ($SkipDump) {
        Write-Warning "Melewati proses dump database lokal (--SkipDump)"
        return $true
    }
    
    Write-Info ""
    Write-Info "=== STEP 1: Dump Database Lokal ==="
    
    $timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $dumpFile = Join-Path $BackupPath "dump_${LocalDbName}_${timestamp}.sql"
    
    Write-Info "Mengekspor database ke: $dumpFile"
    
    # Set PGPASSWORD untuk pg_dump
    $env:PGPASSWORD = $LocalDbPassword
    
    $pgDumpArgs = @(
        "-h", $LocalDbHost
        "-p", $LocalDbPort
        "-U", $LocalDbUser
        "-d", $LocalDbName
        "-F", "c"  # Custom format (binary)
        "-b"  # Include blobs
        "-v"  # Verbose
        "-f", $dumpFile
    )
    
    try {
        & pg_dump @pgDumpArgs
        if ($LASTEXITCODE -eq 0) {
            Write-Success "✓ Database berhasil di-dump ke: $dumpFile"
            $script:DumpFile = $dumpFile
            return $true
        } else {
            Write-Error "ERROR: Gagal melakukan dump database. Exit code: $LASTEXITCODE"
            return $false
        }
    } catch {
        Write-Error "ERROR: Gagal menjalankan pg_dump: $_"
        return $false
    } finally {
        $env:PGPASSWORD = $null
    }
}

# Import database ke VPS
function Import-ToVps {
    if ($SkipImport) {
        Write-Warning "Melewati proses import ke VPS (--SkipImport)"
        return $true
    }
    
    Write-Info ""
    Write-Info "=== STEP 2: Import Database ke VPS ==="
    
    if (-not $script:DumpFile -or -not (Test-Path $script:DumpFile)) {
        Write-Error "ERROR: File dump tidak ditemukan. Jalankan dump terlebih dahulu."
        return $false
    }
    
    Write-Info "Mengimport database ke VPS: $VpsHost"
    Write-Info "  Database: $VpsDbName"
    Write-Info "  User: $VpsDbUser"
    
    # Set PGPASSWORD untuk pg_restore
    $env:PGPASSWORD = $VpsDbPassword
    
    $pgRestoreArgs = @(
        "-h", $VpsHost
        "-p", $VpsPort
        "-U", $VpsDbUser
        "-d", $VpsDbName
        "-v"  # Verbose
        "-c"  # Clean (drop objects before creating)
        "-a"  # Data only (jika hanya ingin data, bukan schema)
        # "-s"  # Schema only (jika hanya ingin schema, bukan data)
        $script:DumpFile
    )
    
    try {
        Write-Warning "PENTING: Pastikan database '$VpsDbName' sudah dibuat di VPS!"
        Write-Warning "PENTING: Pastikan user '$VpsDbUser' sudah dibuat dan memiliki akses ke database!"
        Write-Info ""
        Write-Info "Menunggu 5 detik sebelum melanjutkan... (Ctrl+C untuk membatalkan)"
        Start-Sleep -Seconds 5
        
        & pg_restore @pgRestoreArgs
        if ($LASTEXITCODE -eq 0) {
            Write-Success "✓ Database berhasil di-import ke VPS"
            return $true
        } else {
            Write-Error "ERROR: Gagal mengimport database. Exit code: $LASTEXITCODE"
            Write-Info "Pastikan:"
            Write-Info "  1. Database sudah dibuat di VPS"
            Write-Info "  2. User memiliki hak akses yang cukup"
            Write-Info "  3. Firewall VPS mengizinkan koneksi dari IP Anda"
            Write-Info "  4. PostgreSQL di VPS sudah dikonfigurasi untuk remote connection"
            return $false
        }
    } catch {
        Write-Error "ERROR: Gagal menjalankan pg_restore: $_"
        return $false
    } finally {
        $env:PGPASSWORD = $null
    }
}

# Jalankan migrasi hanya struktur (migration files)
function Run-MigrationsOnly {
    Write-Info ""
    Write-Info "=== STEP 3: Menjalankan Laravel Migrations ==="
    Write-Info "Jika Anda hanya ingin menjalankan migrations (tanpa data), gunakan:"
    Write-Info "  php artisan migrate --force"
    Write-Info ""
    Write-Info "Atau jika sudah di VPS:"
    Write-Info "  php artisan migrate --force --database=pgsql"
}

# Main execution
Write-Info ""
Write-Info "========================================="
Write-Info "  MIGRASI DATABASE KE VPS"
Write-Info "  VPS: $VpsHost"
Write-Info "========================================="
Write-Info ""

# Validasi tools
if (-not (Test-PostgreSQLTools)) {
    exit 1
}

# Baca konfigurasi
Read-EnvConfig

# Validasi parameter
if (-not (Test-Parameters)) {
    exit 1
}

# Buat backup directory
New-BackupDirectory

# Jalankan proses
$dumpSuccess = Export-LocalDatabase
if (-not $dumpSuccess) {
    Write-Error "Migrasi dibatalkan karena gagal dump database lokal"
    exit 1
}

$importSuccess = Import-ToVps
if (-not $importSuccess) {
    Write-Error "Migrasi dibatalkan karena gagal import ke VPS"
    exit 1
}

Run-MigrationsOnly

Write-Info ""
Write-Success "========================================="
Write-Success "  MIGRASI SELESAI!"
Write-Success "========================================="
Write-Info ""
Write-Info "Langkah selanjutnya:"
Write-Info "  1. Update file .env dengan konfigurasi VPS:"
Write-Info "     DB_CONNECTION=pgsql"
Write-Info "     DB_HOST=114.125.222.8"
Write-Info "     DB_PORT=5432"
Write-Info "     DB_DATABASE=$VpsDbName"
Write-Info "     DB_USERNAME=$VpsDbUser"
Write-Info "     DB_PASSWORD=***"
Write-Info ""
Write-Info "  2. Test koneksi database:"
Write-Info "     php artisan db:show"
Write-Info ""
Write-Info "  3. Jika diperlukan, jalankan migrations:"
Write-Info "     php artisan migrate --force"
Write-Info ""

