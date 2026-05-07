# Pengaturan Gemini AI (Super Admin)

Dokumen tahapan implementasi pengaturan dinamis model & API key Gemini, termasuk rotasi beberapa key.

## Status implementasi


| Tahap | Deskripsi                                                                                              | Status  |
| ----- | ------------------------------------------------------------------------------------------------------ | ------- |
| 1     | Migrasi `app_settings` + model `AppSetting` (nilai terenkripsi)                                        | Selesai |
| 2     | `GeminiConfigService`: merge config `.env` + DB, rotasi round-robin key                                | Selesai |
| 3     | `GeminiAIService` memakai `GeminiConfigService` (tanpa mengubah perilaku API selain sumber config/key) | Selesai |
| 4     | Controller + route Super Admin `GET/PUT /superadmin/gemini-settings`                                   | Selesai |
| 5     | Halaman Inertia `SuperAdmin/GeminiSettings/Index.vue` + item menu sidebar                              | Selesai |
| 6     | (Opsional) Retry otomatis ke key berikutnya jika response 429/403 dari Gemini                          | Belum   |
| 7     | (Opsional) Dukungan `GEMINI_API_KEYS` di `.env` (comma-separated) selain satu `GEMINI_API_KEY`         | Belum   |


## Perilaku

- **Prioritas:** Jika baris `app_settings` dengan `key = gemini` ada dan berisi `api_keys` tidak kosong, key tersebut dipakai. Jika kosong atau tidak ada, fallback ke `GEMINI_API_KEY` di `.env`.
- **Model & base URL:** Bisa di-override dari halaman admin; kosongkan base URL untuk URL default Google.
- **Rotasi:** Setiap request HTTP ke Gemini memakai key berikutnya secara round-robin (cache `gemini_api_key_rotation_index`, TTL 1 jam sliding).
- **Keamanan:** Payload disimpan dengan cast `encrypted:array` Laravel; key penuh tidak dikirim ke frontend (hanya mask `••••xxxx`).

## Endpoint

- `GET /superadmin/gemini-settings` — form pengaturan (middleware: `auth`, `verified`, `superadmin`).
- `PUT /superadmin/gemini-settings` — simpan (field: `model`, `base_url`, `replace_api_keys`, `api_keys_text`).

## Setelah deploy

Jalankan migrasi: `php artisan migrate`

Pastikan `APP_KEY` stabil (enkripsi database settings).