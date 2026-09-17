# Requirements Document

## Introduction

Fitur konfirmasi untuk transaksi ambigu yang tidak bisa ditentukan secara pasti apakah pemasukan atau pengeluaran. Contoh utama: pesan "Gaji karyawan 5000000" bisa berarti menerima gaji (pemasukan) atau membayar gaji karyawan (pengeluaran). Sistem akan mendeteksi pola-pola ambigu dan meminta klarifikasi dari user melalui WhatsApp sebelum mencatat transaksi.

## Glossary

- **Ambiguity_Detector**: Komponen di TransactionExtractorService yang mendeteksi apakah pesan mengandung pola ambigu (income keyword bertabrakan dengan expense pattern)
- **Ambiguous_Pattern**: Frasa dalam pesan transaksi yang secara simultan cocok dengan income_detection_keywords DAN expense_detection_patterns, sehingga tipe transaksi tidak bisa ditentukan otomatis
- **Confirmation_Prompt**: Pesan WhatsApp yang dikirim oleh sistem ke user untuk meminta klarifikasi tipe transaksi (pemasukan atau pengeluaran)
- **Pending_Ambiguous_Confirmation**: Data transaksi yang disimpan sementara di ConversationContext sambil menunggu jawaban user
- **User_Reply**: Jawaban user terhadap Confirmation_Prompt yang menentukan tipe transaksi akhir
- **TransactionExtractorService**: Service yang menangani ekstraksi transaksi dari teks secara lokal (tanpa AI)
- **ConversationContextService**: Service yang menyimpan state percakapan termasuk pending confirmation
- **ProcessIncomingMessage**: Job yang memproses pesan masuk dari WhatsApp dan melakukan routing ke handler yang sesuai

## Requirements

### Requirement 1: Deteksi Pola Ambigu

**User Story:** As a system developer, I want the system to detect ambiguous transaction patterns, so that ambiguous messages are not incorrectly categorized.

#### Acceptance Criteria

1. WHEN a message text contains an entry from the configured ambiguous_transaction_patterns list (case-insensitive substring match) AND the message contains a valid extractable amount (integer > 0 as determined by TransactionExtractorService::extractAmountFromText), THE Ambiguity_Detector SHALL flag the message as ambiguous
2. WHEN a message is flagged as ambiguous, THE Ambiguity_Detector SHALL return an array containing: the matched pattern string, the income interpretation (type: 'income', category_type from config), and the expense interpretation (type: 'expense', category_type from config)
3. THE Ambiguity_Detector SHALL check for ambiguous patterns AFTER hutang/piutang detection (which has its own early return) but BEFORE the standard income/expense detection logic runs in TransactionExtractorService
4. WHEN a message matches an ambiguous pattern, THE Ambiguity_Detector SHALL skip the normal income keyword position-based detection and expense override detection, and instead return a result with type 'ambiguous' to signal that confirmation is needed

### Requirement 2: Kirim Konfirmasi ke User

**User Story:** As a user, I want the system to ask me when a transaction is ambiguous, so that my transactions are recorded correctly.

#### Acceptance Criteria

1. WHEN the Ambiguity_Detector flags a message as ambiguous, THE ProcessIncomingMessage job SHALL send a Confirmation_Prompt to the user via WhatsApp within 30 seconds of receiving the message
2. THE Confirmation_Prompt SHALL display the original message text, the detected amount formatted as currency, and exactly two numbered options: option 1 for pemasukan (income) showing its category name, and option 2 for pengeluaran (expense) showing its category name
3. THE Confirmation_Prompt SHALL use numbered options format with the text "Ketik 1 untuk Pemasukan ([income_category_name])" and "Ketik 2 untuk Pengeluaran ([expense_category_name])"
4. WHEN the Confirmation_Prompt is sent, THE ProcessIncomingMessage job SHALL store a Pending_Ambiguous_Confirmation in ConversationContextService containing the original message, the detected amount, the income interpretation with its category_type, and the expense interpretation with its category_type
5. IF a Pending_Ambiguous_Confirmation already exists for the user when a new ambiguous message is detected, THEN THE ProcessIncomingMessage job SHALL replace the existing Pending_Ambiguous_Confirmation with the new one and send the new Confirmation_Prompt
6. IF sending the Confirmation_Prompt via WhatsApp fails, THEN THE ProcessIncomingMessage job SHALL log the failure and NOT store a Pending_Ambiguous_Confirmation in ConversationContextService

