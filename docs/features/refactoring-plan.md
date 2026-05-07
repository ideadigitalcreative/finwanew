# Refactoring Plan: ProcessIncomingMessage.php

This plan tracks the decomposition of `ProcessIncomingMessage.php` into dedicated service classes.

## Phase 1: Service Extraction

### Core Messaging & Replies
- [x] **MessageReplyService**
  - `sendReply`
  - `sendDocument`
  - `handleFinWaSpecialIntent`

### FAQ Handling
- [x] **FAQService**
  - `checkAndHandleFAQ`
  - All `getFAQ*` getters

### Transaction Management
- [x] **TransactionService**
  - `handleTransaction`
  - `createTransaction`
  - `createTransactionFromData`
  - `processTransferWithClarification`
  - `handleDeleteTransaction`
  - `handleViewTransactions`
  - `handleEditTransaction`
  - `handleDeleteAllTransactions`
  - `handleEditWithContext`
- [x] **TransactionConfirmationService**
  - `sendTransactionConfirmation`
  - `sendReceiptConfirmation` (Added)
- [x] **TransactionExtractorService**
  - `extractTransactionLocally`
  - `extractAmountFromText`
  - `extractDescriptionFromLine`
  - `extractDateFromText`
  - `extractProductName`
  - `extractAccountNameFromMessage`
- [x] **BatchTransactionService**
  - `handleBatchTransactions`
  - `isBatchTransactionFormat`
  - `parseDateFromHeader`

### Organization & Categories
- [x] **CategoryManagerService**
  - `createCategoriesForTenant`
- [x] **CategoryMappingService**
  - `mapFinwaKategoriToCategoryType`
  - `determineCategoryFromDescription`
  - `determineCategoryFromText`

### Wallet & Budget
- [x] **WalletCommandService**
  - `handleSetWalletBalance`
  - `handleTransferToWallet`
  - `handleExpenseFromWallet`
  - `handleAddWallet`
  - `handleListWallets`
  - `handleDeleteWallet`
  - `extractWalletName`
  - `determineAccountType`
  - `handleCheckBalance`
- [x] **BudgetAlertService**
  - `checkBudgetAlert`

### OCR & Image Processing
- [x] **ReceiptParserService** (NEW)
  - `optimizeOcrTextForAI`
  - `extractTotalFromOcrText`
  - `extractStoreNameFromOcrText`
  - `parseReceiptDate`
- [x] **OcrProcessorService** (NEW)
  - `processOcrJobResult`
  - `processImageWithFinWaAI`
  - `handleFinWaAIOcrResult`
  - `handleOcrFailure`
  - `createOcrJob`
  - `dispatchToOcrWorker`
  - `dispatchToOcrWorkerWithDelay`
  - `extractFilePathFromUrl`
  - `isPublicDiskUrl`

### Pending Services
- [x] **ReminderCommandService** (COMPLETED)
  - `handleSetReminder`
  - `handleDeleteReminder`  
  - `handleListReminders`
  - `handleEnableDailyReminder`
  - `handleDisableDailyReminder`
- [x] **ReportCommandService** (COMPLETED)
  - `handleExportPdf`
  - `sendDocument`
- [x] **BudgetCommandService** (NEW - COMPLETED)
  - `handleCheckBudget`
  - `handleSetBudget`
  - `handleAddBudget`
- [x] **AccountCommandService** (NEW - COMPLETED)
  - `handleResetAccount`
  - `handleDeleteAllTransactions`
- [x] **AnalysisCommandService** (NEW - COMPLETED)
  - `handleCheckStatistics`
  - `handleCheckStatisticsWithAI`
- [ ] **RegistrationService** (Optional, complex logic)
  - LID registration logic currently inline.

## Phase 2: Integration
- [x] Inject services into `ProcessIncomingMessage`.
- [x] Replace inline method calls with service calls (Transaction, OCR, Batch, Reply).
- [x] Remove moved methods from `ProcessIncomingMessage.php` (Core Transaction & OCR Logic).
- [x] Refactor Reminder module to `ReminderCommandService`.
- [x] Refactor Report/PDF module to `ReportCommandService`.
- [x] Refactor Budget module to `BudgetCommandService`.
- [x] Refactor Account/Reset module to `AccountCommandService`.
- [x] Refactor Analysis/Statistics module to `AnalysisCommandService`.
- [x] Clean up duplicate method implementations from `ProcessIncomingMessage.php`.
- [ ] Refactor remaining modules (Registration).

## Summary of Changes (Dec 31, 2024)

### New Service Classes Created:
1. `app/Services/Reminder/ReminderCommandService.php` - Reminder commands
2. `app/Services/Report/ReportCommandService.php` - PDF report generation & sending
3. `app/Services/Budget/BudgetCommandService.php` - Budget management commands
4. `app/Services/Account/AccountCommandService.php` - Account reset/delete commands
5. `app/Services/Analysis/AnalysisCommandService.php` - Statistics & AI analysis

### ProcessIncomingMessage.php Improvements:
- **Reduced from ~7200 lines to ~2569 lines** (~64% reduction)
- Removed excessive `Log::info` and `Log::debug` calls for cleaner code
- All Budget, Reminder, Report, Account, and Analysis methods now delegate to services
- All Wallet methods (`handleAddWallet`, `handleListWallets`, `handleDeleteWallet`, `handleCheckBalance`) delegate to `WalletCommandService`
- All FAQ methods (`checkAndHandleFAQ`, `getFAQ*`) delegate to `FAQService`
- Transaction methods (`handleDeleteTransaction`, `handleViewTransactions`, `handleEditTransaction`) delegate to `TransactionService`
- Removed duplicate method implementations (isBatchTransactionFormat, handleBatchTransactions, etc.)
- Cleaner separation of concerns

### Services Now Integrated:
- `ReminderCommandService` - Reminders
- `ReportCommandService` - PDF Reports  
- `BudgetCommandService` - Budget management
- `AccountCommandService` - Account reset/delete
- `AnalysisCommandService` - Statistics & AI
- `WalletCommandService` - Wallet management & balance check
- `FAQService` - FAQ handling
- `TransactionService` - Transaction CRUD operations
- `TransactionExtractorService` - Transaction parsing & extraction (delegated)
- `AchievementService` - Gamification & streaks (delegated)
- `ReceiptParserService` - OCR parsing (delegated)



