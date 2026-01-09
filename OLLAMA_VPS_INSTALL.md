# Panduan Install Ollama di VPS (Linux)

## Prasyarat
- VPS dengan minimal 4GB RAM (8GB+ direkomendasikan)
- Ubuntu 20.04+ atau Debian 11+
- Akses root atau sudo
- Koneksi internet yang stabil

## Langkah 1: Install Ollama

### Metode 1: Install Script (Recommended)
```bash
# Login ke VPS via SSH
ssh user@your-vps-ip

# Install Ollama dengan satu perintah
curl -fsSL https://ollama.com/install.sh | sh
```

### Metode 2: Manual Install
```bash
# Download binary
curl -L https://ollama.com/download/ollama-linux-amd64 -o /usr/local/bin/ollama

# Beri permission execute
chmod +x /usr/local/bin/ollama

# Buat user ollama
useradd -r -s /bin/false -m -d /usr/share/ollama ollama

# Buat systemd service
cat > /etc/systemd/system/ollama.service << 'EOF'
[Unit]
Description=Ollama Service
After=network-online.target

[Service]
ExecStart=/usr/local/bin/ollama serve
User=ollama
Group=ollama
Restart=always
RestartSec=3
Environment="PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

[Install]
WantedBy=default.target
EOF

# Reload systemd dan start service
systemctl daemon-reload
systemctl enable ollama
systemctl start ollama
```

## Langkah 2: Verifikasi Instalasi

```bash
# Cek status service
systemctl status ollama

# Cek apakah Ollama berjalan
curl http://localhost:11434/api/tags

# Atau
ollama list
```

## Langkah 3: Pull Model yang Dibutuhkan

```bash
# Pull model qwen2.5:3b (1.9GB - recommended untuk VPS dengan RAM terbatas)
ollama pull qwen2.5:3b

# Atau model yang lebih besar jika RAM cukup:
# ollama pull qwen2.5:7b-instruct  # 4.7GB
# ollama pull llama3.2:3b          # 2GB

# Verifikasi model sudah ter-download
ollama list
```

## Langkah 4: Test Model

```bash
# Test chat dengan model
ollama run qwen2.5:3b "Halo, siapa kamu?"

# Test via API
curl http://localhost:11434/api/generate -d '{
  "model": "qwen2.5:3b",
  "prompt": "Halo, siapa kamu?",
  "stream": false
}'
```

## Langkah 5: Konfigurasi untuk Production

### A. Expose Ollama ke Network (Opsional - Hati-hati!)

**PERINGATAN:** Hanya lakukan ini jika Anda tahu risikonya!

```bash
# Edit service file
sudo systemctl edit ollama

# Tambahkan:
[Service]
Environment="OLLAMA_HOST=0.0.0.0:11434"

# Restart service
sudo systemctl restart ollama
```

### B. Setup Firewall (Jika expose ke network)

```bash
# Hanya izinkan IP tertentu (ganti YOUR_IP)
sudo ufw allow from YOUR_IP to any port 11434

# Atau jika dalam satu VPS (recommended)
# Biarkan hanya localhost yang bisa akses (default)
```

### C. Setup Nginx Reverse Proxy (Recommended untuk Production)

```bash
# Install nginx jika belum
sudo apt install nginx

# Buat config
sudo nano /etc/nginx/sites-available/ollama

# Isi dengan:
server {
    listen 80;
    server_name ollama.yourdomain.com;

    location / {
        proxy_pass http://localhost:11434;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection 'upgrade';
        proxy_set_header Host $host;
        proxy_cache_bypass $http_upgrade;
        proxy_read_timeout 300s;
        proxy_connect_timeout 75s;
    }
}

# Enable site
sudo ln -s /etc/nginx/sites-available/ollama /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl restart nginx

# Setup SSL dengan Certbot (optional)
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d ollama.yourdomain.com
```

## Langkah 6: Konfigurasi Aplikasi Keuangan AI

### Update .env di VPS

```bash
# Edit .env
nano /path/to/keuangan-ai/.env

# Ubah:
AI_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=qwen2.5:3b
```

### Update ecosystem.config.cjs

Sudah dikonfigurasi di file lokal, tinggal deploy ke VPS.

### Restart Services

```bash
cd /path/to/keuangan-ai/services
pm2 restart ai-processor
pm2 restart ocr-worker
```

## Langkah 7: Monitoring & Maintenance

### Monitor Resource Usage

```bash
# Cek RAM usage
free -h

# Cek GPU usage (jika ada)
nvidia-smi

# Monitor Ollama logs
journalctl -u ollama -f

# Monitor PM2 logs
pm2 logs ai-processor
```

### Manage Models

```bash
# List models
ollama list

# Remove model yang tidak dipakai
ollama rm model-name

# Update model
ollama pull qwen2.5:3b
```

## Troubleshooting

### Ollama tidak bisa start

```bash
# Cek logs
journalctl -u ollama -n 50

# Cek port conflict
sudo lsof -i :11434

# Restart service
sudo systemctl restart ollama
```

### Out of Memory

```bash
# Gunakan model yang lebih kecil
ollama pull qwen2.5:1.5b  # Hanya 986MB

# Atau tambah swap
sudo fallocate -l 4G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

### Model terlalu lambat

```bash
# Gunakan model yang lebih kecil
# Atau upgrade VPS ke yang punya GPU
# Atau kembali ke Groq (restore dari .env.groq.backup)
```

## Rekomendasi VPS Specs

### Minimum (Budget)
- **RAM**: 4GB
- **CPU**: 2 cores
- **Storage**: 20GB SSD
- **Model**: qwen2.5:1.5b atau qwen2.5:3b
- **Harga**: ~$10-15/bulan

### Recommended
- **RAM**: 8GB
- **CPU**: 4 cores
- **Storage**: 40GB SSD
- **Model**: qwen2.5:7b-instruct
- **Harga**: ~$20-30/bulan

### Optimal (dengan GPU)
- **RAM**: 16GB
- **GPU**: NVIDIA T4 atau lebih
- **CPU**: 4+ cores
- **Storage**: 50GB SSD
- **Model**: qwen2.5:14b atau llama3.1:70b
- **Harga**: ~$50-100/bulan

## Provider VPS yang Direkomendasikan

1. **DigitalOcean** - Mudah setup, reliable
2. **Vultr** - Banyak lokasi, harga kompetitif
3. **Hetzner** - Murah untuk specs tinggi (EU)
4. **AWS EC2** - Scalable, banyak pilihan GPU
5. **Google Cloud** - Good GPU options

## Alternatif: Tetap Pakai Groq

Jika VPS specs tidak cukup atau biaya terlalu mahal:

```bash
# Restore konfigurasi Groq
cp .env.groq.backup .env

# Update ecosystem.config.cjs kembali ke Groq
# (atau gunakan environment variables dari .env)

# Restart services
pm2 restart all
```

**Keuntungan Groq:**
- Gratis (dengan rate limit)
- Sangat cepat
- Tidak perlu resource VPS
- Model besar (llama-3.1-70b tersedia)

**Keuntungan Ollama Local:**
- Privacy (data tidak keluar server)
- Tidak ada rate limit
- Tidak bergantung internet
- Full control

---

**Catatan:** Untuk production, pertimbangkan hybrid approach:
- Ollama untuk data sensitif
- Groq untuk volume tinggi atau model besar
- Fallback otomatis jika satu provider down
