# 🤖 FINWA-AI UPDATE: Support "Punya Uang di [Wallet]" Pattern

## 📋 **Requirement:**

User ingin AI bisa terima format:
```
"Saya punya uang di Dana 128k"
"Punya uang di Gopay 500rb"
"Ada uang di BCA 2jt"
```

Dan treat sebagai **income transaction** ke wallet yang disebutkan.

---

## 🔧 **Implementation:**

### **File to Edit:** `intent_classifier.py`

**Location:** `/root/finwa-ai/app/services/intent_classifier.py`

**Add new pattern to INTENT_KEYWORDS:**

```python
INTENT_KEYWORDS = {
    'catat_pemasukan': [
        # ... existing keywords ...
        'terima', 'dapat', 'dapet', 'masuk', 'gaji', 'bonus',
        
        # NEW: Add "punya uang" patterns
        'punya uang',
        'ada uang',
        'saya punya',
        'aku punya',
    ],
    # ... rest of intents ...
}
```

---

### **File to Edit:** `entity_extractor.py`

**Location:** `/root/finwa-ai/app/services/entity_extractor.py`

**Add new pattern to extract wallet from "punya uang di [wallet]":**

```python
def extract_entities(self, text: str, intent: str) -> dict:
    """Extract entities from text based on intent"""
    
    entities = {
        'nominal': None,
        'kategori': None,
        'merchant': None,
        'tanggal': None,
        'deskripsi': text,
        'account_name': None  # Wallet/account name
    }
    
    # ... existing code ...
    
    # Extract account/wallet name for income
    if intent == 'catat_pemasukan':
        # Pattern: "punya uang di [wallet]"
        punya_pattern = r'(?:punya|ada)\s+uang\s+(?:di|ke)\s+([a-zA-Z]+)'
        match = re.search(punya_pattern, text, re.IGNORECASE)
        if match:
            entities['account_name'] = match.group(1).capitalize()
            logger.info(f"Extracted wallet from 'punya uang' pattern: {entities['account_name']}")
    
    # ... rest of code ...
    
    return entities
```

---

### **File to Edit:** `transaction_parser.py`

**Location:** `/root/finwa-ai/app/services/transaction_parser.py`

**Ensure account_name is included in response:**

```python
def parse_transaction(self, text: str) -> dict:
    """Parse transaction from text"""
    
    # ... existing code ...
    
    result = {
        'success': True,
        'intent': intent,
        'confidence': confidence,
        'nominal': entities.get('nominal'),
        'kategori': entities.get('kategori'),
        'merchant': entities.get('merchant'),
        'tanggal': entities.get('tanggal'),
        'deskripsi': entities.get('deskripsi'),
        'account_name': entities.get('account_name'),  # Include wallet name
    }
    
    return result
```

---

## 🧪 **Testing:**

### **Test Cases:**

```python
# Test 1
input: "Saya punya uang di Dana 128k"
expected_output: {
    'intent': 'catat_pemasukan',
    'nominal': 128000,
    'account_name': 'Dana',
    'deskripsi': 'Saya punya uang di Dana 128k'
}

# Test 2
input: "Punya uang di Gopay 500rb"
expected_output: {
    'intent': 'catat_pemasukan',
    'nominal': 500000,
    'account_name': 'Gopay',
    'deskripsi': 'Punya uang di Gopay 500rb'
}

# Test 3
input: "Ada uang di BCA 2jt"
expected_output: {
    'intent': 'catat_pemasukan',
    'nominal': 2000000,
    'account_name': 'Bca',
    'deskripsi': 'Ada uang di BCA 2jt'
}
```

---

## 📝 **Deployment Steps:**

### **1. SSH to VPS:**
```bash
ssh finwa@VM-4-144-ubuntu
```

### **2. Navigate to FinWa-AI:**
```bash
cd /root/finwa-ai
```

### **3. Edit Files:**

**Edit intent_classifier.py:**
```bash
nano app/services/intent_classifier.py
```

Add keywords: `'punya uang', 'ada uang', 'saya punya', 'aku punya'`

**Edit entity_extractor.py:**
```bash
nano app/services/entity_extractor.py
```

Add pattern extraction for wallet name.

**Edit transaction_parser.py:**
```bash
nano app/services/transaction_parser.py
```

Ensure `account_name` is included in response.

### **4. Restart Service:**
```bash
pm2 restart finwa-ai
pm2 logs finwa-ai --lines 50
```

### **5. Test:**
```bash
curl -X POST http://localhost:5000/api/process \
  -H "Content-Type: application/json" \
  -d '{"text": "Saya punya uang di Dana 128k"}'
```

Expected response:
```json
{
  "success": true,
  "intent": "catat_pemasukan",
  "confidence": 0.95,
  "nominal": 128000,
  "account_name": "Dana",
  "deskripsi": "Saya punya uang di Dana 128k"
}
```

---

## ✅ **Verification:**

After deployment, test via WhatsApp:

```
User: "Saya punya uang di Dana 128k"

Expected Response:
✅ Berhasil Dicatat!

💰 Pemasukan
💵 Rp 128.000
💰 Pendapatan Lainnya • Saya punya uang di Dana 128k
👛 Sisa saldo Dana: Rp 128.000
```

---

## 🚨 **Important Notes:**

1. **Laravel already supports `account_name`** - No changes needed in Laravel
2. **Only Python AI service needs update** - Add pattern recognition
3. **Test thoroughly** - Ensure existing patterns still work
4. **Monitor logs** - Check for any errors after deployment

---

## 📊 **Impact:**

- ✅ Users can now say "punya uang di [wallet]"
- ✅ More natural language support
- ✅ Better UX for income transactions
- ✅ Wallet automatically detected and linked

---

**Status:** 📝 Ready for implementation in FinWa-AI Python service
**Priority:** Medium
**Estimated Time:** 15-30 minutes
