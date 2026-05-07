# scripts/dev — Developer Scratch Scripts

Folder ini berisi **script lokal sekali-pakai** (debug, investigasi, fix data, cleanup manual, patch one-off) yang **tidak** dijalankan oleh scheduler atau production.

## Aturan

1. **Jangan commit** isi folder ini. Seluruh folder sudah di-`.gitignore` kecuali `README.md` dan `.gitkeep`.
2. Jika suatu script terbukti berguna dan perlu dipakai berulang, **promosikan** menjadi:
   - Artisan command di `app/Console/Commands/`, atau
   - Test di `tests/`, atau
   - Seeder/migration di `database/`.
3. Skrip di sini **tidak boleh** mengandung kredensial hard-coded. Gunakan `.env`.
4. Hapus script setelah masalah yang diinvestigasi selesai.

## Subfolder

- `backups/` — JSON/SQL backup manual dari user/tenant.

## Menjalankan

```bash
php scripts/dev/nama_script.php
```

Semua script mengasumsikan Laravel autoload sudah tersedia.
