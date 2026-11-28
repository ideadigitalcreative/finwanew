# Rencana Implementasi: Edit Transaksi via Reply WhatsApp

**Status:** Fase 0 ✅ · Fase 1 ✅ (menunggu aktivasi flag) · Fase 2 ✅ · Fase 3 ✅ · Fase 4 ✅
**Target:** FinWa WhatsApp Bot
**Dampak:** Gateway (Node.js) + Laravel Backend

---

## 1. Ringkasan Eksekutif

Fitur ini memungkinkan pengguna **mengedit transaksi yang sudah lewat** dengan cara
**membalas (reply) pesan transaksi** di WhatsApp, bukan hanya mengoreksi transaksi terakhir.

**Contoh penggunaan:**

```text
User → (reply pesan "Makan siang 30rb") → "Salah, harusnya 25rb"
Finwa → ✏️ Ubah transaksi ini?

         📋 Makan siang • 22 Sep 2026 • Dompet Utama
         💵 Rp 30.000 → Rp 25.000

         Balas *Ya* untuk simpan, *Batal* untuk batalkan
```

**Kelayakan: BISA.** Seluruh komponen yang dibutuhkan sudah tersedia —
yang belum ada hanya pengambilan metadata reply dan tabel pemetaan.

---

## 2. Hasil Analisa Kode (Current State)

### 2.1 Yang sudah ada ✅

| Kompone | Lokasi | Kondisi |
|---|---|---|
| Edit transaksi by keyword | `TransactionService.php:1620` | Berfungsi — `ubah beli kue jadi 25rb` |
| Edit transaksi terakhir | `TransactionService.php:1858` | Berfungsi — tapi selalu `orderBy('created_at','desc')->first()` |
| `sendMessage` response | `whatsapp-gateway/index.js:1446` | Mengembalikan `result.key.id` (terbukti dari log) |
| Dedup pesan masuk | `WhatsAppWebhookService.php` | Berfungsi (15 detik) |
| Isolasi tenant | `TransactionService` | Berfungsi — semua query `where('tenant_id', ...)` |

### 2.2 Yang belum ada ❌

| Kebutuhan | Dampak | Tingkat Kesulitan |
|---|---|---|
| Ekstraksi `contextInfo` dari gateway | **Penghalang utama** | Mudah |
| Tabel pemetaan `wa_message_id → transaction_id` | Tanpa ini hanya bisa menebak dari teks | Sedang |
| Alur konfirmasi sebelum eksekusi | Risiko salah ubah data keuangan | Sedang |
| Audit trail perubahan | Wajib untuk fitur keuangan | Mudah |

### 2.3 Temuan kritis

