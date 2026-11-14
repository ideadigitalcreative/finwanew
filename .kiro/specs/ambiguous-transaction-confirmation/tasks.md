# Implementation Plan: Ambiguous Transaction Confirmation

## Overview

Implementasi fitur deteksi dan konfirmasi transaksi ambigu melalui WhatsApp. Sistem mendeteksi pola yang bisa bermakna ganda (pemasukan/pengeluaran), mengirim prompt konfirmasi ke user, menyimpan state pending, dan memproses jawaban user untuk mencatat transaksi dengan tipe yang benar.

## Tasks

- [ ] 1. Add config and detection method
  - [ ] 1.1 Add `ambiguous_transaction_patterns` config entry to `config/finwa_category_rules.php`
    - Add the `'ambiguous_transaction_patterns'` key with `"gaji karyawan"` entry
    - Entry must have: `pattern`, `income_category_type` (`pendapatan_gaji`), `expense_category_type` (`pengeluaran_gaji_karyawan`)
    - _Requirements: 5.1, 5.2, 5.4_

  - [ ] 1.2 Add `detectAmbiguousPattern()` method to `TransactionExtractorService`
    - New `protected` method accepting `string $textLower`, returning `?array`
    - Read patterns from `config('finwa_category_rules.ambiguous_transaction_patterns')`
    - Validate each entry has all 3 required keys; skip invalid entries
    - Perform case-insensitive substring match (`str_contains`) against `$textLower`
    - Return matched pattern info array or `null`
    - _Requirements: 1.1, 1.2, 5.3, 5.5_

  - [ ]* 1.3 Write property test for ambiguous detection correctness
    - **Property 1: Ambiguous Detection Correctness**
    - **Validates: Requirements 1.1, 1.2, 1.4**
    - Generate random strings ± ambiguous patterns, random valid amounts
    - Verify non-null return when pattern present, null when absent

  - [ ]* 1.4 Write property test for config entry validation
    - **Property 5: Config Entry Validation and Resilience**
    - **Validates: Requirements 5.2, 5.5**
    - Generate random arrays with/without required keys
    - Verify entries with missing keys are skipped, valid entries are matched

- [ ] 2. Integrate ambiguous detection into extraction flow
  - [ ] 2.1 Modify `extractTransactionLocally()` to call `detectAmbiguousPattern()`
    - Insert AFTER hutang/piutang early return, BEFORE position-based income check
    - If `detectAmbiguousPattern()` returns non-null, build result with `type='ambiguous'`
    - Include `amount`, `description`, `transaction_date`, `pattern`, `income_category_type`, `expense_category_type`, `confidence_score=0.50`, `source='local_extraction'`
    - Skip normal income/expense detection when ambiguous match found
    - _Requirements: 1.3, 1.4_

- [ ] 3. Implement ambiguous transaction handling in ProcessIncomingMessage
  - [ ] 3.1 Add `handleAmbiguousTransaction()` method to `ProcessIncomingMessage`
    - Accept `array $ambiguousResult` and `ConversationContextService $contextService`
    - Build formatted confirmation prompt with original message, formatted amount (`Rp X.XXX`), two numbered options with category display names
    - Send via `$this->replyService->sendReply()`; if send fails, log error and return without storing
    - Store pending confirmation with `type='ambiguous'`, `retry_count=0`, both category types, amount, description, transaction_date
    - Add helper method `getCategoryDisplayName(string $categoryType): string` to resolve human-readable category name
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.6_

  - [ ]* 3.2 Write property test for confirmation prompt format
    - **Property 3: Confirmation Prompt Contains Required Fields**
    - **Validates: Requirements 2.2, 2.3**
    - Generate random amounts, descriptions, category names
    - Verify prompt contains all required fields

- [ ] 4. Checkpoint - Ensure detection and prompt sending work
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 5. Implement reply handling for ambiguous confirmations
  - [ ] 5.1 Add `handleAmbiguousReply()` method to `ProcessIncomingMessage`
    - Accept `string $reply`, `array $pending`, `ConversationContextService $contextService`
    - Normalize reply (trim, lowercase)
    - Check if message is a new transaction (has amount > 2 and not solely a keyword): clear pending and re-process as new message
    - Check valid income responses: "1", contains "pemasukan", contains "masuk" → process as income
    - Check valid expense responses: "2", contains "pengeluaran", contains "keluar" → process as expense
    - Handle invalid reply: if `retry_count >= 1`, cancel and clear; otherwise increment retry, resend prompt with hint
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 4.1, 4.2, 4.4_

  - [ ] 5.2 Add `processAmbiguousAsType()` helper method
    - Accept `array $pending`, `string $type`, `ConversationContextService $contextService`
    - Clear pending confirmation
    - Call `transactionService->handleTransaction()` with `force_type` and `force_category_type`
    - Send success message with type, formatted amount, and category name
    - _Requirements: 3.4, 3.5_

  - [ ]* 5.3 Write property test for reply-to-transaction-type mapping
    - **Property 2: Reply-to-Transaction-Type Mapping**
    - **Validates: Requirements 3.1, 3.2, 3.3**
    - Generate random strings with/without valid keywords, whitespace/casing variations
    - Verify deterministic mapping to income or expense

  - [ ]* 5.4 Write property test for invalid reply detection
    - **Property 4: Invalid Reply Detection**
    - **Validates: Requirements 4.1**
    - Generate random strings excluding valid keywords
    - Verify classification as invalid reply

