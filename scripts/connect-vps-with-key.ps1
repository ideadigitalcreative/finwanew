# Script untuk Koneksi ke VPS menggunakan RSA Key
# Penggunaan: .\scripts\connect-vps-with-key.ps1

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
Write-Info "  KONEKSI KE VPS DENGAN RSA KEY"
Write-Info "  Host: $VpsHost"
Write-Info "  User: $VpsUser"
Write-Info "========================================="
Write-Info ""

# Cek apakah SSH tersedia
if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) {
    Write-Error "ERROR: SSH client tidak ditemukan!"
    Write-Info "Install OpenSSH Client:"
    Write-Info "  Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0"
    exit 1
}

Write-Success "SSH client ditemukan"

# Cek apakah key file ada
if (-not (Test-Path $KeyPath)) {
    Write-Error "ERROR: Key file tidak ditemukan di: $KeyPath"
    exit 1
}

Write-Success "Key file ditemukan: $KeyPath"

# Set permission untuk key file (untuk Windows, set ke read-only untuk user)
try {
    $acl = Get-Acl $KeyPath
    $permission = $env:USERNAME, "Read", "Allow"
    $accessRule = New-Object System.Security.AccessControl.FileSystemAccessRule $permission
    $acl.SetAccessRule($accessRule)
    $acl | Set-Acl $KeyPath
    Write-Success "Permission key file sudah diatur"
} catch {
    Write-Warning "Tidak dapat mengatur permission key file: $_"
}

# Convert path ke absolute path
$KeyPath = Resolve-Path $KeyPath

Write-Info ""
Write-Info "Menghubungkan ke VPS dengan key authentication..."
Write-Info ""

# Test koneksi
Write-Info "Test koneksi SSH..."
$sshTest = ssh -i $KeyPath -o StrictHostKeyChecking=no -o ConnectTimeout=10 "${VpsUser}@${VpsHost}" "echo 'Connection successful'" 2>&1

if ($LASTEXITCODE -eq 0) {
    Write-Success "Koneksi SSH berhasil!"
    Write-Info ""
    Write-Info "Anda bisa langsung login dengan command:"
    Write-Info "  ssh -i $KeyPath $VpsUser@${VpsHost}"
    Write-Info ""
    
    # Tanya apakah ingin langsung connect
    $response = Read-Host "Apakah ingin langsung connect ke VPS sekarang? (y/n)"
    if ($response -eq "y" -or $response -eq "Y") {
        Write-Info ""
        Write-Info "Menghubungkan ke VPS..." -ForegroundColor Green
        Write-Info ""
        ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}"
    }
} else {
    Write-Error "Gagal terhubung ke VPS"
    Write-Info "Output: $sshTest"
    Write-Info ""
    Write-Info "Troubleshooting:"
    Write-Info "1. Pastikan key file permission sudah benar (chmod 400 di Linux)"
    Write-Info "2. Pastikan VPS sudah online dan dapat diakses"
    Write-Info "3. Coba login manual: ssh -i $KeyPath $VpsUser@${VpsHost}"
    exit 1
}

