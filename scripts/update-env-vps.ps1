# Script untuk Update File .env dengan Konfigurasi VPS
# Penggunaan: .\scripts\update-env-vps.ps1

param(
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsPort = "5432",
    [string]$VpsDbName = "",
    [string]$VpsDbUser = "",
    [string]$VpsDbPassword = "",
    [switch]$Restore = $false
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

$envFile = ".\.env"

# Restore dari backup
if ($Restore) {
    Write-Info "Mencari backup file .env..."
    $backupFiles = Get-ChildItem -Path "." -Filter ".env.backup.*" | Sort-Object LastWriteTime -Descending
    
    if ($backupFiles.Count -eq 0) {
        Write-Error "Tidak ada backup file .env ditemukan!"
        exit 1
    }
    
    $latestBackup = $backupFiles[0]
    Write-Info "Mengembalikan dari: $($latestBackup.Name)"
    
    Copy-Item $latestBackup.FullName $envFile -Force
    Write-Success "✓ File .env berhasil dikembalikan dari backup"
    exit 0
}

# Update .env dengan konfigurasi VPS
if (-not (Test-Path $envFile)) {
    Write-Error "File .env tidak ditemukan!"
    exit 1
}

# Validasi parameter
if (-not $VpsDbName) {
    Write-Error "Parameter VpsDbName harus diisi!"
    Write-Info "Contoh: .\scripts\update-env-vps.ps1 -VpsDbName 'keuangan_ai' -VpsDbUser 'postgres' -VpsDbPassword 'password'"
    exit 1
}

Write-Info ""
Write-Info "=== UPDATE KONFIGURASI .ENV UNTUK VPS ==="
Write-Info ""

# Backup .env
$envBackup = "${envFile}.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
Copy-Item $envFile $envBackup
Write-Success "✓ Backup .env dibuat: $envBackup"

# Baca dan update .env
Write-Info "Membaca file .env..."
$envContent = Get-Content $envFile
$newEnvContent = @()

foreach ($line in $envContent) {
    if ($line -match "^DB_CONNECTION=") {
        $newEnvContent += "DB_CONNECTION=pgsql"
        Write-Info "  → DB_CONNECTION=pgsql"
    } elseif ($line -match "^DB_HOST=") {
        $newEnvContent += "DB_HOST=$VpsHost"
        Write-Info "  → DB_HOST=$VpsHost"
    } elseif ($line -match "^DB_PORT=") {
        $newEnvContent += "DB_PORT=$VpsPort"
        Write-Info "  → DB_PORT=$VpsPort"
    } elseif ($line -match "^DB_DATABASE=") {
        $newEnvContent += "DB_DATABASE=$VpsDbName"
        Write-Info "  → DB_DATABASE=$VpsDbName"
    } elseif ($line -match "^DB_USERNAME=") {
        if ($VpsDbUser) {
            $newEnvContent += "DB_USERNAME=$VpsDbUser"
            Write-Info "  → DB_USERNAME=$VpsDbUser"
        } else {
            $newEnvContent += $line
        }
    } elseif ($line -match "^DB_PASSWORD=") {
        if ($VpsDbPassword) {
            $newEnvContent += "DB_PASSWORD=$VpsDbPassword"
            Write-Info "  → DB_PASSWORD=***"
        } else {
            $newEnvContent += $line
        }
    } else {
        $newEnvContent += $line
    }
}

# Tulis ke file
$newEnvContent | Set-Content $envFile -Encoding UTF8
Write-Success "✓ File .env berhasil diupdate"

Write-Info ""
Write-Success "========================================="
Write-Success "  UPDATE SELESAI!"
Write-Success "========================================="
Write-Info ""
Write-Info "Backup file: $envBackup"
Write-Info ""
Write-Info "Untuk mengembalikan konfigurasi sebelumnya:"
Write-Info "  .\scripts\update-env-vps.ps1 -Restore"
Write-Info ""
Write-Info "Test koneksi database:"
Write-Info "  php artisan db:show"
Write-Info ""

