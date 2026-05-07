# Rencana Implementasi Migrasi OCR Struk ke Gemini Flash Vision

## Tujuan
Mengganti alur OCR gambar struk dari `FinWaAIService::processImage()` ke `Gemini Flash Vision` tanpa mengubah perilaku bisnis utama:
- Tetap membuat **1 transaksi pengeluaran** per struk.
- Tetap memakai fallback parser lokal jika ekstraksi AI tidak lengkap.
- Tetap mempertahankan format data yang dibaca downstream (`entities`).

## Alur Saat Ini (As-Is)
1. Gambar masuk sebagai message `image` di `ProcessIncomingMessage`.
2. `createOcrJob()` membuat record `ocr_jobs`.
3. `OcrProcessorService::dispatchToOcrWorker()` memanggil `processImageWithFinWaAI()` (sinkron).
4. OCR image diproses via `FinWaAIService::processImage()`.
5. Hasil disimpan ke `ocr_jobs.metadata.entities`, `message` diubah ke `text`, lalu `ProcessIncomingMessage` didispatch ulang.
6. Blok `isFromOcr` membuat transaksi berdasarkan `fields.total` / `entities.nominal` + metadata lain.

## Target Alur (To-Be)
1. Jalur masuk tetap sama sampai `OcrProcessorService`.
2. OCR image dipanggil ke `Gemini Flash Vision` (bukan FinWa image OCR).
3. Hasil Gemini dinormalisasi ke schema internal:
   - `entities.nominal`
   - `entities.merchant`
   - `entities.tanggal`
   - `entities.items`
   - `confidence`
   - `raw_text`
4. Jika data belum cukup:
   - fallback ke `ReceiptParserService` (regex/heuristik lokal).
   - optional fallback text-only ke `FinWaAIService::processOCR()`.
5. Simpan hasil ke `ocr_jobs` dengan format kompatibel.
6. Lanjut proses transaksi seperti sekarang (tanpa ubah aturan bisnis).

## File Prioritas Perubahan
- `app/Services/OCR/OcrProcessorService.php`
- `app/Services/GeminiAIService.php`
- `app/Jobs/ProcessIncomingMessage.php`
- `config/services.php`
- `.env.example` (opsional, dokumentasi variabel env model/key)

## Kontrak Data OCR Internal (Wajib Kompatibel)
Gunakan struktur ini saat menyimpan metadata hasil OCR:

```json
{
  "ai_source": "gemini_flash_vision",
  "entities": {
    "nominal": 125000,
    "merchant": "Indomaret",
    "tanggal": "2026-04-10",
    "items": [
      { "name": "Susu UHT", "qty": 1, "price": 21000, "total": 21000 }
    ],
    "category_type": "pengeluaran_belanja"
  },
  "confidence": 0.93,
  "raw_text": "....",
  "raw_response": {}
}
```

Catatan:
- `entities.nominal` harus integer > 0 agar downstream aman.
- Jika tidak ada item, tetap valid (`items: []`) selama nominal ada.

## Tahapan Implementasi
### 1) Wiring Gemini Vision
- Ubah titik call OCR image di `OcrProcessorService`:
  - dari: `FinWaAIService::processImage($dataUri)`
  - ke: `GeminiAIService::parseReceipt($dataUri, $rawTextOptional)`
- Pastikan timeout dan logging jelas (`source`, `latency`, `confidence`).

### 2) Normalizer Output Gemini
- Tambahkan mapper hasil Gemini ke schema `entities` internal.
- Pastikan kompatibel dengan pembacaan existing di `ProcessIncomingMessage`.

### 3) Fallback Berlapis
- Primary: Gemini Flash Vision.
- Secondary: `ReceiptParserService` untuk nominal/merchant/items.
- Tertiary (opsional): `FinWaAIService::processOCR()` bila nominal tetap null.

### 4) Reliability
- Tambahkan retry terbatas + backoff untuk call Gemini.
- Standarkan pesan error user-friendly di `handleOcrFailure()`.

### 5) Validasi Regresi
- Uji struk normal, blur, item banyak, nominal besar, serta bukti transfer.
- Pastikan hasil akhir tetap 1 transaksi expense per struk.

## Risk dan Mitigasi
- **Perbedaan format output Gemini vs format internal**
  - Mitigasi: normalizer tunggal sebelum save metadata.
- **Nominal tidak terbaca**
  - Mitigasi: fallback parser lokal + fallback text OCR.
- **Noise OCR tinggi**
  - Mitigasi: confidence threshold + fallback + validasi nominal range.
- **Path legacy membingungkan (`ProcessOcrImage`)**
  - Mitigasi: dokumentasikan path aktif; cleanup setelah migrasi stabil.

## Checklist Go-Live
- [ ] `GEMINI_API_KEY` tersedia di environment.
- [ ] `GEMINI_MODEL` diarahkan ke varian Flash Vision yang dipilih.
- [ ] Logging observability aktif untuk OCR source/fallback/error.
- [ ] Uji regresi minimal 20 sampel struk lulus.
- [ ] Tidak ada perubahan perilaku bisnis pada pencatatan transaksi.

## Rollback Plan
Jika terjadi degradasi akurasi/latensi:
1. Kembalikan call OCR image ke `FinWaAIService::processImage()`.
2. Pertahankan normalizer dan logging untuk analisis perbaikan.
3. Aktifkan ulang rollout bertahap setelah prompt/model tuning.

## Keputusan Teknis yang Direkomendasikan
- Gunakan pendekatan **compatibility-first**: schema internal tidak berubah, hanya engine OCR image yang diganti.
- Implementasi bertahap dengan fallback tetap aktif agar risiko produksi rendah.
