# Rencana Implementasi: Notifikasi Real-time WhatsApp/Telegram untuk Transaksi Anggota Grup

## 1. Ringkasan Fitur

Saat seorang **anggota grup/keluarga** (tenant) mencatat transaksi — baik via WhatsApp maupun dashboard — aplikasi akan mengirim **notifikasi real-time** ke **anggota lain** di grup yang sama melalui WhatsApp atau Telegram.

> Contoh: Jika "Budi" (anggota grup keluarga) mencatat transaksi "beli makan 25rb" via WhatsApp, maka "Siti" dan "Andi" (anggota grup lain) akan menerima notifikasi di WhatsApp/Telegram mereka masing-masing.

---

## 2. Analisis Arsitektur yang Ada

### Relasi Grup / Anggota
- **Model:** `App\Models\Tenant`
- **Relasi anggota:** `Tenant::members()` → `belongsToMany(User::class, 'user_tenants')`
- **Filter anggota aktif:** `Tenant::activeMembers()` → hanya `is_active = true`
- **Pivot:** tabel `user_tenants` (`user_id`, `tenant_id`, `role_id`, `is_active`, `joined_at`)

### User — Kontak
- `User` memiliki field: `whatsapp_number`, `telegram_username`, `telegram_chat_id`
- `UserWhatsAppNumber` — mendukung multiple nomor per user (termasuk format LID)
- `UserTelegramMapping` — mapping Telegram chat_id (dengan field `notifications_enabled`, `is_active`)

### Layanan Kirim Pesan
| Channel | Service | Metode Utama | Signature |
|---------|---------|-------------|-----------|
| WhatsApp | `App\Services\WhatsAppService` | `sendMessage()` | `(string $sessionId, string $toNumber, string $message, string $type = 'text', ?string $originalLid = null, ...)` |
| | | `sendMessageToLid()` | `(string $sessionId, string $lidAddress, string $message, ...)` |
| Telegram | `App\Services\TelegramService` | `sendMessage()` | `(int\|string $chatId, string $text, array $options = [])` |

### Resolusi Channel
- `Channel` model — ada per tenant, field `type` (whatsapp/telegram), `channel_account`, `session_id`, `config` (JSON), `is_shared_channel`
- `WhatsAppService::generateSessionId()` → format `wa_{$tenantId}_{$channelAccount}`

### Arah Transaksi
| Sumber | Entry Point | Membuat Transaksi | Notifikasi? |
|--------|-------------|-------------------|-------------|
| WhatsApp | `WhatsAppWebhookController` → job `ProcessIncomingMessage` → `TransactionService::createTransaction()` | ✅ (line 1072) | ❌ Hanya balas ke **pengirim** |
| Telegram | `TelegramWebhookController` → `TelegramService::handleWebhook()` | (perlu verifikasi) | ❌ |
| Dashboard | `TransactionController::storeJson()` | ✅ (line 492) | ❌ |

### Sistem Event/Listerner yang Ada
**Tidak ada.** Tidak ada direktori `app/Events/` atau `app/Listeners/`. Transaksi tidak memancarkan event. Notifikasi (confirmation) dilakukan langsung via callback.

---

## 3. Rancangan Solusi

### Pendekatan: Laravel Event + Listener (direkomendasikan)

Membuat sistem berbasis event agar tidak mengubah kode inti `TransactionService`/`TransactionController` secara signifikan — cukup tambahkan `event(new TransactionCreated($transaction))` di satu titik.

```
Transaction dibuat
       │
       ▼
[event(TransactionCreated)]  ←  event() dispatch
       │
       ▼
[listener(SendTransactionNotificationToGroupMembers)]
       │
       ├─► Cari anggota grup (kecuali pencatat)
       ├─► Untuk tiap anggota: resolve WA number / Telegram chat_id
       ├─► Kirim notifikasi via WhatsApp OR Telegram
       └─► Simpan log notifikasi
```

### Alternatif: Dispatch Job

Alternatif tanpa Event — gunakan `dispatch(new NotifyGroupMembersJob($transaction))`. Namun Event + Listener lebih bersih dan testable, sekaligus memungkinkan subscriber tambahan di masa depan (push notification, email, dsb.).

---

## 4. Komponen yang Perlu Dibuat

### 4.1. Event: `App\Events\TransactionCreated`

**Path:** `app/Events/TransactionCreated.php`

```php
class TransactionCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsSockets, SerializesModels;

    public function __construct(
        public Transaction $transaction,
    ) {}
}
```

