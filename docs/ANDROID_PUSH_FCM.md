# Push Notification FinWa — Panduan Aplikasi Android (WebView)

Popup notifikasi Android setiap ada transaksi baru, termasuk saat aplikasi ditutup.
Backend Laravel **sudah siap**; yang perlu ditambahkan di aplikasi Android:
Firebase Messaging + jembatan JavaScript kecil.

```
Transaksi baru (WA / dashboard / struk / batch)
   → Laravel TransactionObserver → job antrean → FCM HTTP v1
   → HP anggota tenant → popup → ketuk → WebView membuka /transactions
```

---

## 1. Firebase (sekali saja)

1. Buka <https://console.firebase.google.com> → **Add project** (gratis).
2. **Add app → Android**, isi *package name* aplikasi WebView (mis. `id.finwa.app`).
3. Unduh **`google-services.json`** → taruh di `app/` proyek Android.
4. **Project settings → Service accounts → Generate new private key** → unduh JSON.
   File ini untuk **server Laravel** (jangan dimasukkan ke aplikasi Android / git).

## 2. Server Laravel

1. Upload JSON service account ke server, mis. `storage/app/firebase-service-account.json`
   (pastikan tidak bisa diakses publik — folder `storage/app` aman).
2. `.env`:
   ```
   FINWA_PUSH_ENABLED=true
   FIREBASE_CREDENTIALS=storage/app/firebase-service-account.json
   # opsional
   FINWA_PUSH_QUIET_HOURS=22:00-06:00
   FINWA_PUSH_NOTIFY_ACTOR=true
   FINWA_PUSH_BATCH_WINDOW=1
   ```
3. Jalankan:
   ```
   php artisan migrate
   php artisan optimize:clear
   php artisan queue:restart
   ```
4. Setelah aplikasi Android terpasang & login, uji:
   ```
   php artisan finwa:push-test email@user.com
   ```

> Notifikasi dikirim lewat **queue** — pastikan queue worker berjalan.

## 3. Aplikasi Android

### 3.1 Gradle

`build.gradle` (project):
```gradle
plugins {
    id 'com.google.gms.google-services' version '4.4.2' apply false
}
```

`app/build.gradle`:
```gradle
plugins {
    id 'com.android.application'
    id 'org.jetbrains.kotlin.android'
    id 'com.google.gms.google-services'
}

dependencies {
    implementation platform('com.google.firebase:firebase-bom:33.5.1')
    implementation 'com.google.firebase:firebase-messaging'
}
```

### 3.2 AndroidManifest.xml

```xml
<uses-permission android:name="android.permission.INTERNET" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

<application ...>

    <!-- Ikon & channel default untuk notifikasi dari server -->
    <meta-data
        android:name="com.google.firebase.messaging.default_notification_icon"
        android:resource="@drawable/ic_notification" />
    <meta-data
        android:name="com.google.firebase.messaging.default_notification_channel_id"
        android:value="transactions" />

    <activity android:name=".MainActivity" android:exported="true"
        android:launchMode="singleTask"> ... </activity>

    <service
        android:name=".FinwaMessagingService"
        android:exported="false">
        <intent-filter>
            <action android:name="com.google.firebase.MESSAGING_EVENT" />
        </intent-filter>
    </service>
</application>
```

`ic_notification` = ikon putih polos (siluet) 24dp di `res/drawable`.

### 3.3 FinwaMessagingService.kt

Menangani token baru & notifikasi saat aplikasi **sedang dibuka** (saat aplikasi di
background/ditutup, Android menampilkan notifikasi otomatis).

```kotlin
class FinwaMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        // Simpan; MainActivity akan menyerahkannya ke web saat halaman dimuat
        getSharedPreferences("finwa", MODE_PRIVATE).edit().putString("fcm_token", token).apply()
        MainActivity.instance?.pushTokenToWeb(token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val n = message.notification ?: return
        val url = message.data["url"] ?: "/dashboard"

        val intent = Intent(this, MainActivity::class.java).apply {
            putExtra("open_url", url)
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pending = PendingIntent.getActivity(
            this, System.currentTimeMillis().toInt(), intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notif = NotificationCompat.Builder(this, "transactions")
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(n.title)
            .setContentText(n.body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(n.body))
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setContentIntent(pending)
            .build()

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            == PackageManager.PERMISSION_GRANTED || Build.VERSION.SDK_INT < 33) {
            NotificationManagerCompat.from(this).notify(System.currentTimeMillis().toInt(), notif)
        }
    }
}
```

### 3.4 MainActivity.kt (bagian yang ditambahkan)

