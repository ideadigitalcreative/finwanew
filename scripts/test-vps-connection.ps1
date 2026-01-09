# Script untuk Test Koneksi ke VPS
# Penggunaan: .\scripts\test-vps-connection.ps1

param(
    [string]$VpsHost = "114.125.222.8",
    [string]$VpsUser = "ubuntu",
    [string]$VpsPort = "22"
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
Write-Info "  TEST KONEKSI KE VPS"
Write-Info "  Host: $VpsHost"
Write-Info "  User: $VpsUser"
Write-Info "========================================="
Write-Info ""

# Test ping
Write-Info "1. Test ping ke VPS..."
try {
    $pingResult = Test-Connection -ComputerName $VpsHost -Count 2 -Quiet
    if ($pingResult) {
        Write-Success "VPS dapat dijangkau (ping berhasil)"
    } else {
        Write-Warning "VPS tidak merespon ping (mungkin firewall memblokir ICMP)"
    }
} catch {
    Write-Warning "Gagal ping ke VPS: $_"
}

# Test port SSH
Write-Info ""
Write-Info "2. Test port SSH (22)..."
try {
    $tcpClient = New-Object System.Net.Sockets.TcpClient
    $connection = $tcpClient.BeginConnect($VpsHost, 22, $null, $null)
    $wait = $connection.AsyncWaitHandle.WaitOne(3000, $false)
    if ($wait) {
        $tcpClient.EndConnect($connection)
        Write-Success "Port SSH (22) terbuka dan dapat diakses"
        $tcpClient.Close()
    } else {
        Write-Error "Port SSH (22) tidak dapat diakses atau ditutup"
        $tcpClient.Close()
    }
} catch {
    Write-Error "Gagal test koneksi SSH: $_"
}

# Test port PostgreSQL
Write-Info ""
Write-Info "3. Test port PostgreSQL (5432)..."
try {
    $tcpClient = New-Object System.Net.Sockets.TcpClient
    $connection = $tcpClient.BeginConnect($VpsHost, 5432, $null, $null)
    $wait = $connection.AsyncWaitHandle.WaitOne(3000, $false)
    if ($wait) {
        $tcpClient.EndConnect($connection)
        Write-Success "Port PostgreSQL (5432) terbuka dan dapat diakses"
        $tcpClient.Close()
    } else {
        Write-Warning "Port PostgreSQL (5432) tidak dapat diakses (mungkin belum dikonfigurasi)"
        $tcpClient.Close()
    }
} catch {
    Write-Warning "Port PostgreSQL (5432) tidak dapat diakses: $_"
}

# Test SSH connection
Write-Info ""
Write-Info "4. Informasi Koneksi SSH..."
if (Get-Command ssh -ErrorAction SilentlyContinue) {
    Write-Info "SSH client ditemukan"
} else {
    Write-Warning "SSH client tidak ditemukan"
    Write-Info "Install OpenSSH Client atau gunakan PuTTY"
}

Write-Info ""
Write-Success "========================================="
Write-Info "  INFORMASI KONEKSI"
Write-Success "========================================="
Write-Info ""
Write-Info "Untuk login ke VPS, jalankan:"
Write-Info "  ssh $VpsUser@$VpsHost"
Write-Info ""
Write-Info "Password: Sembarang123"
Write-Info ""
Write-Info "Setelah login, jalankan script setup database:"
Write-Info "  ./setup-vps-database.sh"
Write-Info ""