> Opsional `ShouldBroadcast` jika ingin real-time ke browser juga, namun tidak wajib untuk notifikasi WA/Telegram.

### 4.2. Listener: `App\Listeners\SendTransactionNotificationToGroupMembers`

**Path:** `app/Listeners/SendTransactionNotificationToGroupMembers.php`

**Tugas:**
1. Dapatkan `tenant` dari transaksi.
2. Dapatkan `activeMembers()` kecuali user yang mencatat (`created_by` / `user_id`).
3. Untuk tiap anggota:
   - Jika punya `telegram_chat_id` *valid* (atau `UserTelegramMapping` aktif): kirim via **Telegram**.
   - Jika punya `whatsapp_number` (atau `UserWhatsAppNumber` aktif): kirim via **WhatsApp**.
4. Gunakan `ChannelServiceResolverService` sebagai abstraksi kanal (Telegram) dan `WhatsAppService` langsung untuk WhatsApp.

**Dependency injection:** `WhatsAppService`, `TelegramService`, `ChannelServiceResolverService`.

### 4.3. Service notifikasi: `App\Services\MemberNotificationService` (baru)

**Path:** `app/Services/MemberNotificationService.php`

Mengkapsulkan logika resolusi channel & pengiriman, sehingga listener tetap tipis.

**Method:**
- `notifyTransaction(Transaction $transaction, User $recipient): void`
- `resolveWhatsAppTarget(User $user): ?array` → mengembalikan `[sessionId, toNumber|lidAddress, isLid]`
- `resolveTelegramTarget(User $user): ?string` → `telegram_chat_id` (cek `UserTelegramMapping::notifications_enabled`)

### 4.4. Job (opsional, untuk async + queue)

**Path:** `app/Jobs/SendTransactionNotificationJob.php`

Meng-handle pengiriman secara async agar tidak menghambat webhook handler / request API. Listener dapat `dispatch()` job ini.

> ⚠️ Pastikan queue worker berjalan (`php artisan queue:work`). Jika queue belum dipakai di project ini, gunakan **sync** (listener langsung kirim) untuk MVP.

### 4.5. Event Service Provider

Daftarkan listener di `EventServiceProvider` (atau `Event::listen` di `AppServiceProvider::boot`):

```php
protected $listen = [
    App\Events\TransactionCreated::class => [
        App\Listeners\SendTransactionNotificationToGroupMembers::class,
    ],
];
```

### 4.6. Hook dispatch event

**WhatsApp flow:** Di `TransactionService::createTransaction()` setelah transaksi berhasil disimpan (sekitar line 1086), tambahkan:

```php
event(new \App\Events\TransactionCreated($transaction));
```

**Dashboard flow:** Di `TransactionController::storeJson()` setelah `Transaction::create()` (line 492), tambahkan:

```php
event(new \App\Events\TransactionCreated($transaction));
```

---

## 5. Skema Database (opsional — hanya jika ingin audit log)

Buat tabel `transaction_notifications` untuk mencatat notifikasi yang terkirim (audit / replay / statistik):

**Path:** `database/migrations/YYYY_MM_DD_create_transaction_notifications_table.php`

```php
Schema::create('transaction_notifications', function (Blueprint $table) {
    $table->id();
    $table->foreignId('transaction_id')->constrained()->onDelete('cascade');
    $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
    $table->foreignId('recipient_user_id')->constrained('users')->onDelete('cascade');
    $table->string('channel');         // 'whatsapp' | 'telegram'
    $table->string('status');          // 'sent' | 'failed' | 'skipped'
    $table->text('message')->nullable();
    $table->string('error_message')->nullable();
    $table->timestamps();

    $table->index(['transaction_id', 'recipient_user_id']);
});
```

> Opsional — bisa ditambahkan di akhir jika time budget mencukupi.

---

## 6. Format Notifikasi

**WhatsApp / Telegram ke anggota lain:**

```
💰 Transaksi baru dicatat oleh {nama_pencatat}

Jenis    : {type}  (Pemasukan / Pengeluaran)
Jumlah   : Rp {amount_formatted}
Kategori : {category_name}
Untuk    : {tenant_name}
📅 {date}

📋 Deskripsi: {description}  (jika ada)

Balas dengan _komentar_ untuk berdiskusi.
```

Gunakan markdown / format platform yang sesuai. Untuk Telegram gunakan `parse_mode: HTML` agar **bold** (`**text**` tidak valid di HTML — pakai `<b>` atau gunakan MarkdownV2 `*text*`).

