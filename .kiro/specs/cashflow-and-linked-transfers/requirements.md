# Requirements Document

## Introduction

Dokumen ini mendefinisikan kebutuhan untuk dua fitur prioritas sedang: **Cashflow Service** (menghidupkan model Cashflow yang sudah ada dengan service layer, artisan command, dan dashboard endpoint) dan **Transfer Internal Linked** (menghubungkan pasangan transaksi debit-kredit pada transfer antar dompet agar tidak terjadi double counting di laporan keuangan).

## Glossary

- **Cashflow_Service**: Service class yang bertanggung jawab menghitung total pemasukan, pengeluaran, dan net cashflow untuk periode tertentu berdasarkan data transaksi yang terkonfirmasi
- **Cashflow_Report**: Record pada tabel `cashflows` yang berisi ringkasan keuangan untuk satu periode (daily/weekly/monthly)
- **Tenant**: Entitas pemilik data (organisasi atau pengguna) dalam sistem multi-tenant
- **Transaction**: Record transaksi keuangan (income/expense/debit_internal/kredit_internal) yang tercatat di tabel `transactions`
- **Internal_Transfer**: Perpindahan dana antar dompet milik tenant yang sama, terdiri dari satu transaksi debit (sumber) dan satu transaksi kredit (tujuan)
- **Linked_Transaction**: Transaksi yang memiliki referensi ke pasangannya melalui kolom `linked_transaction_id`, menandakan bahwa kedua transaksi merupakan bagian dari satu Internal_Transfer
- **Balance**: Saldo dompet/rekening milik tenant, tersimpan di tabel `balances`
- **WhatsApp_Notification_Service**: Komponen yang mengirim pesan ringkasan keuangan bulanan ke nomor WhatsApp tenant
- **Dashboard_Endpoint**: API endpoint yang menyajikan data cashflow ke frontend (Inertia.js + Vue 3)
- **Period_Type**: Jenis periode laporan cashflow: `daily`, `weekly`, atau `monthly`
- **Transfer_Reference**: Kode unik (format `TRF-XXXXXXXX`) yang mengidentifikasi satu operasi transfer internal

## Requirements

### Requirement 1: Cashflow Calculation Service

**User Story:** As a tenant, I want the system to automatically calculate my cashflow summary for any given period, so that I can see accurate income vs expense data without manual computation.

#### Acceptance Criteria

1. WHEN a cashflow calculation is requested for a specific Tenant and Period_Type, THE Cashflow_Service SHALL aggregate all confirmed Transaction records within the period boundaries and produce total_income, total_expense, and net_cashflow values
2. WHEN calculating cashflow totals, THE Cashflow_Service SHALL exclude transactions with type `debit_internal` and `kredit_internal` from total_income and total_expense to prevent double counting from Internal_Transfer operations
3. THE Cashflow_Service SHALL generate a category breakdown in the `breakdown` field, grouping expense and income amounts by category_id
4. WHEN a Cashflow_Report already exists for the same Tenant, period_start, and period_end, THE Cashflow_Service SHALL update the existing record instead of creating a duplicate
5. IF no confirmed transactions exist for the requested period, THEN THE Cashflow_Service SHALL create a Cashflow_Report with zero values for total_income, total_expense, and net_cashflow

### Requirement 2: Cashflow Artisan Command

**User Story:** As a system administrator, I want an artisan command to generate cashflow reports on a schedule, so that reports are always up to date without manual intervention.

#### Acceptance Criteria

1. THE Artisan_Command `cashflow:generate` SHALL accept a `--period` option with valid values `daily`, `weekly`, and `monthly`
2. WHEN the command is executed with `--period=daily`, THE Artisan_Command SHALL calculate cashflow for the current day (00:00 to 23:59)
3. WHEN the command is executed with `--period=weekly`, THE Artisan_Command SHALL calculate cashflow from Monday 00:00 to Sunday 23:59 of the current week
4. WHEN the command is executed with `--period=monthly`, THE Artisan_Command SHALL calculate cashflow from the first day to the last day of the current month
5. WHEN the command is executed without a `--tenant` option, THE Artisan_Command SHALL generate reports for all active tenants
6. WHEN the command is executed with a `--tenant` option, THE Artisan_Command SHALL generate a report only for the specified Tenant
7. IF the Tenant specified by `--tenant` does not exist, THEN THE Artisan_Command SHALL output an error message and return exit code 1

### Requirement 3: Dashboard Cashflow Endpoint

**User Story:** As a tenant, I want to see "Pemasukan vs Pengeluaran bulan ini" on my dashboard, so that I can quickly understand my financial health at a glance.

#### Acceptance Criteria

1. WHEN the Dashboard_Endpoint is accessed, THE Dashboard_Endpoint SHALL return cashflow data for the current month including total_income, total_expense, net_cashflow, income_change percentage, expense_change percentage, and health_status
2. WHEN calculating dashboard cashflow, THE Dashboard_Endpoint SHALL exclude Internal_Transfer transactions (type `debit_internal` and `kredit_internal`) from income and expense totals
3. THE Dashboard_Endpoint SHALL include a comparison with the previous month period showing prev_total_income, prev_total_expense, and prev_net_cashflow values
4. THE Dashboard_Endpoint SHALL calculate health_status as `growing` when net_cashflow exceeds previous period by more than 10%, `declining` when net_cashflow falls below previous period by more than 10%, and `stable` otherwise

