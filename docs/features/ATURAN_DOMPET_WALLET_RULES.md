# 💳 ATURAN DOMPET (WALLET RULES) - FINWA

## 📋 **Overview**

Sistem dompet FinWa dirancang untuk:
- ✅ **Simple** untuk user awam (auto default wallet)
- ✅ **Fleksibel** untuk user lanjutan (multiple wallets)
- ✅ **Konsisten** dalam pencatatan (no transaction without wallet)

---

## 🎯 **Aturan Utama**

### **1. Dompet Default**

**Definisi:**
- Setiap tenant WAJIB punya 1 dompet default
- Dompet default dibuat otomatis saat user register
- Dompet default selalu aktif (`is_default = 1`)

**Behavior:**
```
User: "terima gaji 5jt"
System: Masuk ke Dompet Default
Result: Dompet Default +Rp 5.000.000
```

**Database:**
```sql
SELECT * FROM balances 
WHERE tenant_id = X 
AND is_default = 1;
```

---

### **2. Dompet Spesifik**

**Definisi:**
- User bisa buat dompet tambahan (Gopay, BCA, Cash, dll)
- Dompet spesifik harus disebutkan eksplisit saat transaksi

**Behavior:**
```
User: "gaji masuk dompet Gopay 5jt"
System: Masuk ke Dompet Gopay
Result: Gopay +Rp 5.000.000, Dompet lain tidak berubah
```

**Pattern Detection:**
```php
// Patterns untuk detect dompet
'/pakai\s+([a-z]+)/i',      // "pakai Gopay"
'/ke\s+([a-z]+)/i',          // "ke BCA"
'/dari\s+([a-z]+)/i',        // "dari Gopay"
'/dompet\s+([a-z]+)/i',      // "dompet Gopay"
```

---

### **3. Total Saldo**

**Formula:**
```
Total Saldo = Σ(semua balance.balance)
```

**Tampilan:**
```
💰 Total Saldo: Rp 10.000.000

Breakdown:
- Dompet Default: Rp 3.000.000
- Gopay: Rp 5.000.000
- BCA: Rp 2.000.000
```

**Query:**
```php
$totalBalance = Balance::where('tenant_id', $tenantId)
    ->where('is_active', 1)
    ->sum('balance');
```

---

## 🔄 **Flow Transaksi**

### **Scenario 1: Tanpa Menyebutkan Dompet**

```
Input: "terima gaji 5jt"

Process:
1. Detect: No wallet mentioned
2. Use: Default wallet
3. Update: default_balance.balance += 5000000
4. Link: transaction.balance_id = default_balance.id

Response:
✅ Berhasil Dicatat!
💰 Pemasukan
💵 Rp 5.000.000
👛 Sisa saldo: Rp 8.000.000
```

---

### **Scenario 2: Dengan Menyebutkan Dompet**

```
Input: "bayar makan 150rb pakai Gopay"

Process:
1. Detect: "pakai Gopay"
2. Find: Balance where account_name = 'Gopay'
3. Update: gopay_balance.balance -= 150000
4. Link: transaction.balance_id = gopay_balance.id

Response:
✅ Berhasil Dicatat!
💸 Pengeluaran
💵 Rp 150.000
👛 Sisa saldo Gopay: Rp 2.850.000
```

---

### **Scenario 3: Dompet Tidak Ditemukan**

```
Input: "bayar makan 150rb pakai Mandiri"

Process:
1. Detect: "pakai Mandiri"
2. Find: Balance where account_name = 'Mandiri'
3. Result: NOT FOUND
4. Fallback: Use default wallet

Response:
✅ Berhasil Dicatat!
💸 Pengeluaran
💵 Rp 150.000
⚠️ Dompet Mandiri tidak ditemukan, menggunakan dompet default
👛 Sisa saldo: Rp 7.850.000
```

---

## 📊 **Cashflow Analysis**

