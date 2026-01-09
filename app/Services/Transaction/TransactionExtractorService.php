<?php

namespace App\Services\Transaction;

use App\Helpers\BatchTransactionHelper;
use Illuminate\Support\Facades\Log;

/**
 * TransactionExtractorService - Handles transaction extraction from text
 * 
 * REFACTORED FROM: App\Jobs\ProcessIncomingMessage
 * REFACTORING TYPE: Structural only (no logic changes)
 * 
 * Methods moved as-is without any modification to logic, conditionals,
 * or return values. Only namespace and class structure changed.
 */
class TransactionExtractorService
{
    /**
     * Extract amount from text line
     * Supports: 17.000, 17000, 17rb, 17ribu, 17k
     * NOW SUPPORTS BATCH: Detects multiple amounts and sums them
     * 
     * MOVED FROM: ProcessIncomingMessage::extractAmountFromText()
     * LINES: 7106-7115
     * MODIFICATION: None (structural move only)
     */
    public function extractAmountFromText(string $text): ?int
    {
        // Use BatchTransactionHelper for advanced batch detection
        return BatchTransactionHelper::extractAmount($text);
    }
    
    /**
     * Extract description from line (text before the amount)
     * 
     * MOVED FROM: ProcessIncomingMessage::extractDescriptionFromLine()
     * LINES: 7117-7128
     * MODIFICATION: None (structural move only)
     */
    public function extractDescriptionFromLine(string $line): string
    {
        // Remove amount patterns from end
        $description = preg_replace('/\s*\d+(?:[.,]\d+)?\s*(rb|ribu|k|jt|juta)?\s*$/i', '', $line);
        $description = preg_replace('/\s*\d{1,3}(?:\.\d{3})+\s*$/i', '', $description);
        $description = preg_replace('/\s*\d{3,}\s*$/', '', $description);
        
        return trim($description) ?: $line;
    }
    
    /**
     * Extract product name from description
     * 
     * MOVED FROM: ProcessIncomingMessage::extractProductName()
     * LINES: 5103-5127
     * MODIFICATION: None (structural move only)
     */
    public function extractProductName(?string $description): ?string
    {
        if (empty($description)) {
            return null;
        }
        
        // Remove common prefixes
        $desc = preg_replace('/^(Pembelian|Belanja|Beli|Purchase|Item|Produk)\s+/i', '', $description);
        
        // Extract first part (product name) before dash or " - "
        if (preg_match('/^([^-]+?)(?:\s*-\s*|\s+x\s+|\s*Qty|QTY|\s*\d+\s*Pcs)/i', $desc, $matches)) {
            return trim($matches[1]);
        }
        
        // If contains "x" or "Pcs", extract part before it
        if (preg_match('/^(.+?)(?:\s+x\s+|\s*\d+\s*Pcs)/i', $desc, $matches)) {
            return trim($matches[1]);
        }
        
        // Return first 50 chars if no pattern matches
        return mb_substr(trim($desc), 0, 50);
    }

    /**
     * Extract account name from message text (fallback if AI doesn't extract)
     * 
     * MOVED FROM: ProcessIncomingMessage::extractAccountNameFromMessage()
     * LINES: 5129-5198
     * MODIFICATION: None (structural move only)
     */
    public function extractAccountNameFromMessage(string $messageText): ?string
    {
        if (empty($messageText)) {
            return null;
        }

        $messageLower = strtolower($messageText);
        
        // Patterns to extract account name
        $patterns = [
            '/pakai\s+saldo\s+(?:bank\s+)?([a-z]+)/i',  // "pakai saldo BCA"
            '/pakai\s+([a-z]+)/i',  // "pakai Gopay"
            '/pake\s+([a-z]+)/i',   // "pake Gopay"
            '/dari\s+(?:bank\s+)?([a-z]+)/i',  // "dari BCA" or "dari bank BCA"
            '/ke\s+(?:bank\s+)?([a-z]+)/i',  // "ke BCA"
            '/via\s+(?:bank\s+)?([a-z]+)/i',  // "via BCA"
            '/saldo\s+(?:bank\s+)?([a-z]+)/i',  // "saldo BCA"
            '/dengan\s+saldo\s+(?:bank\s+)?([a-z]+)/i',  // "dengan saldo BCA"
            '/menggunakan\s+(?:bank\s+)?([a-z]+)/i',  // "menggunakan BCA"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $messageText, $matches)) {
                $potentialAccount = trim($matches[1]);
                // Validate it's a known bank/wallet name or reasonable length
                $knownAccounts = ['bca', 'mandiri', 'bni', 'bri', 'cash', 'tunai', 'gopay', 'ovo', 'dana', 'linkaja'];
                if (in_array(strtolower($potentialAccount), $knownAccounts) || (strlen($potentialAccount) >= 2 && strlen($potentialAccount) <= 15)) {
                    // Skip if it's a number
                    if (!is_numeric($potentialAccount)) {
                        Log::info('Account name extracted from message using regex (Laravel fallback)', [
                            'message_text' => mb_substr($messageText, 0, 100),
                            'account_name' => $potentialAccount,
                            'pattern' => $pattern
                        ]);
                        return $potentialAccount;
                    }
                }
            }
        }
        