### Requirement 4: Monthly WhatsApp Summary Notification

**User Story:** As a tenant, I want to receive a monthly financial summary via WhatsApp, so that I stay informed about my spending habits without logging into the app.

#### Acceptance Criteria

1. WHEN the first day of a new month arrives, THE WhatsApp_Notification_Service SHALL send a summary message to each Tenant that has an active WhatsApp channel
2. THE WhatsApp_Notification_Service SHALL include in the summary message: total_income, total_expense, net_cashflow, top 3 expense categories with amounts, and comparison percentage with the previous month
3. IF a Tenant does not have an active WhatsApp channel configured, THEN THE WhatsApp_Notification_Service SHALL skip that Tenant without error
4. IF the WhatsApp message delivery fails, THEN THE WhatsApp_Notification_Service SHALL log the failure and retry up to 3 times with exponential backoff

### Requirement 5: Linked Transaction Column Migration

**User Story:** As a developer, I want a `linked_transaction_id` column on the transactions table, so that paired internal transfer transactions can reference each other directly.

#### Acceptance Criteria

1. THE Migration SHALL add a nullable `linked_transaction_id` column of type `unsignedBigInteger` to the `transactions` table
2. THE Migration SHALL add a foreign key constraint on `linked_transaction_id` referencing `transactions.id` with `SET NULL` on delete
3. THE Migration SHALL add an index on `linked_transaction_id` for query performance
4. WHEN the migration is rolled back, THE Migration SHALL drop the `linked_transaction_id` column and its constraints

### Requirement 6: Atomic Internal Transfer Operation

**User Story:** As a tenant, I want transfers between my wallets to be recorded as one atomic operation with both sides linked, so that my financial reports are accurate and I can undo the transfer cleanly.

#### Acceptance Criteria

1. WHEN a transfer between wallets is executed, THE WalletCommandService SHALL create both debit and credit Transaction records within a single database transaction (atomicity)
2. WHEN both transactions are created successfully, THE WalletCommandService SHALL set the `linked_transaction_id` on the debit Transaction to reference the credit Transaction id, and vice versa
3. IF either the debit or credit Transaction creation fails, THEN THE WalletCommandService SHALL rollback both transactions and return an error message to the user
4. THE Transaction model SHALL provide a `linkedTransaction` relationship method that returns the linked counterpart Transaction via `linked_transaction_id`
5. WHEN an existing Internal_Transfer pair is created via `transfer_ref` metadata (legacy records), THE Cashflow_Service SHALL still recognize them as internal transfers by checking the transaction type (`debit_internal`/`kredit_internal`)

### Requirement 7: Undo Transfer Operation

**User Story:** As a tenant, I want to undo a wallet transfer so that both the debit and credit sides are reversed automatically, keeping my balances consistent.

#### Acceptance Criteria

1. WHEN an undo transfer command is received for a Transaction that has a `linked_transaction_id`, THE WalletCommandService SHALL delete or reverse both the source and linked Transaction in a single database transaction
2. WHEN both transactions are reversed, THE WalletCommandService SHALL restore the Balance of both the source and destination wallets to their pre-transfer amounts
3. IF the linked Transaction has already been deleted or does not exist, THEN THE WalletCommandService SHALL reverse only the remaining Transaction and adjust its associated Balance
4. WHEN an undo is successful, THE WalletCommandService SHALL send a confirmation message to the user indicating both sides of the transfer were reversed

### Requirement 8: Report Exclusion of Internal Transfers

**User Story:** As a tenant, I want internal transfers excluded from my income/expense totals in reports, so that moving money between my own wallets does not inflate my financial summaries.

#### Acceptance Criteria

1. WHEN generating any financial report or cashflow calculation, THE Cashflow_Service SHALL exclude all Transaction records with type `debit_internal` or `kredit_internal` from total_income and total_expense sums
2. WHEN generating category breakdown, THE Cashflow_Service SHALL list internal transfer totals in a separate `internal_transfers` section rather than mixing them with income or expense categories
3. THE Dashboard_Endpoint SHALL display internal transfer volume as a separate informational metric, not included in the main income/expense/net_cashflow values
4. WHEN a Transaction has a non-null `linked_transaction_id`, THE Cashflow_Service SHALL treat that Transaction as an internal transfer regardless of its category_type value

### Requirement 9: Historical Cashflow Data for Analytics

**User Story:** As a tenant, I want access to historical cashflow data across multiple periods, so that I can analyze long-term financial trends and patterns.

#### Acceptance Criteria

1. THE Dashboard_Endpoint SHALL provide a chart data endpoint that returns monthly income, expense, and net cashflow for the last 6 months
2. WHEN a historical Cashflow_Report does not exist for a past period, THE Cashflow_Service SHALL calculate it on-demand from Transaction records and persist the result
3. THE Cashflow_Service SHALL support querying Cashflow_Report records filtered by Tenant, Period_Type, and date range
