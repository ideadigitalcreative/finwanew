# Analisis & Rencana Optimasi AI Processor

## 📊 Analisis Sistem Saat Ini

### Arsitektur Current
```
WhatsApp → Laravel (ProcessIncomingMessage Job) → AI Processor Service (FastAPI) → LLM (Ollama/Groq/OpenAI)
```

### Flow Pemrosesan Pesan Text
1. **WhatsApp Gateway** menerima pesan → Laravel
2. **Laravel** (ProcessIncomingMessage.php):
   - Klasifikasi intent (query vs transaction) menggunakan `classifyIntent()`
   - Jika transaction → `extractTransaction()` → AI Processor
3. **AI Processor** (main.py):
   - Endpoint `/extract-transaction`
   - Mengirim **System Prompt yang SANGAT PANJANG** (~400 baris)
   - Mengirim **User Prompt** dengan message text
   - Menunggu response dari LLM
   - Post-processing (fix amount, merge retail receipts, dll)
4. **Response** kembali ke Laravel → Simpan transaksi → Reply ke WhatsApp

### ⚠️ Masalah Saat Ini

#### 1. **Token Usage Sangat Tinggi**
- **System Prompt**: ~2000 tokens (sangat panjang dengan banyak contoh)
- **User Message**: ~100-500 tokens
- **Response**: ~200-300 tokens
- **Total per request**: ~2500-3000 tokens
- **Biaya**: Sangat mahal jika menggunakan API berbayar

#### 2. **Latency Tinggi**
- Waktu proses: 5-15 detik untuk text sederhana
- Timeout risk untuk message kompleks
- User experience buruk (menunggu lama)

#### 3. **Redundansi**
- System prompt dikirim berulang untuk setiap request
- Banyak contoh yang tidak relevan untuk message sederhana
- Post-processing dilakukan di Python (seharusnya bisa di-handle sebelumnya)

#### 4. **Tidak Ada Pre-Processing**
- Semua message langsung ke LLM
- Tidak ada rule-based extraction untuk pattern sederhana
- Tidak ada caching untuk pattern yang sering muncul

## 🎯 Rencana Optimasi (Sesuai Request Anda)

### Pendekatan: **Rule-Engine First, LLM as Fallback**

```
Input Message
    ↓
[1] Rule-Based Classifier/Extractor (Regex/Pattern Matching)
    ↓
    ├─ Success? → Return hasil (SKIP LLM) ✅
    └─ Failed? → Lanjut ke LLM ⚠️
        ↓
    [2] LLM dengan Prompt Minimal + JSON Mode
        ↓
    [3] Return hasil
```

### Komponen Optimasi

#### A. **Rule-Based Extractor (Pre-Processing)**
Ekstraksi data untuk pattern sederhana TANPA LLM:

**Pattern yang bisa di-handle:**
```
✅ "beli bensin 50rb"           → expense, 50000, transport
✅ "makan siang 25k"            → expense, 25000, makanan
✅ "gaji 5 juta"                → income, 5000000, gaji
✅ "bayar listrik 100 ribu"    → expense, 100000, utilitas
✅ "belanja alfamart 75rb"     → expense, 75000, belanja
```

**Regex Patterns:**
```python
# Pattern: [action] [item?] [amount] [unit]
# Contoh: "beli bensin 50 rb"
pattern = r'(beli|bayar|belanja|makan|gaji|bonus|terima)\s+(\w+)?\s*(\d+)\s*(rb|ribu|k|juta)'
```

**Kategori Mapping:**
```python
CATEGORY_KEYWORDS = {
    'pengeluaran_makanan': ['makan', 'minum', 'kopi', 'snack', 'sayur', 'buah'],
    'pengeluaran_transport': ['bensin', 'parkir', 'tol', 'ojek', 'grab', 'gojek'],
    'pengeluaran_belanja': ['belanja', 'alfamart', 'indomaret', 'supermarket'],
    'pengeluaran_utilitas': ['listrik', 'air', 'wifi', 'pulsa', 'token'],
    'pendapatan_gaji': ['gaji', 'salary'],
    'pendapatan_bonus': ['bonus', 'thr'],
}
```

#### B. **Optimized LLM Prompt**
Jika rule-based gagal, gunakan LLM dengan prompt MINIMAL:

**System Prompt (Disimpan di Server):**
```
Ekstrak transaksi dari teks Indonesia.
Output JSON: {"kategori":"","nominal":0,"tanggal":"YYYY-MM-DD","vendor":""}
Kategori: pendapatan_gaji, pengeluaran_makanan, pengeluaran_transport, dll.
Nominal: angka penuh (rb=×1000, juta=×1000000).
```