### **Command: "cek cashflow"**

**Response Format:**
```
📊 Ringkasan Keuangan
📅 December 2025
━━━━━━━━━━━━━━━

📊 Cashflow Bulan Ini
💵 Pendapatan: Rp 6.000.000
💸 Pengeluaran: Rp 7.082.000
📉 Defisit: Rp 1.082.000
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
```

**Query:**
```php
// Income
$income = Transaction::where('tenant_id', $tenantId)
    ->where('type', 'income')
    ->whereMonth('transaction_date', now()->month)
    ->sum('amount');

// Expense
$expense = Transaction::where('tenant_id', $tenantId)
    ->where('type', 'expense')
    ->whereMonth('transaction_date', now()->month)
    ->sum('amount');

// Net
$net = $income - $expense;

// Top Categories
$topIncome = Transaction::where('tenant_id', $tenantId)
    ->where('type', 'income')
    ->whereMonth('transaction_date', now()->month)
    ->select('category_type', DB::raw('SUM(amount) as total'))
    ->groupBy('category_type')
    ->orderBy('total', 'desc')
    ->limit(3)
    ->get();
```

---

## 🛠️ **Implementation Checklist**

### **✅ Already Implemented:**
1. ✅ Balance detection from text ("pakai Gopay")
2. ✅ Transaction linking to balance
3. ✅ Balance update after transaction
4. ✅ Display balance name in response
5. ✅ Total balance calculation

### **🔧 Need to Implement:**
1. ⏳ Default wallet auto-creation on registration
2. ⏳ Fallback to default wallet if specified wallet not found
3. ⏳ Warning message when wallet not found
4. ⏳ Cashflow analysis command ("cek cashflow")
5. ⏳ Top categories breakdown

---

## 📝 **Database Schema**

### **Table: balances**
```sql
CREATE TABLE balances (
    id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED,
    account_name VARCHAR(255),          -- "Gopay", "BCA", "Cash"
    account_number VARCHAR(255),        -- Optional
    account_type ENUM('bank','cash','wallet','investment','other'),
    currency VARCHAR(3) DEFAULT 'IDR',
    balance DECIMAL(15,2) DEFAULT 0,    -- Current balance
    balance_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    is_default TINYINT(1) DEFAULT 0,    -- Only 1 per tenant
    metadata JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### **Table: transactions**
```sql
CREATE TABLE transactions (
    id BIGINT UNSIGNED PRIMARY KEY,
    tenant_id BIGINT UNSIGNED,
    balance_id BIGINT UNSIGNED,         -- Link to balance
    type ENUM('income','expense'),
    amount DECIMAL(15,2),
    category_type VARCHAR(255),
    description TEXT,
    transaction_date DATE,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    FOREIGN KEY (balance_id) REFERENCES balances(id)
);
```

---

## 🎯 **User Experience Goals**

### **For Beginner Users:**
- ✅ No need to understand wallets
- ✅ Everything goes to default wallet automatically
- ✅ Simple commands work out of the box

### **For Advanced Users:**
- ✅ Can create multiple wallets
- ✅ Can specify wallet per transaction
- ✅ Can track balance per wallet
- ✅ Can see total across all wallets

### **For All Users:**
- ✅ Consistent balance tracking
- ✅ No lost transactions
- ✅ Clear balance display
- ✅ Accurate cashflow analysis

---

## 🚀 **Next Steps**

1. **Test current implementation:**
   ```
   beli makan 50rb pakai gopay
   ```
   Expected: ✅ Sisa saldo Gopay: Rp X

2. **Implement cashflow command:**
   ```
   cek cashflow
   ```
   Expected: Full cashflow analysis

3. **Add default wallet creation:**
   - On user registration
   - On first transaction if not exists

4. **Add wallet not found handling:**
   - Fallback to default
   - Show warning message

---

**Status:** 🟢 Core functionality working
**Priority:** Implement cashflow analysis next
