# 🦙 Panduan Migrasi ke Ollama

## 📋 Ringkasan

Aplikasi Keuangan AI sekarang mendukung **3 provider LLM**:
1. **Ollama** (Local AI - GRATIS, tidak perlu API key)
2. **OpenRouter** (Cloud AI - berbayar)
3. **OpenAI** (Cloud AI - berbayar)

---

## ✅ Keuntungan Menggunakan Ollama

| Aspek | Ollama | OpenRouter/OpenAI |
|-------|--------|-------------------|
| **Biaya** | ✅ GRATIS | ❌ Berbayar (per token) |
| **Privacy** | ✅ Data tetap lokal | ❌ Data dikirim ke cloud |
| **Kecepatan** | ✅ Cepat (lokal) | ⚠️ Tergantung internet |
| **Offline** | ✅ Bisa offline | ❌ Butuh internet |
| **Setup** | ⚠️ Perlu install Ollama | ✅ Tinggal pakai API key |

---

## 🚀 Cara Setup Ollama

### **1. Install Ollama**

**Windows:**
```powershell
# Download dari https://ollama.com/download
# Atau gunakan winget:
winget install Ollama.Ollama
```

**Linux/Mac:**
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

### **2. Pull Model yang Dibutuhkan**

Untuk **teks** (AI Processor):
```bash
# Pilih salah satu:
ollama pull qwen2.5:3b      # Recommended (cepat, akurat)
ollama pull llama3.1:8b     # Alternatif (lebih besar)
ollama pull gemma:7b        # Alternatif
```

Untuk **gambar** (OCR Worker - opsional):
```bash
# Pilih salah satu:
ollama pull qwen2.5-vl      # Recommended (vision model)
ollama pull llama3.2-vision # Alternatif
```

### **3. Jalankan Ollama Server**

```bash
ollama serve
```

Server akan berjalan di `http://localhost:11434`

### **4. Update File `.env`**

Tambahkan konfigurasi berikut ke file `.env`:

```env
# =====================================================================
# AI/LLM Configuration
# =====================================================================

# Pilih provider: ollama, openrouter, atau openai
AI_PROVIDER=ollama

# Ollama Configuration (untuk local AI)
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=qwen2.5:3b
OLLAMA_VISION_MODEL=qwen2.5-vl

# OpenRouter Configuration (alternatif - kosongkan jika pakai Ollama)
OPENROUTER_API_KEY=
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=openai/gpt-4o

# OpenAI Configuration (alternatif - kosongkan jika pakai Ollama)
OPENAI_API_KEY=
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_MODEL=gpt-4o-mini

# API Keys untuk services
AI_PROCESSOR_API_KEY=ai_processor_api_key_123
OCR_WORKER_API_KEY=ocr_worker_api_key_123
```

### **5. Restart Services**

```bash
# Restart AI Processor
pm2 restart ai-processor

# Restart OCR Worker
pm2 restart ocr-worker
```

---

## 📊 Tabel Kompatibilitas Model

### **Untuk Teks (AI Processor)**

| Model | Size | RAM | Kecepatan | Akurasi | Rekomendasi |
|-------|------|-----|-----------|---------|-------------|
| `qwen2.5:3b` | 3GB | 8GB | ⚡⚡⚡ Cepat | ✅ Bagus | ⭐ **Recommended** |
| `llama3.1:8b` | 8GB | 16GB | ⚡⚡ Sedang | ✅✅ Sangat Bagus | Jika RAM cukup |
| `gemma:7b` | 7GB | 16GB | ⚡⚡ Sedang | ✅ Bagus | Alternatif |

### **Untuk Gambar (OCR Worker)**

| Model | Size | RAM | Kecepatan | Akurasi | Rekomendasi |
|-------|------|-----|-----------|---------|-------------|
| `qwen2.5-vl` | 5GB | 16GB | ⚡⚡ Sedang | ✅✅ Sangat Bagus | ⭐ **Recommended** |
| `llama3.2-vision` | 8GB | 16GB | ⚡ Lambat | ✅ Bagus | Alternatif |

