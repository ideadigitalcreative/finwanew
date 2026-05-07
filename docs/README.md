# FinWa — Dokumentasi Internal

Dokumentasi teknis, panduan fitur, catatan perbaikan, dan referensi deployment.

## Struktur

```
docs/
├── features/        Desain & analisa fitur (hutang-piutang, AI, wallet, dsb)
├── fixes/           Catatan bug & solusinya
├── guides/          Panduan pemakaian (batch transaksi, panduan UMKM)
├── deployment/      Referensi konfigurasi hosting (htaccess, storage path)
├── migrations/      Catatan migrasi besar (OCR Gemini, dsb)
├── ollama/          Dokumentasi integrasi Ollama (LLM lokal)
├── seo/             Catatan SEO & struktur halaman publik
├── wa-registration/ Alur registrasi via WhatsApp
└── workflow/        Alur sistem end-to-end
```

## Kontribusi

- Semua markdown baru WAJIB masuk salah satu subfolder di atas, **jangan** di root proyek.
- Gunakan nama file `kebab-case.md`, kecuali untuk dokumen lama yang masih di-reference link-nya.
- Tambahkan tanggal revisi di bagian atas dokumen saat melakukan perubahan besar.
