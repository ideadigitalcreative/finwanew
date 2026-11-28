# Implementation Plan

## Overview

Bugfix untuk `TransactionExtractorService::extractTransactionLocally()` yang salah mengkategorikan pesan berawalan income keyword sebagai expense. Fix menambahkan position-based income check sebelum expense override block.

## Tasks

- [ ] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - Income Keyword di Awal Pesan Salah Dikategorikan Expense
  - **CRITICAL**: This test MUST FAIL on unfixed code - failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior - it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate the bug exists
  - **Scoped PBT Approach**: Scope the property to concrete failing cases where message starts with income keyword AND contains expense pattern
  - Create test file: `tests/Unit/Services/Transaction/IncomeDetectionPriorityTest.php`
  - Instantiate `TransactionExtractorService` and call `extractTransactionLocally()` with bug condition inputs
  - Test case 1: `"Pemasukan bayar wifi mardani 150000"` → ASSERT `result['type'] === 'income'`
  - Test case 2: `"Masuk 150rb mardani bayar wifi"` → ASSERT `result['type'] === 'income'`
  - Test case 3: `"Gaji karyawan 5000000"` → ASSERT `result['type'] === 'income'` (contains expense pattern "gaji karyawan")
  - Test case 4: `"Terima pembayaran customer 200000"` → ASSERT `result['type'] === 'income'` (contains "bayar" via "pembayaran")
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct - it proves the bug exists because expense override is processed before income keyword position check)
  - Document counterexamples: `result['type']` returns `'expense'` instead of expected `'income'`
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 1.1, 1.2, 1.3_

- [ ] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Non-Income-Start Inputs Tetap Identik
  - **IMPORTANT**: Follow observation-first methodology
  - **IMPORTANT**: Write these tests BEFORE implementing the fix
  - Observe behavior on UNFIXED code for non-buggy inputs (messages NOT starting with income keyword)
  - Add preservation test methods to `tests/Unit/Services/Transaction/IncomeDetectionPriorityTest.php`
  - Observe: `"beli obat 50000"` → returns expense on unfixed code (expense pattern match)
  - Observe: `"bayar wifi 100000"` → returns expense on unfixed code (bayar standalone)
  - Observe: `"makan siang 25000"` → returns expense on unfixed code (default expense, no income keyword)
  - Observe: `"dapat bonus 1000000"` → returns income on unfixed code (income keyword "dapat" matches via word boundary)
  - Observe: `"masukan data 50000"` → returns expense on unfixed code (NOT matching "masuk" because followed by letter, not space/digit/end)
  - Write property-based test: for all non-bug-condition inputs, assert behavior matches observed outputs
  - Test expense patterns still override when NO income keyword at start: `"bayar kos 500000"` → expense
  - Test default expense: messages without any keyword markers → expense
  - Test hutang/piutang early return not affected: `"hutang ke budi 100000"` → still processed as hutang
  - Verify all tests PASS on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [ ] 3. Fix for income detection priority when message starts with income keyword

  - [ ] 3.1 Implement the fix in TransactionExtractorService::extractTransactionLocally()
    - Add position-based income keyword check BEFORE the expense override block
    - Loop through `config('finwa_category_rules.income_detection_keywords')` array
    - For each keyword, check `str_starts_with($textLower, $keyword)`
    - Word boundary handling: after keyword match, next character must be space, digit, or end-of-string
    - If match found: set `$isIncome = true` and `break`
    - Wrap existing expense override block in `if (!$isIncome)` to skip when income already detected from position
    - Also wrap existing income keyword loop in `if (!$isIncome)` to avoid redundant processing
    - _Bug_Condition: isBugCondition(input) where input starts with income keyword AND contains expense pattern_
    - _Expected_Behavior: result.type = 'income' AND result.category_type = 'pendapatan_lainnya' for all bug condition inputs_
    - _Preservation: All inputs NOT starting with income keyword produce identical output to pre-fix code_
    - _Requirements: 2.1, 2.2, 2.3, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

  - [ ] 3.2 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - Income Keyword di Awal Pesan Diprioritaskan
    - **IMPORTANT**: Re-run the SAME test from task 1 - do NOT write a new test
    - The test from task 1 encodes the expected behavior (type === 'income' for bug condition inputs)
    - When this test passes, it confirms the expected behavior is satisfied
    - Run: `php artisan test --filter=IncomeDetectionPriorityTest` (or the bug condition test method)
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - _Requirements: 2.1, 2.2, 2.3_

  - [ ] 3.3 Verify preservation tests still pass
    - **Property 2: Preservation** - Non-Income-Start Inputs Tetap Identik
    - **IMPORTANT**: Re-run the SAME tests from task 2 - do NOT write new tests
    - Run preservation test methods from task 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - Confirm all preservation tests still pass after fix (no regressions)
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [ ] 4. Checkpoint - Ensure all tests pass
  - Run full test suite: `php artisan test --filter=IncomeDetectionPriorityTest`
  - Verify all bug condition tests pass (income keyword at start → type = 'income')
  - Verify all preservation tests pass (non-bug-condition inputs unchanged)
  - Verify no other existing tests are broken: `php artisan test`
  - Ensure all tests pass, ask the user if questions arise.

## Task Dependency Graph

```json
{
  "waves": [
    ["1", "2"],
    ["3.1"],
    ["3.2", "3.3"],
    ["4"]
  ]
}
```

## Notes

- Test file location: `tests/Unit/Services/Transaction/IncomeDetectionPriorityTest.php`
- Service under test: `app/Services/Transaction/TransactionExtractorService.php`
- Config with keywords: `config/finwa_category_rules.php` (income_detection_keywords, expense_detection_patterns)
- Tasks 1 and 2 MUST be completed before task 3 (write tests on unfixed code first)
- Task 1 is expected to FAIL on unfixed code (confirms bug exists)
- Task 2 is expected to PASS on unfixed code (confirms baseline preservation behavior)