- [ ] 6. Wire ambiguous pending check into message flow
  - [ ] 6.1 Add ambiguous pending check in `processTextMessage()`
    - Insert BEFORE existing "ya/iya/ok" confirmation check
    - Call `$contextService->getPendingConfirmation()`; if result has `type === 'ambiguous'`, route to `handleAmbiguousReply()`
    - _Requirements: 3.1, 4.3_

  - [ ] 6.2 Wire `handleAmbiguousTransaction()` call when extractor returns `type='ambiguous'`
    - In the transaction handling flow, check if extraction result type is `'ambiguous'`
    - Call `handleAmbiguousTransaction()` instead of normal `handleTransaction()`
    - Handle case where pending already exists (replace with new one per Req 2.5)
    - _Requirements: 2.1, 2.5_

- [ ] 7. Support force_type override in TransactionService
  - [ ] 7.1 Modify `TransactionService::handleTransaction()` to accept optional `$options` array parameter
    - When `force_type` is present in options, skip local extraction and AI processing
    - Use `force_type` as transaction type and `force_category_type` as category
    - Extract amount from the message text using existing `extractAmountFromText()`
    - Use `transaction_date` from options if provided, otherwise default to `now()`
    - Create transaction directly with forced values
    - _Requirements: 3.2, 3.3, 3.4_

- [ ] 8. Checkpoint - Ensure full flow works end-to-end
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 9. Write integration and unit tests
  - [ ]* 9.1 Write unit tests for ambiguous detection in `TransactionExtractorService`
    - Test "gaji karyawan 5000000" is detected as ambiguous
    - Test hutang/piutang messages bypass ambiguous detection (execution order)
    - Test messages without ambiguous patterns are not flagged
    - Test config entry with missing keys is skipped
    - _Requirements: 1.1, 1.2, 1.3, 5.5_

  - [ ]* 9.2 Write unit tests for `handleAmbiguousTransaction()` in ProcessIncomingMessage
    - Test prompt is sent and pending is stored on success
    - Test pending is NOT stored when sendReply throws exception
    - Test existing pending is replaced when new ambiguous detected
    - _Requirements: 2.1, 2.4, 2.5, 2.6_

  - [ ]* 9.3 Write unit tests for `handleAmbiguousReply()` in ProcessIncomingMessage
    - Test "1" routes to income with correct category
    - Test "2" routes to expense with correct category
    - Test "pemasukan", "masuk" route to income
    - Test "pengeluaran", "keluar" route to expense
    - Test invalid reply triggers retry prompt (first time)
    - Test invalid reply after retry cancels confirmation
    - Test new transaction message discards old pending
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 4.1, 4.2, 4.4_

  - [ ]* 9.4 Write unit test for `force_type` override in TransactionService
    - Test `handleTransaction()` with `force_type` creates transaction with forced type/category
    - _Requirements: 3.4_

- [ ] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Project uses PHPUnit with Pest syntax for tests
- `ConversationContextService` already has `storePendingConfirmation`, `getPendingConfirmation`, `clearPendingConfirmation` methods — reuse them with `type='ambiguous'` marker
- `TransactionService::handleTransaction()` already exists — task 7 adds an optional `$options` parameter
- After this feature is complete, remove "gaji karyawan" from `expense_detection_patterns` (handled by ambiguous flow instead)
- Property tests use Pest data providers with 100+ iterations per property

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2"] },
    { "id": 2, "tasks": ["1.3", "1.4", "2.1"] },
    { "id": 3, "tasks": ["3.1"] },
    { "id": 4, "tasks": ["3.2", "5.1"] },
    { "id": 5, "tasks": ["5.2", "5.3", "5.4"] },
    { "id": 6, "tasks": ["6.1", "6.2", "7.1"] },
    { "id": 7, "tasks": ["9.1", "9.2", "9.3", "9.4"] }
  ]
}
```