        // Fallback: check for bank names directly
        $banks = ['bca', 'mandiri', 'bni', 'bri'];
        foreach ($banks as $bank) {
            // Check if bank name appears after account keywords
            if (preg_match('/(?:pakai\s+saldo|dari|via|ke|saldo)\s+(?:bank\s+)?' . $bank . '\b/i', $messageText)) {
                Log::info('Account name extracted from message using bank keyword (Laravel fallback)', [
                    'message_text' => mb_substr($messageText, 0, 100),
                    'account_name' => $bank
                ]);
                return $bank;
            }
        }
        
        // Last resort: check if bank name appears with transaction keywords
        foreach ($banks as $bank) {
            if (stripos($messageText, $bank) !== false && 
                preg_match('/\b(beli|bayar|gaji|bonus|transfer|pakai|dari|via)\b/i', $messageText)) {
                Log::info('Account name extracted from message using fallback pattern (Laravel fallback)', [
                    'message_text' => mb_substr($messageText, 0, 100),
                    'account_name' => $bank
                ]);
                return $bank;
            }
        }
        
        return null;
    }
    
    /**
     * Extract date from text (kemarin, minggu lalu, tgl 15, etc)
     * 
     * MOVED FROM: ProcessIncomingMessage::extractDateFromText()
     * LINES: 7663-7719
     * MODIFICATION: None (structural move only)
     */
    public function extractDateFromText(string $text): ?string
    {
        $textLower = strtolower($text);
        
        // Today keywords (explicitly mentioning today)
        if (str_contains($textLower, 'tadi') || str_contains($textLower, 'barusan') || 
            str_contains($textLower, 'baru aja') || str_contains($textLower, 'baru saja')) {
            return now()->toDateString(); // Today
        }
        
        // Relative dates
        if (str_contains($textLower, 'kemarin') || str_contains($textLower, 'yesterday') ||
            str_contains($textLower, 'kmrn') || str_contains($textLower, 'kmrin')) {
            return now()->subDay()->toDateString();
        }
        
        // Last night = kemarin malam = usually yesterday
        if (str_contains($textLower, 'semalem') || str_contains($textLower, 'semalam') ||
            str_contains($textLower, 'tadi malam') || str_contains($textLower, 'kemarin malam')) {
            // If it's currently morning (before 6 AM), "semalem" might mean last night (today)
            // Otherwise, it means yesterday night
            if (now()->hour < 6) {
                return now()->toDateString();
            }
            return now()->subDay()->toDateString();
        }
        
        if (str_contains($textLower, 'lusa') || str_contains($textLower, 'kemarin dulu')) {
            return now()->subDays(2)->toDateString();
        }
        
        if (preg_match('/(\d+)\s*hari\s*(lalu|yang\s*lalu)/i', $textLower, $matches)) {
            $days = (int) $matches[1];
            return now()->subDays($days)->toDateString();
        }
        
        if (str_contains($textLower, 'minggu lalu') || str_contains($textLower, 'pekan lalu')) {
            return now()->subWeek()->toDateString();
        }
        
        if (str_contains($textLower, 'bulan lalu')) {
            return now()->subMonth()->toDateString();
        }
        

        // Specific date patterns
        // "tgl 15", "tanggal 15"
        if (preg_match('/(?:tgl|tanggal)\s*(\d{1,2})(?!\d)/i', $textLower, $matches)) {
            $day = (int) $matches[1];
            $date = now()->setDay($day);
            // If the day is in the future, assume last month
            if ($date->isFuture()) {
                $date = $date->subMonth();
            }
            return $date->toDateString();
        }
        
        // "15/12" or "15-12" format
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/', $text, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = isset($matches[3]) ? (int) $matches[3] : now()->year;
            if ($year < 100) $year += 2000;
            
            try {
                return \Carbon\Carbon::createFromDate($year, $month, $day)->toDateString();
            } catch (\Exception $e) {
                // Invalid date
            }
        }
        
        return null; // Use current date as fallback
    }
    
    /**
     * Extract transaction from message text locally (without AI)
     * This handles simple messages like "Makan Pagi Hara Chicken 60rb"
     * 
     * @param string $messageText The message text
     * @return array|null Transaction data or null if extraction failed
     * 
     * MOVED FROM: ProcessIncomingMessage::extractTransactionLocally()
     * LINES: 7242-7661
     * MODIFICATION: None (structural move only)
     */
    public function extractTransactionLocally(string $messageText): ?array
    {
        $textLower = strtolower($messageText);
        
        // Extract amount using extractAmountFromText (supports batch transactions)
        $amount = $this->extractAmountFromText($messageText);
        
        // If no amount found, cannot process
        if (!$amount || $amount <= 0) {
            return null;
        }
        
        // Determine transaction type (income vs expense)
        // FIRST: Check for expense override patterns (these take priority)
        $expenseOverridePatterns = [
            'ambil gaji',      // "Alfi ambil gaji" = expense
            'ambil upah',      // "ambil upah" = expense
            'ambil honor',     // "ambil honor" = expense
            'sudah ambil',     // "sudah ambil gaji" = expense
            'ngambil gaji',    // "ngambil gaji" = expense
            'gaji karyawan',   // "gaji karyawan" = expense (paying salary)
            'upah karyawan',
            'honor karyawan',
            'bayar gaji',
            // Slang expense keywords
            'abis duit',       // "abis duit 50rb" = expense
            'habis duit',      // "habis duit 100rb" = expense
            'keluar duit',     // "keluar duit 30rb" = expense
            'abis uang',       // "abis uang 50rb" = expense
            'habis uang',      // "habis uang 100rb" = expense
            'keluar uang',     // "keluar uang 30rb" = expense
            'ngeluarin',       // "ngeluarin 50rb" = expense
            'keluarin',        // "keluarin 50rb" = expense
        ];
        $isExpenseOverride = false;
        foreach ($expenseOverridePatterns as $pattern) {
            if (str_contains($textLower, $pattern)) {
                $isExpenseOverride = true;
                break;
            }
        }
        
        // THEN: Check income keywords (if not overridden)
        // "dikasih" = receiving money from someone = INCOME
        $incomeKeywords = [
            'gaji', 'bonus', 'terima', 'dapat', 'dapet', 'pemasukan', 'pendapatan', 
            'honor', 'upah', 'transfer masuk', 'THR', 'uang masuk', 'duit masuk', 'masuk',
            'dikasih', 'dikasi', 'dari papi', 'dari papa', 'dari mama', 'dari mami',
            'dari ortu', 'dari orang tua', 'dari ayah', 'dari ibu', 'dari bapak',
            'hadiah', 'kado', 'angpao', 'amplop', 'sumbangan', 'kiriman',
            'dikirim', 'dikirimin', 'ditransfer', 'di transfer',
            // Slang income keywords
            'masuk duit', 'dapet duit', 'dapat duit', 'dapet transferan',
            'nyangkut', 'cair', 'pencairan'
        ];

        $isIncome = false;
        if (!$isExpenseOverride) {
            foreach ($incomeKeywords as $keyword) {
                if (str_contains($textLower, $keyword)) {
                    $isIncome = true;
                    break;
                }
            }
        }
        
        // Determine category from keywords
        // FIRST: Check for specific category overrides
        if ($isExpenseOverride && (str_contains($textLower, 'gaji') || str_contains($textLower, 'upah') || str_contains($textLower, 'honor'))) {
            // "ambil gaji" = paying employee salary, use gaji category
            $categoryType = 'pengeluaran_gaji';
        } else {
            $categoryType = $isIncome ? 'pendapatan_lainnya' : 'pengeluaran_lainnya';
        }
        
        // Expense categories mapping (including informal/slang Indonesian)
        $expenseCategoryMap = [
            // PRIORITY 0: Health keywords with "beli" prefix (must be checked FIRST)
            'beli obat' => 'pengeluaran_kesehatan',
            'bayar obat' => 'pengeluaran_kesehatan',
            'beli vitamin' => 'pengeluaran_kesehatan',
            'bayar dokter' => 'pengeluaran_kesehatan',
            'biaya dokter' => 'pengeluaran_kesehatan',
            'biaya rs' => 'pengeluaran_kesehatan',
            'biaya rumah sakit' => 'pengeluaran_kesehatan',
            'bayar rumah sakit' => 'pengeluaran_kesehatan',
            'bayar apotek' => 'pengeluaran_kesehatan',
            
            // PRIORITY 0.5: Pulsa/Digital (before generic "beli")
            'beli pulsa' => 'pengeluaran_pulsa_token',
            'isi pulsa' => 'pengeluaran_pulsa_token',
            'bayar pulsa' => 'pengeluaran_pulsa_token',
            'beli kuota' => 'pengeluaran_pulsa_token',
            'isi kuota' => 'pengeluaran_pulsa_token',
            'beli paket' => 'pengeluaran_pulsa_token',
            'isi paket' => 'pengeluaran_pulsa_token',
            'top up gopay' => 'pengeluaran_lainnya',
            'topup gopay' => 'pengeluaran_lainnya',
            'isi gopay' => 'pengeluaran_lainnya',
            'top up ovo' => 'pengeluaran_lainnya',
            'topup ovo' => 'pengeluaran_lainnya',
            'isi ovo' => 'pengeluaran_lainnya',
            'top up dana' => 'pengeluaran_lainnya',
            'topup dana' => 'pengeluaran_lainnya',
            'isi dana' => 'pengeluaran_lainnya',
            'top up shopeepay' => 'pengeluaran_lainnya',
            'isi shopeepay' => 'pengeluaran_lainnya',
            
            // PRIORITY 0.6: Groceries (before generic "beli")
            'beli sayur' => 'pengeluaran_makanan',
            'belanja sayur' => 'pengeluaran_makanan',
            'beli bahan' => 'pengeluaran_makanan',
            'belanja bahan' => 'pengeluaran_makanan',
            'beli bumbu' => 'pengeluaran_makanan',
            'belanja dapur' => 'pengeluaran_makanan',
            'belanja bulanan' => 'pengeluaran_belanja',
            'grocery' => 'pengeluaran_makanan',
            'groceries' => 'pengeluaran_makanan',
            
            // PRIORITY 0.7: Entertainment tickets (before generic "beli")
            'beli tiket' => 'pengeluaran_hiburan',
            'bayar tiket' => 'pengeluaran_hiburan',
            'tiket nonton' => 'pengeluaran_hiburan',
            'tiket bioskop' => 'pengeluaran_hiburan',
            'tiket konser' => 'pengeluaran_hiburan',
            
            // PRIORITY 0.8: Personal items (before generic "beli")
            'beli rokok' => 'pengeluaran_lainnya',
            'rokok' => 'pengeluaran_lainnya',
            'beli baju' => 'pengeluaran_belanja',
            'beli sepatu' => 'pengeluaran_belanja',
            'beli tas' => 'pengeluaran_belanja',
            'beli jam' => 'pengeluaran_belanja',
            'beli hp' => 'pengeluaran_belanja',
            'beli laptop' => 'pengeluaran_belanja',
            
            // PRIORITY 0.85: Housing/Rent
            'bayar kos' => 'pengeluaran_hunian',
            'bayar kost' => 'pengeluaran_hunian',
            'bayar kontrakan' => 'pengeluaran_hunian',
            'bayar sewa' => 'pengeluaran_hunian',
            'sewa rumah' => 'pengeluaran_hunian',
            'sewa kos' => 'pengeluaran_hunian',
            'uang kos' => 'pengeluaran_hunian',
            'uang kost' => 'pengeluaran_hunian',
            
            // PRIORITY 0.9: Education
            'bayar spp' => 'pengeluaran_pendidikan',
            'uang spp' => 'pengeluaran_pendidikan',
            'bayar les' => 'pengeluaran_pendidikan',
            'uang les' => 'pengeluaran_pendidikan',
            'bayar kursus' => 'pengeluaran_pendidikan',
            'uang kursus' => 'pengeluaran_pendidikan',
            'bayar sekolah' => 'pengeluaran_pendidikan',
            'uang sekolah' => 'pengeluaran_pendidikan',
            'bayar kuliah' => 'pengeluaran_pendidikan',
            'uang kuliah' => 'pengeluaran_pendidikan',
            'beli buku' => 'pengeluaran_pendidikan',
            
            // PRIORITY 0.95: Beauty/Personal Care
            'beli skincare' => 'pengeluaran_lainnya',
            'beli makeup' => 'pengeluaran_lainnya',
            'beli kosmetik' => 'pengeluaran_lainnya',
            'potong rambut' => 'pengeluaran_lainnya',
            'pangkas rambut' => 'pengeluaran_lainnya',
            'cukur' => 'pengeluaran_lainnya',

            // PRIORITY 1: Entertainment/Games (must be checked before generic "beli/belanja")
            'game' => 'pengeluaran_hiburan',
            'steam' => 'pengeluaran_hiburan',
            'topup game' => 'pengeluaran_hiburan',
            'voucher game' => 'pengeluaran_hiburan',
            'nonton' => 'pengeluaran_hiburan',
            'bioskop' => 'pengeluaran_hiburan',
            'cinema' => 'pengeluaran_hiburan',

            'netflix' => 'pengeluaran_hiburan',
            'spotify' => 'pengeluaran_hiburan',
            'karaoke' => 'pengeluaran_hiburan',
            
            // Makanan & Minuman - formal & informal
            'makan' => 'pengeluaran_makanan',
            'mkn' => 'pengeluaran_makanan',
            'maem' => 'pengeluaran_makanan',
            'mamam' => 'pengeluaran_makanan',
            'sarapan' => 'pengeluaran_makanan',
            'breakfast' => 'pengeluaran_makanan',
            'lunch' => 'pengeluaran_makanan',
            'dinner' => 'pengeluaran_makanan',
            'nyemil' => 'pengeluaran_makanan',
            'ngemil' => 'pengeluaran_makanan',
            'jajan' => 'pengeluaran_makanan',
            'snack' => 'pengeluaran_makanan',
            'cemilan' => 'pengeluaran_makanan',
            
            // Jenis makanan
            'chicken' => 'pengeluaran_makanan',
            'ayam' => 'pengeluaran_makanan',
            'geprek' => 'pengeluaran_makanan',
            'nasi' => 'pengeluaran_makanan',
            'mie' => 'pengeluaran_makanan',
            'indomie' => 'pengeluaran_makanan',
            'bakso' => 'pengeluaran_makanan',
            'sate' => 'pengeluaran_makanan',
            'pizza' => 'pengeluaran_makanan',
            'burger' => 'pengeluaran_makanan',
            'soto' => 'pengeluaran_makanan',
            'rawon' => 'pengeluaran_makanan',
            'rendang' => 'pengeluaran_makanan',
            'gudeg' => 'pengeluaran_makanan',
            'pecel' => 'pengeluaran_makanan',
            'gado' => 'pengeluaran_makanan',
            'ketoprak' => 'pengeluaran_makanan',
            'bubur' => 'pengeluaran_makanan',
            'lontong' => 'pengeluaran_makanan',
            'martabak' => 'pengeluaran_makanan',
            'roti' => 'pengeluaran_makanan',
            'donat' => 'pengeluaran_makanan',
            'kue' => 'pengeluaran_makanan',
            'gorengan' => 'pengeluaran_makanan',
            'bakwan' => 'pengeluaran_makanan',
            'siomay' => 'pengeluaran_makanan',
            'batagor' => 'pengeluaran_makanan',
            'pempek' => 'pengeluaran_makanan',
            'cilok' => 'pengeluaran_makanan',
            'cireng' => 'pengeluaran_makanan',
            'nasgor' => 'pengeluaran_makanan',
            'nasgep' => 'pengeluaran_makanan',
            
            // Restoran/Brand
            'mcd' => 'pengeluaran_makanan',
            'mcdonalds' => 'pengeluaran_makanan',
            'kfc' => 'pengeluaran_makanan',
            'hokben' => 'pengeluaran_makanan',
            'yoshinoya' => 'pengeluaran_makanan',
            'solaria' => 'pengeluaran_makanan',
            'warteg' => 'pengeluaran_makanan',
            'warung' => 'pengeluaran_makanan',
            'resto' => 'pengeluaran_makanan',
            'cafe' => 'pengeluaran_makanan',
            'kantin' => 'pengeluaran_makanan',
            'foodcourt' => 'pengeluaran_makanan',
            'pizza hut' => 'pengeluaran_makanan',
            'phd' => 'pengeluaran_makanan',
            'dominos' => 'pengeluaran_makanan',
            'jco' => 'pengeluaran_makanan',
            'dunkin' => 'pengeluaran_makanan',
            
            // Minuman & Kopi
            'ngopi' => 'pengeluaran_makanan',
            'kopi' => 'pengeluaran_makanan',
            'coffee' => 'pengeluaran_makanan',
            'starbucks' => 'pengeluaran_makanan',
            'sbux' => 'pengeluaran_makanan',
            'sbx' => 'pengeluaran_makanan',
            'janji jiwa' => 'pengeluaran_makanan',
            'kopi kenangan' => 'pengeluaran_makanan',
            'fore' => 'pengeluaran_makanan',
            'tomoro' => 'pengeluaran_makanan',
            'mixue' => 'pengeluaran_makanan',
            'chatime' => 'pengeluaran_makanan',
            'xiboba' => 'pengeluaran_makanan',
            'boba' => 'pengeluaran_makanan',
            'teh' => 'pengeluaran_makanan',
            'es teh' => 'pengeluaran_makanan',
            'jus' => 'pengeluaran_makanan',
            'susu' => 'pengeluaran_makanan',
            'minum' => 'pengeluaran_makanan',
            
            // Transport
            'bensin' => 'pengeluaran_transport',
            'pertamax' => 'pengeluaran_transport',
            'pertalite' => 'pengeluaran_transport',
            'solar' => 'pengeluaran_transport',
            'parkir' => 'pengeluaran_transport',
            'transport' => 'pengeluaran_transport',
            'ongkos' => 'pengeluaran_transport',
            'ongkir' => 'pengeluaran_transport',
            'grab' => 'pengeluaran_transport',
            'gojek' => 'pengeluaran_transport',
            'ojol' => 'pengeluaran_transport',
            'ojek' => 'pengeluaran_transport',
            'maxim' => 'pengeluaran_transport',
            'indriver' => 'pengeluaran_transport',
            'taxi' => 'pengeluaran_transport',
            'taksi' => 'pengeluaran_transport',
            'bluebird' => 'pengeluaran_transport',
            'angkot' => 'pengeluaran_transport',
            'busway' => 'pengeluaran_transport',
            'transjakarta' => 'pengeluaran_transport',
            'mrt' => 'pengeluaran_transport',
            'lrt' => 'pengeluaran_transport',
            'krl' => 'pengeluaran_transport',
            'kereta' => 'pengeluaran_transport',
            'toll' => 'pengeluaran_transport',
            'tol' => 'pengeluaran_transport',
            'etoll' => 'pengeluaran_transport',
            'tiket' => 'pengeluaran_transport',
            'pesawat' => 'pengeluaran_transport',
            'bus' => 'pengeluaran_transport',
            'travel' => 'pengeluaran_transport',
            
            // Belanja
            'belanja' => 'pengeluaran_belanja',
            'beli' => 'pengeluaran_belanja',
            'borong' => 'pengeluaran_belanja',
            'checkout' => 'pengeluaran_belanja',
            'order' => 'pengeluaran_belanja',
            'pesen' => 'pengeluaran_belanja',
            'shopee' => 'pengeluaran_belanja',
            'tokped' => 'pengeluaran_belanja',
            'tokopedia' => 'pengeluaran_belanja',
            'lazada' => 'pengeluaran_belanja',
            'bukalapak' => 'pengeluaran_belanja',
            'blibli' => 'pengeluaran_belanja',
            'olshop' => 'pengeluaran_belanja',
            'alfamart' => 'pengeluaran_belanja',
            'indomaret' => 'pengeluaran_belanja',
            'alfamidi' => 'pengeluaran_belanja',
            'superindo' => 'pengeluaran_belanja',
            'hypermart' => 'pengeluaran_belanja',
            
            // Tagihan
            'listrik' => 'pengeluaran_tagihan',
            'pln' => 'pengeluaran_tagihan',
            'air' => 'pengeluaran_tagihan',
            'pdam' => 'pengeluaran_tagihan',
            'internet' => 'pengeluaran_tagihan',
            'wifi' => 'pengeluaran_tagihan',
            'indihome' => 'pengeluaran_tagihan',
            'biznet' => 'pengeluaran_tagihan',
            'firstmedia' => 'pengeluaran_tagihan',
            'bpjs' => 'pengeluaran_tagihan',
            'pajak' => 'pengeluaran_tagihan',
            'pbb' => 'pengeluaran_tagihan',
            'stnk' => 'pengeluaran_tagihan',
            
            // Pulsa & Token
            'pulsa' => 'pengeluaran_pulsa_token',
            'kuota' => 'pengeluaran_pulsa_token',
            'paket data' => 'pengeluaran_pulsa_token',
            'top up' => 'pengeluaran_pulsa_token',
            'topup' => 'pengeluaran_pulsa_token',
            'isi pulsa' => 'pengeluaran_pulsa_token',
            'isi kuota' => 'pengeluaran_pulsa_token',
            
            // Hiburan
            'nonton' => 'pengeluaran_hiburan',
            'bioskop' => 'pengeluaran_hiburan',
            'cinema' => 'pengeluaran_hiburan',
            'xxi' => 'pengeluaran_hiburan',
            'cgv' => 'pengeluaran_hiburan',
            'cinepolis' => 'pengeluaran_hiburan',
            'netflix' => 'pengeluaran_hiburan',
            'spotify' => 'pengeluaran_hiburan',
            'youtube' => 'pengeluaran_hiburan',
            'disney' => 'pengeluaran_hiburan',
            'vidio' => 'pengeluaran_hiburan',
            'viu' => 'pengeluaran_hiburan',
            'game' => 'pengeluaran_hiburan',
            'steam' => 'pengeluaran_hiburan',
            'playstation' => 'pengeluaran_hiburan',
            'mobile legend' => 'pengeluaran_hiburan',
            'ml' => 'pengeluaran_hiburan',
            'ff' => 'pengeluaran_hiburan',
            'pubg' => 'pengeluaran_hiburan',
            'valorant' => 'pengeluaran_hiburan',
            
            // Digital Products & Subscriptions
            'gpt' => 'pengeluaran_langganan',
            'chatgpt' => 'pengeluaran_langganan',
            'openai' => 'pengeluaran_langganan',
            'gemini' => 'pengeluaran_langganan',
            'claude' => 'pengeluaran_langganan',
            'api' => 'pengeluaran_langganan',
            'copilot' => 'pengeluaran_langganan',
            'cursor' => 'pengeluaran_langganan',
            'canva' => 'pengeluaran_langganan',
            'figma' => 'pengeluaran_langganan',
            'adobe' => 'pengeluaran_langganan',
            'premium' => 'pengeluaran_langganan',
            'prem' => 'pengeluaran_langganan',
            'langganan' => 'pengeluaran_langganan',
            'subscription' => 'pengeluaran_langganan',
            'subs' => 'pengeluaran_langganan',
            'apk' => 'pengeluaran_langganan',
            'apk prem' => 'pengeluaran_langganan',
            'app' => 'pengeluaran_langganan',
            'aplikasi' => 'pengeluaran_langganan',
            'software' => 'pengeluaran_langganan',
            'license' => 'pengeluaran_langganan',
            'lisensi' => 'pengeluaran_langganan',
            'cloud' => 'pengeluaran_langganan',
            'hosting' => 'pengeluaran_langganan',
            'domain' => 'pengeluaran_langganan',
            'server' => 'pengeluaran_langganan',
            'vps' => 'pengeluaran_langganan',
            
            // Hunian
            'sewa' => 'pengeluaran_hunian',
            'kos' => 'pengeluaran_hunian',
            'kost' => 'pengeluaran_hunian',
            'kontrakan' => 'pengeluaran_hunian',
            'kontrak' => 'pengeluaran_hunian',
            'ngontrak' => 'pengeluaran_hunian',
            
            // Cicilan & Pinjaman
            'cicilan' => 'pengeluaran_pinjaman',
            'kredit' => 'pengeluaran_pinjaman',
            'angsuran' => 'pengeluaran_pinjaman',
            
            // Keluarga
            'ngasih' => 'pengeluaran_keluarga',
            'kasih' => 'pengeluaran_keluarga',
            'kirimin' => 'pengeluaran_keluarga',
            'ortu' => 'pengeluaran_keluarga',
            'orang tua' => 'pengeluaran_keluarga',
            
            // Donasi
            'sedekah' => 'pengeluaran_donasi',
            'infaq' => 'pengeluaran_donasi',
            'infak' => 'pengeluaran_donasi',
            'zakat' => 'pengeluaran_donasi',
            'sumbangan' => 'pengeluaran_donasi',
            'donasi' => 'pengeluaran_donasi',
            'amal' => 'pengeluaran_donasi',
            
            // Kesehatan
            'obat' => 'pengeluaran_kesehatan',
            'apotek' => 'pengeluaran_kesehatan',
            'dokter' => 'pengeluaran_kesehatan',
            'rumah sakit' => 'pengeluaran_kesehatan',
            'rs' => 'pengeluaran_kesehatan',
            'puskesmas' => 'pengeluaran_kesehatan',
            'klinik' => 'pengeluaran_kesehatan',
            'lab' => 'pengeluaran_kesehatan',
            
            // Kecantikan
            'salon' => 'pengeluaran_lainnya',
            'barbershop' => 'pengeluaran_lainnya',
            'potong rambut' => 'pengeluaran_lainnya',
            'cukur' => 'pengeluaran_lainnya',
            'facial' => 'pengeluaran_lainnya',
            'spa' => 'pengeluaran_lainnya',
            'pijat' => 'pengeluaran_lainnya',
            'skincare' => 'pengeluaran_lainnya',
            'makeup' => 'pengeluaran_lainnya',
            'kosmetik' => 'pengeluaran_lainnya',
            
            // Pendidikan
            'spp' => 'pengeluaran_pendidikan',
            'uang sekolah' => 'pengeluaran_pendidikan',
            'uang kuliah' => 'pengeluaran_pendidikan',
            'buku' => 'pengeluaran_pendidikan',
            'atk' => 'pengeluaran_pendidikan',
            'alat tulis' => 'pengeluaran_pendidikan',
            'kursus' => 'pengeluaran_pendidikan',
            'les' => 'pengeluaran_pendidikan',
            'bimbel' => 'pengeluaran_pendidikan',
        ];
        
        // Income categories mapping
        $incomeCategoryMap = [
            'gaji' => 'pendapatan_gaji',
            'honor' => 'pendapatan_gaji',
            'upah' => 'pendapatan_gaji',
            'bayaran' => 'pendapatan_gaji',
            'penghasilan' => 'pendapatan_gaji',
            'income' => 'pendapatan_gaji',
            'bonus' => 'pendapatan_bonus',
            'thr' => 'pendapatan_bonus',
            'komisi' => 'pendapatan_bonus',
            'fee' => 'pendapatan_bonus',
            'hadiah' => 'pendapatan_bonus',
            'transfer' => 'pendapatan_lainnya',
            'tf' => 'pendapatan_lainnya',
            'trf' => 'pendapatan_lainnya',
            'terima' => 'pendapatan_lainnya',
            'dapat' => 'pendapatan_lainnya',
            'dapet' => 'pendapatan_lainnya',
            'dikasih' => 'pendapatan_lainnya',
            'dikasi' => 'pendapatan_lainnya',
            'kiriman' => 'pendapatan_lainnya',
            'dikirim' => 'pendapatan_lainnya',
            'angpao' => 'pendapatan_lainnya',
            'amplop' => 'pendapatan_lainnya',
            'papi' => 'pendapatan_lainnya',
            'papa' => 'pendapatan_lainnya',
            'mama' => 'pendapatan_lainnya',
            'mami' => 'pendapatan_lainnya',
            'ortu' => 'pendapatan_lainnya',
            'ayah' => 'pendapatan_lainnya',
            'ibu' => 'pendapatan_lainnya',
            'bapak' => 'pendapatan_lainnya',
        ];
        
        if ($isIncome) {
            foreach ($incomeCategoryMap as $keyword => $category) {
                if (str_contains($textLower, strtolower($keyword))) {
                    $categoryType = $category;
                    break;
                }
            }
        } else {
            foreach ($expenseCategoryMap as $keyword => $category) {
                if (str_contains($textLower, $keyword)) {
                    $categoryType = $category;
                    break;
                }
            }
        }
        
        // Extract date from text (kemarin, minggu lalu, tgl 15, etc)
        $transactionDate = $this->extractDateFromText($messageText) ?? now()->toDateString();
        
        // Build transaction data
        return [
            'type' => $isIncome ? 'income' : 'expense',
            'amount' => $amount,
            'category_type' => $categoryType,
            'description' => $messageText, // Use original message as description
            'transaction_date' => $transactionDate,
            'confidence_score' => 0.85,
            'source' => 'local_extraction',
            'account_name' => null,
            'merchant' => null,
        ];

    }
}
