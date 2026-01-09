# =====================================================================
# FinWa-AI v2 Integration Guide
# =====================================================================

## Overview

FinWa-AI v2 has been successfully integrated into your Laravel application.
This document explains how everything works together.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        WhatsApp Message                              │
└──────────────────────────────┬──────────────────────────────────────┘
                               │
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   ProcessIncomingMessage.php                         │
│  ┌─────────────────────────────────────────────────────────────┐    │
│  │ 1. Check if FinWa-AI is enabled                             │    │
│  │ 2. Call FinWaAIService->classifyIntent()                    │    │
│  │    - Fast, deterministic (~100ms)                           │    │
│  │    - Returns: intent + entities (nominal, kategori, etc.)   │    │
│  │ 3. If FinWa-AI fails → Fallback to AIProcessorService       │    │
│  │    - Slower, requires LLM (~5-30s)                          │    │
│  └─────────────────────────────────────────────────────────────┘    │
└─────────────────────────┬───────────────────────────────────────────┘
                          │
          ┌───────────────┼───────────────────┬───────────────────┐
          │               │                   │                   │
          ▼               ▼                   ▼                   ▼
   ┌──────────┐    ┌──────────────┐    ┌───────────┐    ┌────────────┐
   │   sapa   │    │ transaction  │    │   query   │    │ irrelevant │
   │   help   │    │              │    │           │    │            │
   └────┬─────┘    └──────┬───────┘    └─────┬─────┘    └──────┬─────┘
        │                 │                  │                 │
        ▼                 ▼                  ▼                 ▼
   Send Help       Use FinWa entities   FinancialQuery     No reply
   Message         for fast creation    Service
```

## Files Created/Modified

### New Files (FinWa-AI Engine)
Located in: `C:\Users\melis\Herd\Ai-Finwa\`

```
Ai-Finwa/
├── main.py                 # FastAPI entry point (port 8000)
├── config.py               # Configuration & mappings
├── demo.py                 # Interactive demo CLI
├── requirements.txt        # Python dependencies
├── ecosystem.config.cjs    # PM2 config
├── start-finwa.bat         # Start script for Windows
│
├── core/
│   └── processor.py        # Main NLU pipeline
│
├── services/
│   ├── intent_classifier.py   # Rule-based intent detection
│   ├── ner_extractor.py       # Entity extraction
│   ├── slot_filler.py         # Slot filling
│   ├── rule_engine.py         # Validation & correction
│   └── ocr_parser.py          # OCR processing
│
├── utils/
│   ├── normalizers.py         # Number (25rb→25000) & date normalization
│   └── mappings.py            # Category & merchant mappings
│
└── models/
    └── schemas.py             # Pydantic schemas
```

### Modified Files (Laravel Integration)
Located in: `C:\Users\melis\Herd\keuangan-ai\`

```
keuangan-ai/
├── app/
│   ├── Services/
│   │   └── FinWaAIService.php     # NEW: FinWa-AI client service
│   │
│   └── Jobs/
│       └── ProcessIncomingMessage.php   # MODIFIED: Uses FinWa-AI
│
├── config/
│   └── services.php               # MODIFIED: Added finwa_ai config
│
└── .env.example                   # MODIFIED: Added FinWa-AI env vars
```

## Setup Instructions

### Step 1: Add Environment Variables

Add to your `.env` file:

```env
# FinWa-AI v2 Configuration
FINWA_AI_URL=http://localhost:8000
FINWA_AI_TIMEOUT=30
FINWA_AI_ENABLED=true
```

### Step 2: Start FinWa-AI Server

Option A - Using batch script:
```cmd
cd C:\Users\melis\Herd\Ai-Finwa
start-finwa.bat
```

Option B - Using PM2:
```cmd
cd C:\Users\melis\Herd\Ai-Finwa
pm2 start ecosystem.config.cjs
```

Option C - Manual:
```cmd
cd C:\Users\melis\Herd\Ai-Finwa
.\venv\Scripts\activate
python main.py
```

### Step 3: Test the Integration

Test FinWa-AI directly:
```cmd
curl -X POST http://localhost:8000/process/text ^
  -H "Content-Type: application/json" ^
  -d "{\"message\": \"catat makan siang 27rb di KFC\"}"
