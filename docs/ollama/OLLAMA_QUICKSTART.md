# 🦙 Quick Start: Ollama untuk Keuangan AI

## 🚀 Setup Cepat (5 Menit)

### 1. Install Ollama
```bash
# Windows (PowerShell)
winget install Ollama.Ollama

# Linux/Mac
curl -fsSL https://ollama.com/install.sh | sh
```

### 2. Pull Model
```bash
ollama pull qwen2.5:3b
```

### 3. Jalankan Ollama
```bash
ollama serve
```

### 4. Update `.env`
```env
AI_PROVIDER=ollama
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=qwen2.5:3b
```

### 5. Restart Services
```bash
pm2 restart ai-processor
pm2 restart ocr-worker
```

## ✅ Done!

Aplikasi sekarang menggunakan Ollama (GRATIS, lokal, privacy-first).

---

## 📊 Format Struk yang Didukung

| Format Struk | Bisa Pakai Ollama? | Model yang Dibutuhkan |
|--------------|--------------------|-----------------------|
| **Teks** (pesan WhatsApp) | ✅ YA | `qwen2.5:3b` / `llama3.1` / `gemma` |
| **Gambar** (foto struk) | ✅ YA (butuh vision) | `qwen2.5-vl` / `llama3.2-vision` |
| **Gambar → Teks → Ollama** | ✅ YA | PaddleOCR + `qwen2.5:3b` |

---

## 🔄 Ganti Provider

### Ollama (Lokal, Gratis)
```env
AI_PROVIDER=ollama
```

### OpenRouter (Cloud, Berbayar)
```env
AI_PROVIDER=openrouter
OPENROUTER_API_KEY=sk-or-v1-xxxxx
```

### OpenAI (Cloud, Berbayar)
```env
AI_PROVIDER=openai
OPENAI_API_KEY=sk-xxxxx
```

---

## 📖 Dokumentasi Lengkap

Lihat [OLLAMA_MIGRATION.md](./OLLAMA_MIGRATION.md) untuk:
- Panduan instalasi detail
- Perbandingan model
- Troubleshooting
- Tips & best practices

---

## ❓ Troubleshooting Cepat

**Ollama tidak konek?**
```bash
ollama serve
```

**Model tidak ada?**
```bash
ollama pull qwen2.5:3b
```

**Cek status Ollama:**
```bash
curl http://localhost:11434/api/tags
```
