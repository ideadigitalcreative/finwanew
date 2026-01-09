# Script Migrasi Struktur Database Saja (Tanpa Data) ke VPS
# Menggunakan Laravel migrations untuk membuat struktur database di VPS
# Penggunaan: .\scripts\migrate-structure-only.ps1

param(
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsPort = "3306",
    [string]$VpsDbName = "dbku",
    [string]$VpsDbUser = "userku",
    [string]$VpsDbPassword = "passwordku123"
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
Write-Info "  MIGRASI STRUKTUR DATABASE KE VPS"
Write-Info "  VPS: $VpsHost"
Write-Info "========================================="
Write-Info ""

# Update .env untuk sementara
$envFile = ".\.env"
if (-not (Test-Path $envFile)) {
    Write-Error "File .env tidak ditemukan!"
    exit 1
}

Write-Info "Membaca konfigurasi dari .env..."

# Backup .env
$envBackup = "${envFile}.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
Copy-Item $envFile $envBackup -Force
Write-Success "Backup .env dibuat: $envBackup"

# Update .env dengan konfigurasi VPS
Write-Info "Mengupdate .env dengan konfigurasi VPS..."

# Baca file .env sebagai raw content
$envRawContent = Get-Content $envFile -Raw
$envLines = Get-Content $envFile

$newEnvContent = @()

foreach ($line in $envLines) {
    $trimmedLine = $line.Trim()
    
    # Skip empty lines and comments
    if ([string]::IsNullOrWhiteSpace($trimmedLine) -or $trimmedLine.StartsWith("#")) {
        $newEnvContent += $line
        continue
    }
    
    if ($trimmedLine -match "^DB_CONNECTION=(.+)$") {
        $newEnvContent += "DB_CONNECTION=mysql"
    } elseif ($trimmedLine -match "^DB_HOST=(.+)$") {
        $newEnvContent += "DB_HOST=$VpsHost"
    } elseif ($trimmedLine -match "^DB_PORT=(.+)$") {
        $newEnvContent += "DB_PORT=$VpsPort"
    } elseif ($trimmedLine -match "^DB_DATABASE=(.+)$") {
        if ($VpsDbName) {
            $newEnvContent += "DB_DATABASE=$VpsDbName"
        } else {
            $newEnvContent += $line
        }
    } elseif ($trimmedLine -match "^DB_USERNAME=(.+)$") {
        if ($VpsDbUser) {
            $newEnvContent += "DB_USERNAME=$VpsDbUser"
        } else {
            $newEnvContent += $line
        }
    } elseif ($trimmedLine -match "^DB_PASSWORD=(.+)$") {
        if ($VpsDbPassword) {
            $newEnvContent += "DB_PASSWORD=$VpsDbPassword"
        } else {
            $newEnvContent += $line
        }
    } else {
        $newEnvContent += $line
    }
}

# Tulis kembali file .env
try {
    [System.IO.File]::WriteAllLines((Resolve-Path $envFile).Path, $newEnvContent, [System.Text.UTF8Encoding]::new($false))
    Write-Success "File .env diupdate dengan konfigurasi VPS"
} catch {
    Write-Error "Gagal menulis file .env: $_"
    Write-Info "Mengembalikan .env ke konfigurasi sebelumnya..."
    Copy-Item $envBackup $envFile -Force
    exit 1
}

# Test koneksi database
Write-Info ""
Write-Info "Menguji koneksi database..."

# Clear Laravel config cache
php artisan config:clear 2>&1 | Out-Null

try {
    $testOutput = php artisan db:show 2>&1
    $testExitCode = $LASTEXITCODE
    
    if ($testExitCode -eq 0) {
        Write-Success "Koneksi database berhasil!"
        if ($testOutput) {
            Write-Info $testOutput
        }
    } else {
        Write-Error "ERROR: Gagal terkoneksi ke database VPS"
        if ($testOutput) {
            Write-Info "Output: $testOutput"
        }
        Write-Info ""
        Write-Info "Mengembalikan .env ke konfigurasi sebelumnya..."
        Copy-Item $envBackup $envFile -Force
        exit 1
    }
} catch {
    Write-Error "ERROR: Gagal menguji koneksi: $_"
    Write-Info "Mengembalikan .env ke konfigurasi sebelumnya..."
    Copy-Item $envBackup $envFile -Force
    exit 1
}

# Jalankan migrations
Write-Info ""
Write-Info "Menjalankan Laravel migrations..."
Write-Warning "Ini akan membuat semua tabel di database VPS"
Write-Info "Menunggu 5 detik sebelum melanjutkan..."
Start-Sleep -Seconds 5

Write-Info ""
Write-Info "Jalankan migrations..."
try {
    $migrateOutput = php artisan migrate --force 2>&1
    $migrateExitCode = $LASTEXITCODE
    
    if ($migrateOutput) {
        Write-Info $migrateOutput
    }
    
    if ($migrateExitCode -eq 0) {
        Write-Success "Migrations berhasil dijalankan!"
    } else {
        Write-Error "ERROR: Gagal menjalankan migrations (Exit Code: $migrateExitCode)"
        exit 1
    }
} catch {
    Write-Error "ERROR: Gagal menjalankan migrations: $_"
    exit 1
}

Write-Info ""
Write-Success "========================================="
Write-Success "  MIGRASI STRUKTUR SELESAI!"
Write-Success "========================================="
Write-Info ""
Write-Info "File .env sudah diupdate dengan konfigurasi VPS"
Write-Info "Backup .env tersedia di: $envBackup"
Write-Info ""
Write-Info "Konfigurasi Database VPS:"
Write-Info "  Host: $VpsHost"
Write-Info "  Port: $VpsPort"
Write-Info "  Database: $VpsDbName"
Write-Info "  User: $VpsDbUser"
Write-Info ""
Write-Info "Jika ingin mengembalikan konfigurasi lokal:"
Write-Info "  Copy-Item '$envBackup' '.env' -Force"
Write-Info ""

