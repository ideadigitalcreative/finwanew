# Instruksi Setup RSA Key untuk SSH

## Langkah 1: Buat File Key

Buat file `scripts/vps-key.pem` dengan isi berikut:

```
-----BEGIN RSA PRIVATE KEY-----
MIIEpAIBAAKCAQEAskn4RwdbvKT4liHHh2TGuEpSFrR/hbClLo4nIgclPdAoJ6nP
idWIPKscGpykgFDxZ3XP2MAt8s9QEo+oK15j8RCzuGlfy5T5Yja//GEceptOmu8G
VSLmO0W0BlttaDLYXdeZlggXHJVgJVNrtUcamCzztoXChM/lhZYEDucWfuV4XaFW
69rlKb5yh7js2w4TpH03xrtPZnhVE0qB+ludYJ6YiUKpVMWJRPjivechftKTxtQD
ZS+mnoJfHzmr+jlo8yK0QK51xwx6GRk/RdFq9DUpbO8S/ohy46OaNX7FSpldoKSa
ss5ddNBCza19HAbORijxaVAjpY5CT6QeAcVLmQIDAQABAoIBAAqDWx0RHYM3tSLh
NgtRUXh/hWyqUxS2kF746ezTQApvklaYaFEH4uM08dDN6NNaMon3w/xThMIbIG0f
xHpeNAV8hyR6LjhqffQhJ1wB+/Rs4Qs4ygZOKwZWu2Felcp9qQ2Lj5ZtvrMkScmZ
rDHB0hskF3DtXUWIns+sQ2v/i2+LDjelwCR/64nVN22n1HXN0MvZjNa4qwn7g938
vYeivihPjZXbyMDTzdTMgUDXCsE1t8Ff4ZVkLKcL2zd1krbbrJ/yvGGu045/6z05
AYlQSOAn6nWyZg8v+gD1YSPu00cwtFXX4mJh2sIEAg2gF2A8wFqK3+pJfpfZUMHz
6t9MzYECgYEAx7ABVGcPAHt3b/n73/pq/0D/NoxIkj2vTbG8Jfk+dGH8dzCVzVrq
7urmJDn+Jpny6KxwmS89ffqjmtc78CFHVB28D4cJ6ltBZp1gcQXAqyV7xDsqg6nW
ZRuslT1Th2eeGi8VYOK2p5F/g0v3+9p2BhtS//Y7GFTAFWIG78BY1qECgYEA5JEk
aZPt1z/l4QJxfcgTSlVb+dt0LdSV5f5yqxMluCT5g1O/qkkccGEjmY+3DxTvgMRc
hz2c906MHCIMGORH3lb7xR+2BGTnz2h+sUfLcgp9eExfySf6ep/UKyNttCGVT+1r
1iGOk3uXwdD+VxHtfAAX29fg4o2GKURZU0WE6fkCgYEAuKzb7fUJ+MY25YZqHPB5
d+vim84NZ8JImDAh83SZAAWG+awjPrIwyBjSEvrXQ1fpQKoJ0IHR+uqL2C3qLuB4
GSEOxcV7tBQFXiN6B8zsLNwTpJ2bafzuXL/FUphO4dFAdLLKsLm7dymmpgTiKTgX
IvquPi645H2sz5nDFIPtJUECgYEAo+ycasPXPhrrqTZxYr5NZ3BUqJuFdSET6IFW
h+8RjEGoWVGFEoGgzdA9EfMKXNys8HLj0XKU0qEYx0x71JZUHNfRdYzKo9gikJPm
2QoelMmFNvO/dqsfbzaVmeKs2RWE2m/yeP5UHN309uIGpzeKVPZUJi1rcdACOjJ0
xc4EBzECgYB404qhbykdyAiBaNXvXGySh50OnKFNZbyKlMpn2RPJqO6apWCIYATp
t/Mmq8up2DU7sCpiVH9+Ma6A7rgdLQK6RV3oXQ4btulHSQks0hQSLg3syafZA0LJ
LP2R5WZ67PpmDgIaWtrzGChTN6yI3BsUwGe8ny2fy3snY3xerlyUhg==
-----END RSA PRIVATE KEY-----
```

**Cara membuat file:**
1. Buat file baru di folder `scripts` dengan nama `vps-key.pem`
2. Copy-paste isi key di atas ke dalam file
3. Simpan file

## Langkah 2: Set Permission Key File

### Windows (PowerShell)

```powershell
# Set permission agar hanya user yang bisa baca
icacls scripts\vps-key.pem /inheritance:r
icacls scripts\vps-key.pem /grant:r "$env:USERNAME:R"
```

### Linux/Mac

```bash
chmod 600 scripts/vps-key.pem
```

## Langkah 3: Test Koneksi

### Menggunakan Script

```powershell
.\scripts\connect-vps-with-key.ps1
```

### Manual

```powershell
ssh -i scripts\vps-key.pem ubuntu@114.125.222.8
```

## Langkah 4: Upload & Setup Database

Setelah berhasil login, jalankan script setup:

```powershell
.\scripts\upload-and-setup.ps1
```

Atau manual:

```powershell
# Upload script
scp -i scripts\vps-key.pem scripts\setup-database-vps.sh ubuntu@114.125.222.8:~/

# Login dan jalankan
ssh -i scripts\vps-key.pem ubuntu@114.125.222.8
chmod +x setup-database-vps.sh
./setup-database-vps.sh
```

## Troubleshooting

### Error: "Permissions for 'vps-key.pem' are too open"

**Solusi Windows:**
```powershell
# Set permission
icacls scripts\vps-key.pem /inheritance:r
icacls scripts\vps-key.pem /grant:r "$env:USERNAME:R"
```

**Solusi Linux/Mac:**
```bash
chmod 600 scripts/vps-key.pem
```

### Error: "Connection timed out"

1. Pastikan VPS sudah online
2. Pastikan firewall VPS mengizinkan port 22
3. Cek koneksi internet Anda

### Error: "Permission denied (publickey)"

1. Pastikan key file permission sudah benar (600)
2. Pastikan key file format benar (tidak ada extra space/newline)
3. Pastikan key file path benar

## File-file Script

- `scripts/vps-key.pem` - RSA private key (buat manual)
- `scripts/connect-vps-with-key.ps1` - Script untuk connect dengan key
- `scripts/upload-and-setup.ps1` - Script untuk upload & setup database
- `scripts/setup-database-vps.sh` - Script setup database di VPS

