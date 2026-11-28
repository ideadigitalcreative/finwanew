# Implementation Plan: Cashflow & Linked Transfers

## Overview

This plan implements two interconnected features: a CashflowService that extracts and centralizes cashflow calculation logic from DashboardController, and linked internal transfers via a `linked_transaction_id` self-referential FK on the transactions table. Tasks are ordered so foundational changes (migration, service) come first, then consumers (commands, controller, WhatsApp), and finally tests.

## Tasks

- [x] 1. Database migration for linked_transaction_id
  - [x] 1.1 Create migration to add `linked_transaction_id` to transactions table
    - Create `database/migrations/2026_06_10_000001_add_linked_transaction_id_to_transactions_table.php`
    - Add nullable `unsignedBigInteger` column `linked_transaction_id` after `metadata`
    - Add foreign key referencing `transactions.id` with `SET NULL` on delete
    - Add index on `linked_transaction_id`
    - Implement `down()` to drop column and constraints
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

- [x] 2. Transaction model update
  - [x] 2.1 Add `linkedTransaction` relationship and fillable field to Transaction model
    - Add `'linked_transaction_id'` to `$fillable` array in `app/Models/Transaction.php`
    - Add `linkedTransaction(): BelongsTo` relationship method referencing `Transaction::class` via `linked_transaction_id`
    - _Requirements: 6.4_

- [x] 3. CashflowService core implementation
  - [x] 3.1 Create CashflowService with `calculateForPeriod()` method
    - Create `app/Services/CashflowService.php`
    - Implement period boundary calculation (daily/weekly/monthly) using Carbon
    - Query confirmed transactions excluding `debit_internal` and `kredit_internal` types
    - Also exclude transactions with non-null `linked_transaction_id`
    - Calculate `total_income`, `total_expense`, `net_cashflow`
    - Generate category breakdown JSON with separate `internal_transfers` section
    - Use `Cashflow::updateOrCreate()` keyed on `(tenant_id, period_start, period_end)`
    - Return zero-value Cashflow record when no transactions exist
    - Throw `\InvalidArgumentException` for invalid period_type
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 8.1, 8.2, 8.4_

  - [x] 3.2 Add `getDashboardData()` and `getChartData()` methods to CashflowService
    - Implement `getDashboardData(int $tenantId): array` returning current month cashflow, previous month comparison, percentage changes (capped ±999%), and `health_status`
    - Implement health_status logic: `growing` when net > prev * 1.1, `declining` when net < prev * 0.9, `stable` otherwise
    - Include `internal_transfer_volume` in response
    - Implement `getChartData(int $tenantId, int $months = 6): array` returning monthly data for last N months
    - Calculate on-demand and persist if historical records don't exist
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 9.1, 9.2_

  - [x] 3.3 Add `query()` and `formatMonthlySummary()` methods to CashflowService
    - Implement `query(int $tenantId, ?string $periodType, ?Carbon $from, ?Carbon $to): Collection` for filtered queries
    - Implement `formatMonthlySummary(int $tenantId, Carbon $month): string` returning WhatsApp-formatted message with total_income, total_expense, net_cashflow, top 3 expense categories, and comparison percentage
    - _Requirements: 4.2, 9.3_

  - [ ]* 3.4 Write property test for cashflow aggregation correctness
    - **Property 1: Cashflow aggregation correctness**
    - **Validates: Requirements 1.1**

  - [ ]* 3.5 Write property test for internal transfer exclusion
    - **Property 2: Internal transfer exclusion from totals**
    - **Validates: Requirements 1.2, 3.2, 8.1, 8.4**

  - [ ]* 3.6 Write property test for category breakdown correctness
    - **Property 3: Category breakdown correctness with separate internal transfers section**
    - **Validates: Requirements 1.3, 8.2**

  - [ ]* 3.7 Write property test for idempotence
    - **Property 4: Cashflow calculation idempotence**
    - **Validates: Requirements 1.4**

  - [ ]* 3.8 Write property test for period boundary correctness
    - **Property 5: Period boundary correctness**
    - **Validates: Requirements 2.2, 2.3, 2.4**

  - [ ]* 3.9 Write property test for health status classification
    - **Property 6: Health status classification**
    - **Validates: Requirements 3.4**

- [~] 4. Checkpoint - Ensure core service tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. WalletCommandService atomic transfer with linking
  - [~] 5.1 Refactor `handleTransferBetweenWallets()` to use DB::transaction and link both sides
    - Wrap transfer logic in `DB::transaction()` closure
    - After creating debit and credit transactions, update each with the other's ID via `linked_transaction_id`
    - Keep existing `transfer_ref` metadata for backward compatibility
    - Rollback on any failure, send error reply
    - _Requirements: 6.1, 6.2, 6.3, 6.5_

  - [~] 5.2 Implement `handleUndoTransfer(Transaction $transaction)` method
    - Load the transaction's `linkedTransaction` relationship
    - Wrap in `DB::transaction()`
    - Reverse both wallet balances to pre-transfer amounts
    - Delete both transactions
    - If linked transaction already deleted, reverse only the remaining one
    - Send confirmation message to user
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

  - [ ]* 5.3 Write property test for transfer atomicity and bidirectional linking
    - **Property 7: Transfer atomicity and bidirectional linking**
    - **Validates: Requirements 6.1, 6.2**

  - [ ]* 5.4 Write property test for transfer-then-undo round-trip
    - **Property 8: Transfer-then-undo restores original state (round-trip)**
    - **Validates: Requirements 7.1, 7.2**

