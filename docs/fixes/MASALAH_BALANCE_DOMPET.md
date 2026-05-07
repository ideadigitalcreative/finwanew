# 🐛 MASALAH BALANCE/DOMPET

## 📋 **Issues:**

### **1. Saldo Dompet Tidak Terpotong**
**Masalah:**
```
User: "bayar makan 150rb pakai Gopay"
Expected: Gopay balance berkurang 150rb
Actual: Balance tidak berubah, atau balance default yang terpotong
```

**Root Cause:**
- System tidak extract "Gopay" dari text dengan benar
- Atau transaksi tidak ter-link ke balance Gopay
- Balance detection logic di line 4094-4138 mungkin tidak match

**Lokasi Code:**
- `app/Jobs/ProcessIncomingMessage.php` line 4094-4138
- Function yang detect "pakai [nama_dompet]"

---

### **2. Sisa Saldo Selalu 0**
**Masalah:**
```
Response: "👛 Sisa saldo: Rp 0"
Padahal: Gopay balance = Rp 8.000.000
```

**Root Cause:**
- Line 3442-3444: `$transaction->balance->current_balance`
- Jika transaksi tidak ter-link ke balance, return 0
- Seharusnya tampilkan total semua balance atau balance yang digunakan

**Lokasi Code:**
- `app/Jobs/ProcessIncomingMessage.php` line 3442-3444

---

## ✅ **Solusi:**

### **Fix 1: Improve Balance Detection**

Di line 4094-4138, pastikan pattern match untuk "pakai Gopay":

```php
// Current pattern
'/pakai\s+saldo\s+(?:bank\s+)?([a-z]+)/i'

// Should also match
'/pakai\s+([a-z]+)/i'  // "pakai Gopay"
'/pake\s+([a-z]+)/i'   // "pake Gopay"
```

### **Fix 2: Fix Sisa Saldo Display**

Di line 3442-3444, ganti logic:

```php
// OLD (WRONG):
if ($transaction->balance) {
    $currentBalance = number_format($transaction->balance->current_balance ?? 0, 0, ',', '.');
    $reply .= "👛 Sisa saldo: Rp {$currentBalance}\n";
}

// NEW (CORRECT):
if ($transaction->balance) {
    $currentBalance = number_format($transaction->balance->current_balance ?? 0, 0, ',', '.');
    $reply .= "👛 Sisa saldo {$transaction->balance->account_name}: Rp {$currentBalance}\n";
} else {
    // Show total balance if no specific balance linked
    $totalBalance = \App\Models\Balance::where('tenant_id', $this->message->tenant_id)
        ->sum('current_balance');
    $currentBalance = number_format($totalBalance, 0, ',', '.');
    $reply .= "👛 Total saldo: Rp {$currentBalance}\n";
}
```

---

## 🔍 **Debug Steps:**

### **1. Check Balance Detection:**
```bash
cd ~/www/finwa.web.id
tail -100 storage/logs/laravel.log | grep -i "balance\|account"
```

### **2. Check Transaction:**
```sql
SELECT id, amount, description, balance_id 
FROM transactions 
WHERE description LIKE '%Gopay%' 
ORDER BY created_at DESC 
LIMIT 5;
```

### **3. Check Balance:**
```sql
SELECT * FROM balances WHERE tenant_id = [tenant_id];
```

---

## 📝 **Testing:**

### **Test Case 1:**
```
Input: "beli makan 150rb pakai Gopay"
Expected:
- Transaction created with balance_id = Gopay balance ID
- Gopay balance reduced by 150rb
- Response shows: "Sisa saldo Gopay: Rp X"
```

### **Test Case 2:**
```
Input: "lihat dompet"
Expected:
- Show all balances with correct amounts
```

### **Test Case 3:**
```
Input: "beli makan 100rb" (no balance specified)
Expected:
- Use default balance
- Show total balance or default balance
```

---

## 🚀 **Implementation Priority:**

1. **HIGH**: Fix sisa saldo display (line 3442-3444)
2. **HIGH**: Improve balance detection pattern (line 4094-4138)
3. **MEDIUM**: Add logging for balance detection
4. **LOW**: Add validation for balance existence

---

**Status:** 🔴 Critical - Affects core functionality
**Files to Edit:** `app/Jobs/ProcessIncomingMessage.php`
