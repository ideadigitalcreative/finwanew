# Bugfix Requirements Document

## Introduction

Di file `config/finwa_category_rules.php`, banyak keyword yang terduplikasi antara section "Bahan Makanan & Bumbu Dapur" (`pengeluaran_bahan_makanan`) dan section "Makanan" (`pengeluaran_makanan`). Karena PHP associative array hanya mengizinkan satu value per key, keyword yang ditulis dua kali akan mengambil value terakhir. Akibatnya, keyword bahan mentah/bumbu dapur seperti `'indomie'`, `'royco'`, `'masako'`, `'santan'`, `'garam'`, `'bawang'`, `'cabai'`, dll yang seharusnya masuk `pengeluaran_bahan_makanan` malah ter-overwrite menjadi `pengeluaran_makanan`.

Bug ini berdampak pada SEMUA tenant karena config bersifat global.

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN keyword bahan masak/bumbu dapur (royco, masako, saori, santan, garam, kecap manis, kecap asin, saus sambal, saus tomat, sambal botol, mentega, margarine, minyak zaitun) didefinisikan di section "Bahan Makanan" sebagai `pengeluaran_bahan_makanan` DAN juga didefinisikan ulang di section "Makanan" sebagai `pengeluaran_makanan` THEN the system mengklasifikasikan keyword tersebut sebagai `pengeluaran_makanan` karena PHP array overwrite (last-key-wins)

1.2 WHEN keyword mie instan (indomie, pop mie) didefinisikan di section "Bahan Makanan" sebagai `pengeluaran_bahan_makanan` DAN juga didefinisikan ulang di section "Makanan" sebagai `pengeluaran_makanan` THEN the system mengklasifikasikan keyword tersebut sebagai `pengeluaran_makanan` alih-alih `pengeluaran_bahan_makanan`

1.3 WHEN keyword bahan mentah/rempah (bawang, cabai, cabe, tomat, jahe, kunyit, lada, merica, terigu, kaldu) didefinisikan di section "Bahan Makanan" sebagai `pengeluaran_bahan_makanan` DAN juga didefinisikan ulang di section "Makanan" sebagai `pengeluaran_makanan` THEN the system mengklasifikasikan keyword tersebut sebagai `pengeluaran_makanan` alih-alih `pengeluaran_bahan_makanan`

1.4 WHEN sebuah keyword muncul dua kali dalam array `expense_keywords` dengan kategori berbeda THEN PHP silently overwrite entry pertama dengan entry terakhir tanpa warning, menyebabkan misklasifikasi tanpa indikasi error

### Expected Behavior (Correct)

2.1 WHEN keyword bahan masak/bumbu dapur (royco, masako, saori, santan, garam, kecap manis, kecap asin, saus sambal, saus tomat, sambal botol, mentega, margarine, minyak zaitun) diklasifikasikan oleh system THEN the system SHALL mengembalikan kategori `pengeluaran_bahan_makanan`

2.2 WHEN keyword mie instan (indomie, pop mie) diklasifikasikan oleh system THEN the system SHALL mengembalikan kategori `pengeluaran_bahan_makanan`

2.3 WHEN keyword bahan mentah/rempah (bawang, cabai, cabe, tomat, jahe, kunyit, lada, merica, terigu, kaldu) diklasifikasikan oleh system THEN the system SHALL mengembalikan kategori `pengeluaran_bahan_makanan`

2.4 WHEN seluruh array `expense_keywords` di-load oleh system THEN the system SHALL memastikan tidak ada keyword yang muncul lebih dari satu kali dengan kategori berbeda (zero duplicate keys across categories)

### Unchanged Behavior (Regression Prevention)

3.1 WHEN keyword makanan siap saji/jadi (nasi goreng, bakso, sate, mie ayam, gofood, grabfood, es kopi, roti bakar, martabak, dll) diklasifikasikan oleh system THEN the system SHALL CONTINUE TO mengembalikan kategori `pengeluaran_makanan`

3.2 WHEN keyword minuman (kopi, teh, jus, es boba, milk tea, cappuccino, latte, americano, dll) diklasifikasikan oleh system THEN the system SHALL CONTINUE TO mengembalikan kategori `pengeluaran_makanan`

3.3 WHEN keyword brand restoran (gofood, grabfood, shopeefood, burger king, richeese, wingstop, dll) diklasifikasikan oleh system THEN the system SHALL CONTINUE TO mengembalikan kategori `pengeluaran_makanan`

3.4 WHEN keyword dari kategori lain (transport, kesehatan, utilitas, hiburan, dll) diklasifikasikan oleh system THEN the system SHALL CONTINUE TO mengembalikan kategori masing-masing yang benar tanpa terpengaruh oleh fix ini

3.5 WHEN keyword yang HANYA ada di satu section (tidak duplikat) diklasifikasikan oleh system THEN the system SHALL CONTINUE TO mengembalikan kategori yang sama seperti sebelum fix
