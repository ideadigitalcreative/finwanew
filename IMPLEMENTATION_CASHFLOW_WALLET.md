# 🚀 IMPLEMENTATION: CASHFLOW ANALYSIS & WALLET RULES

## 📋 **Changes Needed in ProcessIncomingMessage.php**

### **1. Improve Cashflow Analysis (Line 5580-5639)**

**Current Response:**
```
💰 Ringkasan Keuangan
📅 December 2025
━━━━━━━━━━━━━━━

📊 Cashflow Bulan Ini
💵 Pendapatan: Rp 6.000.000
💸 Pengeluaran: Rp 7.082.000
📉 Defisit: Rp 1.082.000

🏦 Saldo Rekening
💵 Gopay: Rp 2.950.000

━━━━━━━━━━━━━━━
⚠️ Pengeluaran melebihi pendapatan!
```

**Target Response:**
```
📊 Ringkasan Keuangan Bulan Ini
📅 December 2025
━━━━━━━━━━━━━━━

📊 Cashflow Bulan Ini
💰 Pemasukan: Rp 6.000.000
💸 Pengeluaran: Rp 7.082.000
📈 Saldo Bersih: Rp -1.082.000
📝 Total Transaksi: 12

💰 Top Pemasukan:
  • Pendapatan Lainnya: Rp 6.000.000

💸 Top Pengeluaran:
  • Pinjaman: Rp 4.000.000
  • Belanja: Rp 2.270.000
  • Tagihan: Rp 450.000

🏦 Saldo Rekening
💵 Gopay: Rp 2.950.000
💵 Dompet Default: Rp 3.000.000

━━━━━━━━━━━━━━━
💰 Total Saldo: Rp 5.950.000
⚠️ Pengeluaran melebihi pendapatan!
```

**Code Changes:**

```php
// After line 5599, add:

// Total transactions count
$transactionCount = $transactions->count();
$reply .= "📝 Total Transaksi: {$transactionCount}\n\n";

// Top Income Categories
$topIncome = $transactions
    ->where('type', 'income')
    ->groupBy('category_id')
    ->map(function($group) {
        return [
            'category' => $group->first()->category->name ?? 'Lainnya',
            'total' => $group->sum('amount')
        ];
    })
    ->sortByDesc('total')
    ->take(3);

if ($topIncome->isNotEmpty()) {
    $reply .= "💰 *Top Pemasukan:*\n";
    foreach ($topIncome as $item) {
        $amount = number_format($item['total'], 0, ',', '.');
        $reply .= "  • {$item['category']}: Rp {$amount}\n";
    }
    $reply .= "\n";
}

// Top Expense Categories
$topExpense = $transactions
    ->where('type', 'expense')
    ->groupBy('category_id')
    ->map(function($group) {
        return [
            'category' => $group->first()->category->name ?? 'Lainnya',
            'total' => $group->sum('amount')
        ];
    })
    ->sortByDesc('total')
    ->take(3);

if ($topExpense->isNotEmpty()) {
    $reply .= "💸 *Top Pengeluaran:*\n";
    foreach ($topExpense as $item) {
        $amount = number_format($item['total'], 0, ',', '.');
        $reply .= "  • {$item['category']}: Rp {$amount}\n";
    }
    $reply .= "\n";
}
```

**After line 5612 (after balances loop), add:**

```php
// Show total balance
if ($balances->isNotEmpty()) {
    $reply .= "━━━━━━━━━━━━━━━\n";
    $reply .= "💰 *Total Saldo: Rp " . number_format($totalBalance, 0, ',', '.') . "*\n";
}
```

---

### **2. Default Wallet Creation**

**Location:** `app/Helpers/WhatsAppRegistrationHelper.php` line ~150

**Add after tenant creation:**

```php
// Create default balance/wallet
\App\Models\Balance::create([
    'tenant_id' => $tenant->id,
    'account_name' => 'Dompet Utama',
    'account_type' => 'cash',
    'currency' => 'IDR',
    'balance' => 0,
    'balance_date' => now(),
    'is_active' => true,
    'is_default' => true,
]);
```

---

### **3. Wallet Fallback Logic**

**Location:** `ProcessIncomingMessage.php` around line 4100-4150

**Current:**
```php
// Extract account name
$accountName = extractAccountFromText($messageText);
$balance = Balance::where('account_name', $accountName)->first();
```

**Improved:**
```php
// Extract account name
$accountName = extractAccountFromText($messageText);

if ($accountName) {
    // Try to find specified wallet
    $balance = Balance::where('tenant_id', $this->message->tenant_id)
        ->where('account_name', 'LIKE', "%{$accountName}%")
        ->where('is_active', true)
        ->first();
    
    if (!$balance) {
        // Wallet not found, use default
        $balance = Balance::where('tenant_id', $this->message->tenant_id)
            ->where('is_default', true)
            ->first();
        
        // Add warning to response
        $walletNotFoundWarning = "⚠️ Dompet {$accountName} tidak ditemukan, menggunakan dompet default\n";
    }
} else {
    // No wallet specified, use default
    $balance = Balance::where('tenant_id', $this->message->tenant_id)
        ->where('is_default', true)
        ->first();
}

// If still no balance, create default
if (!$balance) {
    $balance = Balance::create([
        'tenant_id' => $this->message->tenant_id,
        'account_name' => 'Dompet Utama',
        'account_type' => 'cash',
        'currency' => 'IDR',
        'balance' => 0,
        'balance_date' => now(),
        'is_active' => true,
        'is_default' => true,
    ]);
}
```

---

### **4. Transaction Response with Warning**

**Location:** Line 4355 (in transaction response)

**Add after balance display:**

```php
if (isset($walletNotFoundWarning)) {
    $reply .= $walletNotFoundWarning;
}
```

---

## 🧪 **Testing Scenarios**

### **Test 1: Cashflow Analysis**
```
Input: cek cashflow

Expected:
- ✅ Total transaksi count
- ✅ Top 3 pemasukan by category
- ✅ Top 3 pengeluaran by category
- ✅ Total saldo (sum of all balances)
```

### **Test 2: Default Wallet**
```
Input: terima gaji 5jt

Expected:
- ✅ Masuk ke Dompet Utama (default)
- ✅ Response: "Sisa saldo Dompet Utama: Rp 5.000.000"
```

### **Test 3: Specific Wallet**
```
Input: bayar makan 150rb pakai Gopay

Expected:
- ✅ Gopay balance -150rb
- ✅ Response: "Sisa saldo Gopay: Rp X"
```

### **Test 4: Wallet Not Found**
```
Input: bayar makan 150rb pakai Mandiri

Expected:
- ✅ Use default wallet
- ✅ Response includes: "⚠️ Dompet Mandiri tidak ditemukan, menggunakan dompet default"
- ✅ Response: "Sisa saldo Dompet Utama: Rp X"
```

---

## 📝 **Implementation Priority**

1. **HIGH**: Cashflow Analysis improvements (user-facing)
2. **HIGH**: Default wallet creation on registration
3. **MEDIUM**: Wallet fallback logic
4. **MEDIUM**: Warning message for wallet not found
5. **LOW**: Auto-create default wallet if missing

---

## 🚀 **Deployment Steps**

1. Edit `ProcessIncomingMessage.php` - Add cashflow improvements
2. Edit `WhatsAppRegistrationHelper.php` - Add default wallet creation
3. Upload both files to VPS
4. Run: `php artisan cache:clear && php artisan queue:restart`
5. Test all scenarios

---

**Status:** 📝 Implementation guide ready
**Next:** Apply changes to code files