### Requirement 3: Proses Jawaban User

**User Story:** As a user, I want to reply with a simple answer to confirm my transaction type, so that the process is quick and easy.

#### Acceptance Criteria

1. WHEN a user sends a reply AND a Pending_Ambiguous_Confirmation exists in ConversationContext (within 5-minute expiry), THE ProcessIncomingMessage job SHALL normalize the reply by trimming whitespace and converting to lowercase, then check if it matches a valid confirmation response
2. WHEN the normalized User_Reply is "1" or contains the word "pemasukan" or "masuk" as a substring, THE ProcessIncomingMessage job SHALL process the transaction as income using the amount, description, and income category from the stored interpretation
3. WHEN the normalized User_Reply is "2" or contains the word "pengeluaran" or "keluar" as a substring, THE ProcessIncomingMessage job SHALL process the transaction as expense using the amount, description, and expense category from the stored interpretation
4. WHEN the transaction is processed based on User_Reply, THE system SHALL send a confirmation message to the user that includes the transaction type (pemasukan/pengeluaran), the recorded amount, and the assigned category name
5. WHEN the transaction is processed based on User_Reply, THE system SHALL clear the Pending_Ambiguous_Confirmation from ConversationContext

### Requirement 4: Handling Timeout dan Invalid Reply

**User Story:** As a user, I want clear guidance when my reply is not understood, so that I can still complete the transaction.

#### Acceptance Criteria

1. IF the User_Reply does not match any valid confirmation response (not "1", "2", "pemasukan", "pengeluaran", "masuk", or "keluar"), THEN THE ProcessIncomingMessage job SHALL resend the Confirmation_Prompt along with a hint message listing the valid response options ("1"/"pemasukan"/"masuk" or "2"/"pengeluaran"/"keluar")
2. IF the User_Reply does not match any valid confirmation response AND the Confirmation_Prompt has already been resent once (1 retry maximum), THEN THE ProcessIncomingMessage job SHALL discard the Pending_Ambiguous_Confirmation, send a message indicating the confirmation has been cancelled, and treat subsequent messages as new messages
3. IF the Pending_Ambiguous_Confirmation has expired (older than 5 minutes), THEN THE ProcessIncomingMessage job SHALL clear the Pending_Ambiguous_Confirmation from ConversationContext and treat the incoming message as a new message by routing it through the standard message processing pipeline
4. WHEN a Pending_Ambiguous_Confirmation exists AND the user sends a message that contains a numeric amount greater than 2 AND is not solely one of the valid confirmation keywords, THE ProcessIncomingMessage job SHALL discard the old Pending_Ambiguous_Confirmation from ConversationContext and process the new message through the standard message processing pipeline

### Requirement 5: Konfigurasi Ambiguous Patterns

**User Story:** As a system developer, I want ambiguous patterns to be configurable, so that new ambiguous cases can be added without code changes.

#### Acceptance Criteria

1. THE system SHALL store ambiguous patterns in the finwa_category_rules config file under the key 'ambiguous_transaction_patterns' as a PHP array
2. Each entry in ambiguous_transaction_patterns SHALL be an associative array defining exactly three keys: 'pattern' (a lowercase string of 2 to 100 characters used for case-insensitive substring matching against the message text), 'income_category_type' (a valid category_type string existing in the categories table), and 'expense_category_type' (a valid category_type string existing in the categories table)
3. WHEN a new ambiguous pattern entry is added to the ambiguous_transaction_patterns config array, THE Ambiguity_Detector SHALL match the new pattern using case-insensitive substring matching against the message text without requiring any code modifications or application restart
4. THE ambiguous_transaction_patterns config SHALL include at minimum: "gaji karyawan" with income_category_type "pendapatan_gaji" and expense_category_type "pengeluaran_gaji_karyawan"
5. IF a config entry in ambiguous_transaction_patterns is missing any of the three required keys ('pattern', 'income_category_type', or 'expense_category_type'), THEN THE Ambiguity_Detector SHALL skip that entry and continue processing remaining entries