```

Expected response:
```json
{
  "intent": "catat_pengeluaran",
  "entities": {
    "kategori": "makan",
    "nominal": 27000,
    "merchant": "KFC",
    "tanggal": "2025-12-05",
    "catatan": null,
    "items": []
  },
  "confidence": 0.95
}
```

## Performance Comparison

| Metric | FinWa-AI v2 | AIProcessorService (LLM) |
|--------|-------------|--------------------------|
| Response Time | ~50-100ms | ~5-30 seconds |
| CPU Usage | Low (rule-based) | High (model inference) |
| Memory | ~200MB | ~2-8GB (depending on model) |
| Accuracy | High for common patterns | Higher for edge cases |
| Deterministic | ✅ Yes | ❌ No |
| Requires GPU | ❌ No | ⚠️ Recommended |

## How It Works

### 1. Intent Classification
FinWa-AI classifies messages into intents:
- `catat_pengeluaran` - Record expense
- `catat_pemasukan` - Record income
- `cek_cashflow` - Check cashflow (mapped to "query")
- `lihat_transaksi` - View transactions (mapped to "query")
- `sapa` - Greeting (handled directly)
- `help` - Help request (handled directly)
- `unknown` - Unknown intent

### 2. Entity Extraction
Entities are extracted using rule-based NER:
- **nominal**: `25rb` → 25000, `1.5jt` → 1500000
- **kategori**: Inferred from keywords or merchant
- **merchant**: Normalized (e.g., "mcd" → "McDonald's")
- **tanggal**: `kemarin` → "2025-12-04"
- **catatan**: Additional notes

### 3. Fast Path
When FinWa-AI successfully extracts entities with a valid nominal:
- Transaction is created directly WITHOUT calling AIProcessorService
- This bypasses the slow LLM inference
- Response time: ~100ms instead of 5-30 seconds

### 4. Fallback
If FinWa-AI fails or is disabled:
- System falls back to AIProcessorService
- Uses LLM for classification and extraction
- Maintains full compatibility with existing flow

## Troubleshooting

### FinWa-AI not responding
```cmd
# Check if service is running
curl http://localhost:8000/health

# Check logs
type C:\Users\melis\Herd\Ai-Finwa\logs\finwa-error.log
```

### Disable FinWa-AI
Set in `.env`:
```env
FINWA_AI_ENABLED=false
```

### Clear Laravel config cache
```cmd
cd C:\Users\melis\Herd\keuangan-ai
php artisan config:clear
```

## API Reference

### POST /process/text
Process text message from WhatsApp.

Request:
```json
{
  "message": "catat makan siang 27rb di KFC"
}
```

Response:
```json
{
  "intent": "catat_pengeluaran",
  "entities": {
    "kategori": "makan",
    "nominal": 27000,
    "merchant": "KFC",
    "tanggal": "2025-12-05",
    "catatan": null,
    "items": []
  },
  "confidence": 0.95
}
```

### POST /process/ocr
Process OCR text from receipt.

Request:
```json
{
  "ocr_text": "Kopitiam\nNasi Goreng 22000\nTeh Manis 5000\nTOTAL 27000"
}
```

Response:
```json
{
  "intent": "catat_pengeluaran",
  "entities": {
    "kategori": "makan",
    "nominal": 27000,
    "merchant": "Kopitiam",
    "tanggal": "2025-12-05",
    "catatan": null,
    "items": [
      {"nama": "Nasi Goreng", "harga": 22000},
      {"nama": "Teh Manis", "harga": 5000}
    ]
  },
  "confidence": 0.98
}
```

### GET /health
Health check endpoint.

Response:
```json
{
  "status": "healthy",
  "version": "2.0.0",
  "timestamp": "2025-12-05T11:00:00"
}
```

---

For more details, see the README.md in the Ai-Finwa folder.
