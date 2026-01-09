# Script untuk Upload Setup Script ke VPS dan Menjalankannya
# Penggunaan: .\scripts\upload-and-setup.ps1

param(
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsUser = "ubuntu",
    [string]$KeyPath = ".\scripts\vps-key.pem",
    [string]$SetupScript = ".\scripts\setup-database-vps.sh"
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
Write-Info "  UPLOAD & SETUP DATABASE DI VPS"
Write-Info "  Host: $VpsHost"
Write-Info "========================================="
Write-Info ""

# Cek apakah SCP tersedia
if (-not (Get-Command scp -ErrorAction SilentlyContinue)) {
    Write-Error "ERROR: SCP tidak ditemukan!"
    Write-Info "Install OpenSSH Client:"
    Write-Info "  Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0"
    exit 1
}

# Cek apakah key file ada
if (-not (Test-Path $KeyPath)) {
    Write-Error "ERROR: Key file tidak ditemukan di: $KeyPath"
    exit 1
}

# Cek apakah setup script ada
if (-not (Test-Path $SetupScript)) {
    Write-Error "ERROR: Setup script tidak ditemukan di: $SetupScript"
    exit 1
}

Write-Success "File ditemukan"

# Convert path ke absolute path
$KeyPath = Resolve-Path $KeyPath
$SetupScript = Resolve-Path $SetupScript

# Upload setup script
Write-Info ""
Write-Info "Uploading setup script ke VPS..."
try {
    scp -i $KeyPath -o StrictHostKeyChecking=no $SetupScript "${VpsUser}@${VpsHost}:~/setup-database-vps.sh"
    if ($LASTEXITCODE -eq 0) {
        Write-Success "Setup script berhasil diupload"
    } else {
        Write-Error "Gagal upload setup script"
        exit 1
    }
} catch {
    Write-Error "Error upload: $_"
    exit 1
}

# Jalankan setup script di VPS
Write-Info ""
Write-Info "Menjalankan setup script di VPS..."
Write-Warning "Ini akan memakan waktu beberapa menit..."
Write-Info ""

try {
    ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}" "chmod +x setup-database-vps.sh && ./setup-database-vps.sh"
    
    if ($LASTEXITCODE -eq 0) {
        Write-Success ""
        Write-Success "========================================="
        Write-Success "  SETUP SELESAI!"
        Write-Success "========================================="
        Write-Info ""
        Write-Info "Database sudah di-setup di VPS"
        Write-Info ""
        Write-Info "Langkah selanjutnya:"
        Write-Info "  1. Update file .env dengan konfigurasi VPS"
        Write-Info "  2. Jalankan: .\scripts\migrate-structure-only.ps1"
        Write-Info ""
    } else {
        Write-Error "Setup script gagal dijalankan"
        exit 1
    }
} catch {
    Write-Error "Error menjalankan setup: $_"
    exit 1
}