**User Prompt (Single-Turn):**
```
Ekstrak kategori, nominal, tanggal, dan vendor dari teks berikut. 
Balas dalam JSON sesuai skema yang telah ditentukan.
Teks: {{chat_message}}
```

**Token Reduction:**
- System Prompt: 2000 → **100 tokens** (95% reduction!)
- User Prompt: 100 → **50 tokens**
- Total: 2500 → **150 tokens** (94% reduction!)

#### C. **JSON Mode / Structured Output**
Gunakan JSON mode untuk memastikan output terstruktur:

```python
# OpenAI/Groq JSON Mode
response = client.chat.completions.create(
    model="gpt-4o-mini",
    response_format={"type": "json_object"},
    messages=[...]
)

# Ollama JSON Format
response = ollama.chat(
    model="qwen2.5:3b",
    format="json",
    messages=[...]
)
```

#### D. **Server-Side Optimizations**

1. **System Prompt Caching** (Jangan kirim berulang)
   - Simpan di server/config
   - Hanya kirim sekali per session

2. **Single-Turn Request** (Hilangkan chat history)
   - Tidak perlu context sebelumnya
   - Setiap request independent

3. **Batch Processing** (Untuk multiple transactions)
   - Gabungkan multiple items dalam 1 request
   - Reduce API calls

## 📋 Implementation Plan

### Phase 1: Rule-Based Extractor (Priority HIGH)
**File**: `services/ai-processor/rule_extractor.py`

```python
class RuleBasedExtractor:
    def extract(self, text: str) -> Optional[Dict]:
        """
        Ekstraksi menggunakan regex untuk pattern sederhana.
        Return None jika tidak bisa extract (fallback ke LLM).
        """
        # Pattern matching
        # Amount extraction
        # Category mapping
        # Return structured data
```

**Estimasi**: 70-80% message bisa di-handle tanpa LLM!

### Phase 2: Optimized LLM Prompt
**File**: `services/ai-processor/main.py`

```python
# Simplified system prompt
SYSTEM_PROMPT_MINIMAL = """
Ekstrak transaksi dari teks Indonesia.
Output JSON: {"kategori":"","nominal":0,"tanggal":"YYYY-MM-DD","vendor":""}
Kategori: pendapatan_gaji, pengeluaran_makanan, pengeluaran_transport, pengeluaran_belanja, pengeluaran_utilitas, pengeluaran_lainnya.
Nominal: angka penuh (rb=×1000, juta=×1000000).
"""

# Simplified user prompt
user_prompt = f"Ekstrak kategori, nominal, tanggal, dan vendor dari teks berikut. Balas dalam JSON sesuai skema yang telah ditentukan. Teks: {message_text}"
```

### Phase 3: JSON Mode Implementation
**File**: `services/ai-processor/main.py`

```python
# Add JSON mode for all providers
if USE_GROQ:
    response = await client.chat.completions.create(
        model=GROQ_MODEL,
        response_format={"type": "json_object"},
        messages=[...]
    )
```

### Phase 4: Integration & Testing
1. Update `extract_transaction()` endpoint
2. Add rule-based check first
3. Fallback to LLM if needed
4. Test dengan berbagai message types
5. Monitor token usage & latency

## 📊 Expected Results

### Before Optimization
- **Token per request**: ~2500 tokens
- **Latency**: 5-15 seconds
- **Cost**: $0.01-0.03 per request (Groq/OpenAI)
- **LLM calls**: 100% of messages

### After Optimization
- **Token per request**: ~150 tokens (94% reduction)
- **Latency**: 0.5-2 seconds (80% faster)
- **Cost**: $0.001-0.003 per request (90% cheaper)
- **LLM calls**: 20-30% of messages only

### ROI
- **Token savings**: 94%
- **Cost savings**: 90%
- **Speed improvement**: 80%
- **User experience**: Jauh lebih baik (response cepat)

## 🚀 Next Steps

1. **Review & Approval**: Apakah pendekatan ini sesuai?
2. **Implementation**: Mulai dari Rule-Based Extractor
3. **Testing**: Test dengan real messages
4. **Deployment**: Deploy ke production
5. **Monitoring**: Track metrics (token usage, latency, accuracy)

## 📝 Notes

- Rule-based extractor harus di-maintain untuk pattern baru
- LLM tetap digunakan untuk message kompleks (struk, multiple items)
- Bisa tambahkan caching untuk pattern yang sering muncul
- Consider menggunakan smaller model (qwen2.5:1.5b) untuk fallback

---

**Prepared by**: AI Assistant
**Date**: 2025-12-04
**Status**: Awaiting Review & Implementation
