# Quick Connect ke VPS dengan RSA Key
# Pastikan file scripts/vps-key.pem sudah dibuat terlebih dahulu

$VpsHost = "114.125.222.8"
$VpsUser = "ubuntu"
$KeyPath = ".\scripts\vps-key.pem"

Write-Host ""
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host "  QUICK CONNECT KE VPS" -ForegroundColor Cyan
Write-Host "=========================================" -ForegroundColor Cyan
Write-Host ""

# Cek apakah key file ada
if (-not (Test-Path $KeyPath)) {
    Write-Host "ERROR: File key tidak ditemukan!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Silakan buat file scripts/vps-key.pem terlebih dahulu" -ForegroundColor Yellow
    Write-Host "Lihat instruksi di: scripts\INSTRUKSI-KEY-SSH.md" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

Write-Host "Key file ditemukan: $KeyPath" -ForegroundColor Green
Write-Host ""

# Set permission (Windows)
try {
    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $acl = Get-Acl $KeyPath
    $acl.SetAccessRuleProtection($true, $false)
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentUser, "Read", "Allow")
    $acl.SetAccessRule($rule)
    Set-Acl $KeyPath $acl
    Write-Host "Permission key file diatur" -ForegroundColor Green
} catch {
    Write-Host "Tidak dapat mengatur permission (mungkin sudah benar)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Menghubungkan ke VPS..." -ForegroundColor Cyan
Write-Host "Host: $VpsHost" -ForegroundColor White
Write-Host "User: $VpsUser" -ForegroundColor White
Write-Host ""

# Connect
ssh -i $KeyPath -o StrictHostKeyChecking=no "${VpsUser}@${VpsHost}"