```kotlin
class MainActivity : AppCompatActivity() {

    companion object {
        var instance: MainActivity? = null
        const val BASE_URL = "https://finwa.web.id"
    }

    private lateinit var webView: WebView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        instance = this
        createNotificationChannel()
        requestNotificationPermission()

        webView = findViewById(R.id.webView)
        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true   // dibutuhkan localStorage (nativePush.ts)

        // Jembatan: web memanggil window.FinwaAndroid.getFcmToken()
        webView.addJavascriptInterface(FinwaBridge(), "FinwaAndroid")

        webView.webViewClient = object : WebViewClient() {
            override fun onPageFinished(view: WebView, url: String) {
                currentToken()?.let { pushTokenToWeb(it) }
            }
        }

        // Ambil token FCM (sekali di awal; onNewToken menangani perubahan)
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
            getSharedPreferences("finwa", MODE_PRIVATE).edit().putString("fcm_token", token).apply()
            pushTokenToWeb(token)
        }

        webView.loadUrl(urlFromIntent(intent) ?: "$BASE_URL/dashboard")
    }

    // Notifikasi diketuk saat aplikasi sudah terbuka
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        urlFromIntent(intent)?.let { webView.loadUrl(it) }
    }

    /** Notifikasi dari background membawa "url" di extras (data payload FCM). */
    private fun urlFromIntent(intent: Intent?): String? {
        val path = intent?.getStringExtra("open_url") ?: intent?.getStringExtra("url") ?: return null
        // Hanya izinkan path internal FinWa
        return if (path.startsWith("/")) BASE_URL + path else null
    }

    fun currentToken(): String? =
        getSharedPreferences("finwa", MODE_PRIVATE).getString("fcm_token", null)

    /** Serahkan token ke web → web mengirim ke POST /device-tokens dengan sesi login. */
    fun pushTokenToWeb(token: String) {
        runOnUiThread {
            val safe = JSONObject.quote(token)
            webView.evaluateJavascript("window.onFinwaFcmToken && window.onFinwaFcmToken($safe)", null)
        }
    }

    inner class FinwaBridge {
        @JavascriptInterface fun getFcmToken(): String? = currentToken()
        @JavascriptInterface fun getDeviceName(): String = "${Build.MANUFACTURER} ${Build.MODEL}"
        @JavascriptInterface fun requestNotificationPermission() = runOnUiThread { this@MainActivity.requestNotificationPermission() }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            val channel = NotificationChannel(
                "transactions", "Transaksi baru", NotificationManager.IMPORTANCE_HIGH
            ).apply { description = "Popup setiap ada transaksi baru di FinWa" }
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }

    private fun requestNotificationPermission() {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS)
            != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.POST_NOTIFICATIONS), 1001)
        }
    }

    override fun onDestroy() { instance = null; super.onDestroy() }
}
```

> **Keamanan jembatan JS:** `addJavascriptInterface` hanya mengekspos token & nama
> perangkat. Pastikan WebView **hanya memuat domain FinWa** (tolak URL luar di
> `shouldOverrideUrlLoading` → buka di browser), agar halaman asing tidak bisa
> membaca token.

## 4. Payload yang dikirim server

```json
{
  "notification": { "title": "💸 Pengeluaran baru", "body": "Ivan: beli beras — Rp 91.000 (Bahan Makanan)" },
  "data": { "type": "transaction", "transaction_id": "123", "url": "/transactions" },
  "android": { "priority": "high", "notification": { "channel_id": "transactions" } }
}
```

Banyak transaksi sekaligus (batch / struk) → **satu** notifikasi:
`🧾 8 transaksi baru — Ivan mencatat 8 transaksi · keluar Rp 762.000`.

## 5. Aturan pengiriman (backend)

| Aturan | Default | Config |
|---|---|---|
| Penerima | Semua anggota tenant yang push-nya aktif & punya perangkat | — |
| Pencatat via WhatsApp ikut menerima | Ya | `FINWA_PUSH_NOTIFY_ACTOR` |
| Pencatat via dashboard/aplikasi | Tidak (ia sudah melihatnya) | `in_app_sources` |
| Gabung transaksi beruntun | 1 detik | `FINWA_PUSH_BATCH_WINDOW` |
| Jam tenang (WIB) | nonaktif | `FINWA_PUSH_QUIET_HOURS=22:00-06:00` |
| Tidak dinotifikasi | pindah saldo internal, saldo awal, status bukan `confirmed` | `skip_types`, `skip_sources` |
| Token kedaluwarsa | dihapus otomatis | — |

User mengatur sendiri di **Settings → Notifikasi**: aktif/nonaktif, sembunyikan nominal,
dan hapus perangkat.

## 6. Checklist uji

- [ ] Login di aplikasi → **Settings → Notifikasi** menampilkan perangkat.
- [ ] `php artisan finwa:push-test email@user.com` → popup muncul.
- [ ] Catat transaksi via WhatsApp → popup muncul (aplikasi ditutup & dibuka).
- [ ] Ketuk notifikasi → aplikasi membuka halaman transaksi.
- [ ] Kirim 5 baris transaksi sekaligus → hanya 1 notifikasi ringkasan.
- [ ] Android 13+: dialog izin notifikasi muncul saat pertama buka.