- [ ] 6. Artisan command: cashflow:generate
  - [~] 6.1 Create `GenerateCashflow` artisan command
    - Create `app/Console/Commands/GenerateCashflow.php`
    - Signature: `cashflow:generate {--period=monthly} {--tenant=}`
    - Without `--tenant`: iterate all active tenants, call `CashflowService::calculateForPeriod()`
    - With `--tenant=ID`: process single tenant, exit code 1 if not found
    - Output progress info and error counts
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7_

- [ ] 7. Artisan command: cashflow:monthly-summary
  - [~] 7.1 Create `SendMonthlyCashflowSummary` artisan command
    - Create `app/Console/Commands/SendMonthlyCashflowSummary.php`
    - Signature: `cashflow:monthly-summary {--test} {--limit=0}`
    - Iterate active tenants with WhatsApp channels
    - Use `CashflowService::formatMonthlySummary()` for message content
    - Retry up to 3 times with exponential backoff (5s, 15s, 45s) on delivery failure
    - Skip tenants without active WhatsApp channels silently
    - Follow existing pattern from `SendMidMonthCashflow` command
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

  - [ ]* 7.2 Write property test for WhatsApp summary message completeness
    - **Property 9: WhatsApp summary message completeness**
    - **Validates: Requirements 4.2**

- [ ] 8. Dashboard controller integration
  - [~] 8.1 Refactor DashboardController to use CashflowService
    - Replace inline cashflow calculation logic (~lines 30-90 of `index()`) with `CashflowService::getDashboardData()`
    - Replace `getChartData()` private method with `CashflowService::getChartData()`
    - Add `internal_transfer_volume` to the Inertia response payload
    - Inject `CashflowService` via constructor or method injection
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 8.3, 9.1_

- [ ] 9. Schedule artisan commands
  - [~] 9.1 Register scheduled commands in `routes/console.php`
    - Schedule `cashflow:generate --period=daily` to run daily
    - Schedule `cashflow:generate --period=monthly` to run on 1st of each month
    - Schedule `cashflow:monthly-summary` to run on 1st of each month at 08:00
    - _Requirements: 4.1_

- [~] 10. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 11. Integration and unit tests
  - [ ]* 11.1 Write unit tests for CashflowService
    - Create `tests/Unit/Services/CashflowServiceTest.php`
    - Test specific transaction sets with exact numeric outputs
    - Test period boundary edge cases (Feb 28/29, months with 30/31 days)
    - Test health_status threshold boundary values
    - Test zero-transaction period returns zero values
    - Test legacy `transfer_ref` transactions still excluded by type
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 3.4_

  - [ ]* 11.2 Write feature test for GenerateCashflow command
    - Create `tests/Feature/Commands/GenerateCashflowCommandTest.php`
    - Test with seeded data, verify Cashflow records created
    - Test `--tenant` not found returns exit code 1
    - Test invalid `--period` value handling
    - _Requirements: 2.1, 2.5, 2.6, 2.7_

  - [ ]* 11.3 Write feature test for Dashboard cashflow endpoint
    - Create `tests/Feature/Http/DashboardCashflowTest.php`
    - HTTP test verifying full response payload shape
    - Verify internal transfers excluded from totals
    - Verify `internal_transfer_volume` present
    - _Requirements: 3.1, 3.2, 8.3_

  - [ ]* 11.4 Write unit test for WalletCommandService transfer linking
    - Create `tests/Unit/Services/Wallet/WalletCommandServiceTransferTest.php`
    - Test bidirectional linking after transfer
    - Test rollback on failure
    - Test undo with and without linked transaction
    - _Requirements: 6.1, 6.2, 6.3, 7.1, 7.2, 7.3_

- [~] 12. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests use `innmind/black-box` library as specified in the design document
- The existing `transfer_ref` metadata pattern is preserved for backward compatibility with legacy transfers
- CashflowService is the central dependency — most other tasks consume it

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["3.1"] },
    { "id": 3, "tasks": ["3.2", "3.3"] },
    { "id": 4, "tasks": ["3.4", "3.5", "3.6", "3.7", "3.8", "3.9", "5.1"] },
    { "id": 5, "tasks": ["5.2", "6.1", "7.1", "8.1"] },
    { "id": 6, "tasks": ["5.3", "5.4", "7.2", "9.1"] },
    { "id": 7, "tasks": ["11.1", "11.2", "11.3", "11.4"] }
  ]
}
```