---

## 🔄 Cara Ganti Provider

### **Ganti ke Ollama:**
```env
AI_PROVIDER=ollama
```

### **Ganti ke OpenRouter:**
```env
AI_PROVIDER=openrouter
OPENROUTER_API_KEY=sk-or-v1-xxxxx
```

### **Ganti ke OpenAI:**
```env
AI_PROVIDER=openai
OPENAI_API_KEY=sk-xxxxx
```

---

## 🧪 Testing

### **Test AI Processor (Teks)**

```bash
curl -X POST http://localhost:3003/extract-transaction \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ai_processor_api_key_123" \
  -d '{
    "tenant_id": 1,
    "message_id": 1,
    "message_text": "beli bensin 50000",
    "message_type": "text"
  }'
```

### **Test OCR Worker (Gambar)**

```bash
curl -X POST http://localhost:3004/process \
  -H "Content-Type: application/json" \
  -H "X-API-Key: ocr_worker_api_key_123" \
  -d '{
    "tenant_id": 1,
    "message_id": 1,
    "ocr_job_id": 1,
    "file_url": "https://example.com/receipt.jpg",
    "file_type": "image"
  }'
```

---

## ❓ Troubleshooting

### **Error: Cannot connect to Ollama**

**Solusi:**
```bash
# Pastikan Ollama server berjalan
ollama serve

# Cek apakah Ollama berjalan
curl http://localhost:11434/api/tags
```

### **Error: Model not found**

**Solusi:**
```bash
# Pull model yang dibutuhkan
ollama pull qwen2.5:3b
```

### **Error: Out of memory**

**Solusi:**
1. Gunakan model yang lebih kecil (qwen2.5:3b)
2. Tutup aplikasi lain yang menggunakan RAM
3. Upgrade RAM komputer

### **Ollama lambat**

**Solusi:**
1. Gunakan GPU jika tersedia (Ollama otomatis detect)
2. Gunakan model yang lebih kecil
3. Kurangi `num_predict` di konfigurasi

---

## 📈 Perbandingan Performa

### **Ekstraksi Transaksi dari Teks**

| Provider | Waktu | Biaya | Akurasi |
|----------|-------|-------|---------|
| Ollama (qwen2.5:3b) | ~2-3s | GRATIS | 85-90% |
| OpenRouter (gpt-4o) | ~1-2s | $0.002/req | 95% |
| OpenAI (gpt-4o-mini) | ~1-2s | $0.001/req | 90% |

### **Ekstraksi dari Gambar Struk**

| Provider | Waktu | Biaya | Akurasi |
|----------|-------|-------|---------|
| Ollama (qwen2.5-vl) | ~5-8s | GRATIS | 80-85% |
| OpenAI (gpt-4o-mini) | ~2-3s | $0.003/req | 90% |

---

## 💡 Tips & Rekomendasi

1. **Untuk Development**: Gunakan Ollama (gratis, cepat)
2. **Untuk Production**: 
   - Traffic rendah: Ollama (hemat biaya)
   - Traffic tinggi: OpenRouter/OpenAI (lebih stabil)
3. **Hybrid**: Gunakan Ollama untuk teks sederhana, OpenAI untuk gambar kompleks

---

## 🔗 Resources

- [Ollama Official](https://ollama.com)
- [Ollama Models](https://ollama.com/library)
- [Qwen2.5 Documentation](https://github.com/QwenLM/Qwen2.5)
- [Llama 3.1 Documentation](https://llama.meta.com)

---

## 📝 Changelog

### v1.0.0 (2025-12-03)
- ✅ Tambah support Ollama untuk AI Processor
- ✅ Tambah support Ollama untuk OCR Worker
- ✅ Multi-provider support (Ollama/OpenRouter/OpenAI)
- ✅ Auto-fallback jika Ollama tidak tersedia