---

## 7. Logika Penyelesaian Kanal (Channel Resolution)

Untuk tiap anggota, urutan prioritas pengiriman:

1. **Telegram** — cek `UserTelegramMapping` (aktif + `notifications_enabled = true`):
   ```php
   UserTelegramMapping::where('user_id', $user->id)
                      ->where('is_active', true)
                      ->where('notifications_enabled', true)
                      ->first();
   ```
   Jika ada → `TelegramService::sendMessage($chatId, $message, ['parse_mode' => 'MarkdownV2'])`.

2. **WhatsApp** — cek `UserWhatsAppNumber` (aktif, bukan LID) atau `User->whatsapp_number`:
   ```php
   $number = UserWhatsAppNumber::where('user_id', $user->id)
                               ->where('is_active', true)
                               ->where('is_lid', false)
                               ->first()?->whatsapp_number
       ?? $user->whatsapp_number;
   ```
   Lalu cari channel WhatsApp shared/default untuk tenant, dan kirim via `WhatsAppService::sendMessage()`.

3. **Skip** — jika tidak punya kanal aktif, lewati (catat log "no channel").

---

## 8. Langkah Implementasi (Roadmap MVP → Full)

| Fase | Durasi | Kompn. | Deskripsi |
|------|--------|--------|-----------|
| **MVP** | 1 hari kerja | 4.1, 4.2, 4.5, 4.6 (2 titik) | Event + Listener + dispatch di WhatsApp flow. Kirim ke member via Telegram (primary) + WhatsApp fallback. Log ke `transaction_notifications`. |
| **Full** | +½ hari | 4.3, 4.4, dashboard hook | Refactor ke `MemberNotificationService` + async `Job`. Tambahkan dashboard trigger. Audit trail selengkapnya. |

---

## 9. Risiko & Pertimbangan

| Risiko | Mitigasi |
|--------|----------|
| **Duplicate notifikasi** (race condition / job diproses 2x) | Gunakan `transaction_notifications` unique (`transaction_id + recipient_user_id`) |
| **Spam notifikasi** | Tambahkan throttling per user (mis. 1 notif/transaksi, atau cooldown) |
| **WhatsApp session tidak connect** | Cek `session_status` via `getSessionStatus()` sebelum kirim; log error; skip jika tidak connect |
| **Pengirim juga menerima notifikasinya** | Eksklusi `created_by` / `user_id` pencatat dari daftar anggota |
| **User belum verifikasi WhatsApp/Telegram** | Skip kirim, jangan spam |
| **Blocking webhook** | Gunakan Queue (Job async) untuk kirim notifikasi |
| **Format nama/kanal berbeda (LID)** | Gunakan `UserWhatsAppNumber` + fallback ke `User->whatsapp_number` |

---

## 10. Ringkasan File yang Akan Dibuat/Diubah

| Aksi | File | Keterangan |
|------|------|------------|
| **Buat** | `app/Events/TransactionCreated.php` | Event baru |
| **Buat** | `app/Listeners/SendTransactionNotificationToGroupMembers.php` | Listener utama |
| **Buat** | `app/Services/MemberNotificationService.php` | Abstraksi kanal & kirim |
| **Buat** | `app/Jobs/SendTransactionNotificationJob.php` | Kirim async (opsional) |
| **Buat** | `database/migrations/..._create_transaction_notifications_table.php` | Audit log |
| **Ubah** | `app/Services/Transaction/TransactionService.php` (~line 1086) | `event(new TransactionCreated($transaction))` |
| **Ubah** | `app/Http/Controllers/TransactionController.php` (~line 492) | `event(new TransactionCreated($transaction))` |
| **Ubah** | `app/Providers/EventServiceProvider.php` | Daftarkan listener |

---

## 11. Checklist Verifikasi

- [ ] Transaksi via WhatsApp mengirim notifikasi ke member lain
- [ ] Transaksi via Dashboard mengirim notifikasi ke member lain
- [ ] Pengirim transaksi tidak menerima notifikasi dirinya sendiri
- [ ] Telegram dikirim jika `UserTelegramMapping` aktif
- [ ] WhatsApp dikirim sebagai fallback / alternatif
- [ ] Notifikasi tidak error saat user tidak punya kanal aktif (skip, tidak crash)
- [ ] Duplicate notifikasi dicegah (ID unik)
- [ ] Queue worker dapat memproses job notifikasi (jika pakai queue)
