# Popup Update Aplikasi Android (Google Play In-App Updates)

Saat user membuka aplikasi FinWa dan ada versi baru di Play Store, muncul popup
update dari Google — tanpa perlu membuka Play Store.

Kode: `C:\Users\melis\AndroidStudioProjects\Finwa\app\src\main\java\com\idea\finwa\AppUpdateHelper.kt`
(dipanggil dari `MainActivity.onResume`). Library: `com.google.android.play:app-update-ktx:2.1.0`.

## Perilaku

| Jenis | Kapan | Yang dilihat user |
|---|---|---|
| **Fleksibel** (default) | Ada versi baru di Play | Popup Google "Update tersedia" → unduh di latar belakang sambil tetap memakai aplikasi → bar **"Update FinWa sudah siap dipasang · Mulai ulang"** |
| **Wajib** (layar penuh) | Rilis diberi *in-app update priority* **4 atau 5** | Layar update penuh; aplikasi tidak bisa dipakai sebelum update |

- User menekan "Nanti" pada update fleksibel → tidak ditanya lagi **hari itu**.
- Update wajib yang dibatalkan / terputus → ditanya lagi setiap aplikasi dibuka.
- Hanya bekerja untuk aplikasi yang **dipasang dari Play Store**. APK debug / sideload
  tidak memunculkan popup (Play tidak mengenalinya) — ini normal.

## Merilis versi baru

1. Naikkan versi di `app/build.gradle.kts`:
   ```kotlin
   versionCode = 8        // wajib naik setiap rilis
   versionName = "1.0.7"
   ```
2. Build **Android App Bundle (.aab)** bertanda tangan: Android Studio →
   *Build → Generate Signed App Bundle / APK*.
3. Upload ke Play Console → **Production** (atau track lain) → rollout.
4. Setelah Play memproses rilis (beberapa jam), user versi lama mendapat popup
   saat membuka aplikasi.

### Membuat update WAJIB (priority 4–5)

Play Console **tidak** punya tombol untuk prioritas ini — diset lewat
**Google Play Developer API** saat membuat rilis (`inAppUpdatePriority` 0–5,
tidak bisa diubah setelah rilis dibuat). Cara termudah:

- **Fastlane**: `upload_to_play_store(in_app_update_priority: 5, ...)`, atau
- **Gradle Play Publisher**: `play { updatePriority.set(5) }`, atau
- panggil API `edits.tracks.update` dengan `releases[].inAppUpdatePriority = 5`.

Upload biasa lewat web Play Console = priority 0 → update **fleksibel**.

## Cara menguji (tanpa menunggu rilis produksi)

1. Play Console → **Internal testing** → buat rilis versi saat ini (mis. versionCode 7),
   tambahkan email Anda sebagai tester, pasang dari link tester.
2. Buat rilis internal berikutnya dengan versionCode lebih tinggi (8).
3. Buka aplikasi versionCode 7 → popup update muncul.
   (Jika belum muncul: buka halaman aplikasi di Play Store sekali agar cache Play
   diperbarui, lalu buka aplikasi lagi.)

Alternatif cepat: **Internal app sharing** (Play Console → Internal app sharing),
upload 2 versi, pasang versi lama dari link, lalu buka link versi baru sekali.

Log Android Studio (Logcat) filter `FinwaUpdate` menampilkan hasil pengecekan.
