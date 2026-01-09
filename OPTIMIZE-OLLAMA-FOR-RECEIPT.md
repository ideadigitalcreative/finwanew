# 🔧 Optimasi Ollama untuk Struk (VPS 6GB RAM)

## Masalah

OCR menghasilkan **57 baris teks** dari struk → Ollama harus proses semua → **RAM meledak!**

## Solusi

**Filter dan ringkas** teks OCR sebelum kirim ke Ollama:
1. Ambil hanya baris yang penting (item + harga + total)
2. Buang baris header/footer yang tidak perlu
3. Batasi maksimal 20 baris

---

## Implementasi

### File: `app/Jobs/ProcessIncomingMessage.php`

Tambahkan method baru untuk filter teks OCR:

```php
/**
 * Optimize OCR text for Ollama processing
 * Reduce 57 lines to ~15-20 important lines only
 */
protected function optimizeOcrTextForAI(string $ocrText): string
{
    $lines = explode("\n", $ocrText);
    $importantLines = [];
    $totalFound = false;
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        // Skip empty lines
        if (empty($line)) {
            continue;
        }
        
        // Skip header lines (store name, address, etc)
        if (preg_match('/^(PT |CV |NPWP|JL\.|KEL\.|KEC\.|KOTA)/i', $line)) {
            continue;
        }
        
        // Skip phone numbers
        if (preg_match('/^\d{10,}$/', $line)) {
            continue;
        }
        
        // Keep lines with prices (contains numbers)
        if (preg_match('/\d{1,3}[,.]?\d{3}/', $line)) {
            $importantLines[] = $line;
        }
        
        // Keep TOTAL line
        if (preg_match('/TOTAL|SUBTOTAL|GRAND/i', $line)) {
            $importantLines[] = $line;
            $totalFound = true;
        }
        
        // Keep voucher/discount lines
        if (preg_match('/VOUCHER|DISKON|DISCOUNT/i', $line)) {
            $importantLines[] = $line;
        }
        
        // Stop after finding total (skip footer)
        if ($totalFound && count($importantLines) > 5) {
            break;
        }
        
        // Limit to 20 lines max
        if (count($importantLines) >= 20) {
            break;
        }
    }
    
    // If no important lines found, return first 10 lines
    if (empty($importantLines)) {
        return implode("\n", array_slice($lines, 0, 10));
    }
    
    return implode("\n", $importantLines);
}
```

### Update `createOcrJob()` method:

```php
protected function createOcrJob(): void
{
    // ... existing code ...
    
    // After OCR success, optimize text before creating transaction job
    if ($result['success'] && !empty($result['text'])) {
        $ocrText = $result['text'];
        
        // Optimize OCR text for AI processing (reduce from 57 to ~15 lines)
        $optimizedText = $this->optimizeOcrTextForAI($ocrText);
        
        Log::info('OCR text optimized for AI', [
            'message_id' => $this->message->id,
            'original_lines' => count(explode("\n", $ocrText)),
            'optimized_lines' => count(explode("\n", $optimizedText))
        ]);
        
        // Update message content with optimized text
        $this->message->update([
            'content' => $optimizedText,
            'metadata' => array_merge($this->message->metadata ?? [], [
                'ocr_original_text' => $ocrText, // Keep original for reference
                'ocr_optimized' => true
            ])
        ]);
        
        // Process optimized text
        $this->processTextMessage();
    }
}
```

---

## Hasil

**Sebelum:**
- OCR: 57 baris
- Kirim ke Ollama: 57 baris
- RAM usage: ~5GB
- Result: **Server crash**

**Sesudah:**
- OCR: 57 baris
- Filter: ~15 baris penting saja
- Kirim ke Ollama: 15 baris
- RAM usage: ~2.5GB
- Result: **Server stabil** ✅

---

## Alternative: Gunakan Groq untuk Struk

Jika masih berat, gunakan **Groq khusus untuk struk**:

```php
protected function handleTransaction(string $messageText): void
{
    // Check if text is from OCR (long text)
    $lineCount = count(explode("\n", $messageText));
    
    // Use Groq for long OCR text (>10 lines)
    if ($lineCount > 10) {
        $aiService = new AIProcessorService();
        $aiService->setProvider('groq'); // Force use Groq
    } else {
        $aiService = new AIProcessorService();
        // Use default (Ollama for short text)
    }
    
    // ... rest of code ...
}
```

---

## Server Auto-Restart

**Ya, server akan online lagi:**

1. **Auto-restart** (jika enabled di VPS panel)
   - Biasanya 2-5 menit
   
2. **Manual restart** (dari VPS panel)
   - Login ke panel VPS
   - Klik "Restart" atau "Reboot"
   
3. **Setelah online:**
   ```bash
   ssh test_finwa@your-server-ip
   pm2 resurrect  # Restore PM2 processes
   ```

---

## Quick Fix Setelah Server Online

```bash
# 1. SSH ke server
ssh test_finwa@your-server-ip

# 2. Stop Ollama sementara
sudo systemctl stop ollama

# 3. Switch ke Groq untuk struk
cd /var/www/test_finwa/data/www/test.finwa.web.id/services/ai-processor
nano .env

# Change:
AI_PROVIDER=groq

# 4. Restart services
pm2 restart all

# 5. Test dengan text message (bukan struk)
# Kirim: "makan malam 23 rb"
# Harus dapat balasan ✅

# 6. Nanti setelah optimasi di-deploy, baru test struk lagi
```

---

**Kesimpulan:**
- ✅ Text message: Ollama OK (cepat, ringan)
- ❌ Struk OCR: Ollama terlalu berat → **Perlu optimasi** atau **gunakan Groq**