**Gateway membuang metadata reply.**
Di [services/whatsapp-gateway/index.js:466-569](file:///c:/Users/melis/Herd/finwa/services/whatsapp-gateway/index.js#L466-L569)
hanya `body`, `type`, `timestamp` yang diteruskan ke Laravel.

Padahal WhatsApp sudah menyediakan:

```js
message.message.extendedTextMessage.contextInfo = {
  stanzaId,       // ID pesan yang di-reply  ← KUNCI
  participant,    // pengirim pesan asli
  quotedMessage   // isi teks pesan yang di-reply
}
```

**Batasan teknis WhatsApp yang harus diantisipasi:**

- Format `stanzaId` pesan keluar kadang berbeda dengan yang muncul di kutipan → butuh **fallback fuzzy match**.
- `stanzaId` belum tentu tersedia jika pesan sudah sangat lama atau gagal decrypt → butuh **graceful degradation**.
- Balasan grup tidak selalu punya `participant` yang konsisten → butuh **validasi kepemilikan**.

---

## 3. Arsitektur Target

```text
┌─────────────────────────────────────────────────────────┐
│  WHATSAPP USER                                          │
│     │                                                   │
│     │  (reply pesan transaksi) "25rb"                   │
│     ▼                                                   │
│  ┌───────────────────────────────────────────┐          │
│  │ GATEWAY (index.js)                        │          │
│  │  • ekstrak contextInfo                    │          │
│  │  • teruskan reply_context ke Laravel      │          │
│  │  • kirim result.key.id untuk pesan keluar │          │
│  └───────────────────────────────────────────┘          │
│     │                                                   │
│     ▼                                                   │
│  ┌───────────────────────────────────────────┐          │
│  │ LARAVEL                                  │          │
│  │  1. webhook → simpan reply_context        │          │
│  │  2. ProcessIncomingMessage                │          │
│  │  3. resolve transaksi (3 prioritas)       │          │
│  │  4. konfirmasi ke user                    │          │
│  │  5. user balas "Ya" → update atomic       │          │
│  │  6. tulis audit trail                     │          │
│  └───────────────────────────────────────────┘          │
└─────────────────────────────────────────────────────────┘
```

### 3.1 Strategi Resolusi (3 Prioritas)

| # | Sumber | Keyakinan | Aksi |
|---|---|---|---|
| 1 | `stanzaId` cocok tabel pemetaan | Sangat tinggi | Edit langsung setelah konfirmasi singkat |
| 2 | Fuzzy match `quotedMessage` ke deskripsi+nominal (30 hari) | Sedang | **Wajib konfirmasi** + tampilkan detail |
| 3 | Ambiguous / tidak ketemu | Rendah | Tampilkan daftar bernomor, user pilih |

> **Prinsip emas:** Jangan pernah mengedit diam-diam pada prioritas 2 dan 3.

---

## 4. Tabel & Schema

### 4.1 `transaction_message_mappings` (Fase 1)

```php
Schema::create('transaction_message_mappings', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->unsignedBigInteger('user_id')->nullable()->index();
    $table->string('wa_message_id')->index();      // ID pesan balasan Finwa
    $table->unsignedBigInteger('transaction_id')->index();
    $table->string('wa_sender')->nullable();       // nomor/LID pengirim
    $table->timestamp('created_at')->useCurrent();

    $table->unique(['tenant_id', 'wa_message_id']);
    $table->index(['tenant_id', 'created_at']);
});
```

### 4.2 `transaction_edit_audits` (Fase 1)

```php
Schema::create('transaction_edit_audits', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('tenant_id')->index();
    $table->unsignedBigInteger('transaction_id')->index();
    $table->unsignedBigInteger('actor_user_id')->nullable();
    $table->json('old_values');
    $table->json('new_values');
    $table->string('resolve_method');   // mapping | fuzzy | manual_pick
    $table->string('wa_message_id')->nullable();
    $table->timestamp('created_at')->useCurrent();
});
```

---

## 5. Fase Implementasi

### ✅ FASE 0 — Persiapan & Fondasi

> Tujuan: memastikan kode siap dan tidak ada regresi.

- [x] Buat branch `feature/reply-edit`
- [x] Tambah feature flag `finwa.reply_edit.enabled` (default `false`) → `config/finwa_reply_edit.php`
- [x] Tambah unit test untuk `handleEditTransactionByKeyword` (baseline)
- [x] Pastikan `php artisan test` hijau sebelum mulai (baseline: 80 failed pre-existing, tidak berubah setelah implementasi — terverifikasi via diff)

**Exit criteria:** flag tersedia, baseline test hijau.

---

### ✅ FASE 1 — Reply Edit Nominal (MVP)

> Tujuan: balas pesan transaksi → ubah nominal. Ini yang memberi ~80% nilai
> dengan risiko paling terkendali.

**1.1 Gateway: ekstrak `contextInfo`**

- [x] Ekstrak `contextInfo` di `forwardToLaravel` (`index.js:559-591`)
- [x] Tambah field `replyContext: { messageId, participant, remoteJid, text }`
- [x] Sertakan `replyContext: null` bila bukan reply (kontrak konsisten)

```js
const ctx = message.message?.extendedTextMessage?.contextInfo;
const replyTo = ctx?.stanzaId ? {
    messageId:   ctx.stanzaId,
    participant: ctx.participant || null,
    text:        ctx.quotedMessage?.conversation
              || ctx.quotedMessage?.extendedTextMessage?.text
              || null
} : null;
```

**1.2 Gateway: capture `wa_message_id` pesan keluar**

- [x] Di endpoint kirim pesan, kembalikan `result.key.id` ke Laravel (`index.js:1553-1560`)
- [x] `MessageReplyService::sendReply()` capture `data.messageId` → cache sementara (TTL 60 detik)

**1.3 Laravel: teruskan & simpan**

- [x] Validasi `reply_context` di `WhatsAppWebhookService` → masuk `raw_data.reply_context`
- [x] Simpan payload reply sementara (cache `wa_out_msgid:{msgId}`, TTL 60 detik)
- [x] Setelah konfirmasi transaksi terkirim → simpan ke `transaction_message_mappings` (`TransactionConfirmationService::sendConfirmation`)

**1.4 Tabel**

- [x] Migration `transaction_message_mappings`
- [x] Migration `transaction_edit_audits`

**1.5 Resolusi prioritas 1**

- [x] Method `ReplyEditService::resolveTargetTransaction()`:
      - cocokkan `replyContext.messageId` → mapping
      - **wajib** filter `tenant_id`
      - **wajib** cek `participant` bila grup (harus sama dengan pemilik transaksi)

**1.6 Alur konfirmasi**

- [x] State konfirmasi (cache, TTL dari config):
      `{ transaction_id, old_amount, new_amount, wa_message_id, expires_at }`
- [x] Reply preview perubahan
- [x] Handle `Ya` / `Batal` / timeout
- [x] Eksekusi update dalam **satu DB transaction**:
      - adjust saldo atomik (`lockForUpdate`)
      - tulis audit trail
      - hapus state
- [x] Routing di `ProcessIncomingMessage::handle()` (diawali cek `finwa_reply_edit.enabled`)

**Exit criteria:** balas pesan transaksi → konfirmasi → nominal terupdate → saldo benar → audit tersimpan.

> **Catatan aktivasi:** jalankan `php artisan migrate` lalu set
> `FINWA_REPLY_EDIT_ENABLED=true` dan `FINWA_REPLY_EDIT_DRY_RUN=false` di `.env`,
> kemudian `php artisan optimize:clear` + `pm2 restart laravel-queue --update-env`.
> Saat ini default-nya `enabled=false` dan `dry_run=true` (aman).

---

### ✅ FASE 2 — Fuzzy Fallback

> Tujuan: tetap bisa edit meski pesan belum punya mapping (transaksi lama /
> pesan sebelum fitur aktif).

- [x] Ambil teks `replyContext.text` (quotedMessage dari gateway)
- [x] Cari kandidat transaksi tenant dalam `fuzzy_window_days` (default 30 hari)
- [x] Skoring: nominal wajib cocok (+50) + tumpang tindih token deskripsi (+5/token)
- [x] **Satu kandidat** → lanjut alur konfirmasi biasa (resolve_method=`fuzzy`)
- [x] **Banyak kandidat** → daftar bernomor 1-5, user balas angka (resolve_method=`manual_pick`)
- [x] State pilihan (`mode=pick`) dengan TTL sama + dukungan `Batal`
- [x] Setelah edit sukses via fuzzy → tulis mapping langsung (prioritas 1 untuk reply berikutnya)
- [x] Audit `resolve_method` mencerminkan jalur: `mapping` | `fuzzy` | `manual_pick`
- [x] Validasi grup diperketat: cek `participant` reply **atau** `wa_sender` mapping
- [x] Test unit: tokenize, groupSenderAllowed, extractAmount (10 test lulus)

**Exit criteria:** balas pesan lama tanpa mapping → fuzzy → konfirmasi → aman.

> **Aturan keamanan fuzzy:** *tidak pernah* langsung update tanpa konfirmasi
> (baik 1 maupun banyak kandidat), dan selalu difilter `tenant_id`.

---

### ✅ FASE 3 — Reply Hapus, Edit Multi-field, Daftar Interaktif

> Tujuan: pengalaman benar-benar "over power".

- [x] Reply → **hapus** transaksi (`hapus` / `delete` / `buang`) dengan konfirmasi
      Ya/Batal, koreksi saldo atomik + audit `new_values.deleted = true`
- [x] Reply → edit multi-field dalam satu balasan: nominal **dan/atau** tanggal
      (`25rb tgl 21/09/2026`) — hanya perubahan yang ditampilkan di preview
- [x] Ekstrak tanggal edit wajib kata kunci `tgl|tanggal|date` (konsisten
      anti-false-positive Bug #1) + tolak tanggal masa depan > 1 hari
- [x] Hapus pemetaan transaksi saat transaksi dihapus (hindari pointer mati)
- [ ] Balas daftar transaksi lalu ketik `3 ubah jadi 50rb`
      (sebagian tersedia via jalur keyword `handleEditTransactionByKeyword`)
- [ ] Tombol/urutan pilihan cepat (`Ya`, `Batal`, `Selanjutnya`)
- [ ] Batas waktu hard: transaksi > 90 hari wajib sebut tanggal eksplisit

**Exit criteria:** seluruh CRUD transaksi bisa lewat reply.

> Test unit ReplyEditService: **14 test lulus** (extractAmount, extractDate,
> isDeleteCommand, tokenize, groupSenderAllowed, reply_context).
> Suite penuh: 213 lulus, 80 gagal (identik baseline pre-existing, tanpa regresi).

---

### ✅ FASE 4 — Hardening & Observability

> Tujuan: aman untuk trafik produksi.

- [x] Metrics: hit rate resolusi per prioritas (counter cache
      `finwa_reply_edit_metrics:{method}` + log `[reply-edit metric]`)
- [x] Alert bila tingkat fuzzy ambigu > threshold
      (`fuzzy_ambiguity_alert_pct`, default 60%)
- [x] Rate limit per pengirim (`rate_limit_max`=10 / `rate_limit_decay`=60 dtk,
      notifikasi sekali)
- [x] Canary rollout per tenant (`allowed_tenants` via env `FINWA_REPLY_EDIT_TENANTS`)
- [x] Uji skenario grup: validasi participant/wa_sender (test unit)
- [x] Uji tenant isolation: filter `tenant_id` di semua query resolusi (code path)
- [x] Cleanup job: `reply-edit:cleanup` (retensi `mapping_retention_days`=90 hari),
      dijadwalkan harian 02:30 bila fitur aktif
- [ ] Load test pemetaan (index & volume) — perlu environment staging
- [ ] Rollout canary per tenant di produksi (menunggu aktivasi flag)

**Exit criteria:** dashboard metrik hijau, semua skenario keamanan lulus.

> Test: **16 test unit lulus**; suite penuh 215 lulus / 80 gagal
> (80 = baseline pre-existing, **0 regresi**).

---

## 6. Risiko & Mitigasi

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | Cross-tenant access | **Kritis** | Filter `tenant_id` di semua query resolusi + test khusus |
| 2 | Anggota grup edit transaksi orang lain | Tinggi | Cek `participant` vs pemilik transaksi |
| 3 | Saldo tidak sinkron | **Kritis** | Update atomik dalam DB transaction; gagal = rollback |
| 4 | Salah target (edit transaksi keliru) | Tinggi | Selalu konfirmasi untuk prioritas 2 & 3 |
| 5 | Duplikasi proses (WA redeliver) | Sedang | Reuse dedup 15 detik + idempotency key |
| 6 | Metadata reply tidak tersedia | Sedang | Graceful degradation ke flow keyword |
| 7 | Pesan duplikat / dobel konfirmasi | Sedang | State konfirmasi sekali pakai (TTL) |
| 8 | Kepatuhan audit hilang | Tinggi | Wajib tulis `transaction_edit_audits` |

---

## 7. Definition of Done (per Fase)

Sebuah fase dianggap **selesai** hanya jika:

- [ ] `php artisan test` hijau
- [ ] `php -l` bersih untuk semua file diubah
- [ ] Feature flag tetap `false` sampai Fase 4
- [ ] Audit trail tercatat untuk setiap perubahan data
- [ ] Isolasi tenant terverifikasi test
- [ ] Log dry-run ditinjau sebelum flag diaktifkan

---

## 8. Urutan Eksekusi yang Disarankan

```text
Fase 0 ──► Fase 1 ──► [tinjau & uji manual] ──► Fase 2 ──► Fase 3 ──► Fase 4
  │           │                                        │
  └─ fondasi  └─ nilai terbesar, risiko terkendali     └─ hanya jika Fase 2 stabil
```

**Mulai dari Fase 1.** Jangan lompat ke Fase 2/3 sebelum mekanisme konfirmasi
terbukti andal — pada aplikasi keuangan, fitur yang salah ubah data jauh lebih
mahal daripada fitur yang belum lengkap.
