<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportController extends Controller
{
    public function import(Request $request)
    {
        $tenant = Tenant::findOrFail($request->tenant_id);

        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240', // 10MB max
            'skip_header' => 'boolean'
        ]);

        $file = $request->file('file');
        $extension = $file->getClientOriginalExtension();
        $skipHeader = $request->input('skip_header', true);

        try {
            DB::beginTransaction();

            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if ($skipHeader && count($rows) > 0) {
                array_shift($rows); // Remove header
            }

            $imported = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + ($skipHeader ? 2 : 1); // +2 karena index 0 dan header dihapus

                try {
                    // Expected format: Tanggal, Tipe, Kategori, Jumlah, Sumber/Tujuan, Deskripsi, No. Referensi
                    if (count($row) < 4) {
                        $errors[] = "Baris {$rowNumber}: Data tidak lengkap";
                        continue;
                    }

                    $date = $this->parseDate($row[0] ?? null);
                    $type = strtolower(trim($row[1] ?? ''));
                    $categoryName = trim($row[2] ?? '');
                    $amount = $this->parseAmount($row[3] ?? 0);
                    $source = trim($row[4] ?? '');
                    $description = trim($row[5] ?? '');
                    $referenceNumber = trim($row[6] ?? '');

                    if (!$date) {
                        $errors[] = "Baris {$rowNumber}: Tanggal tidak valid";
                        continue;
                    }

                    if (!in_array($type, ['income', 'expense'])) {
                        $errors[] = "Baris {$rowNumber}: Tipe harus 'income' atau 'expense'";
                        continue;
                    }

                    if ($amount <= 0) {
                        $errors[] = "Baris {$rowNumber}: Jumlah harus lebih dari 0";
                        continue;
                    }

                    // Find category
                    $category = Category::where('tenant_id', $tenant->id)
                        ->where(function ($q) use ($categoryName) {
                            $q->where('name', $categoryName)
                              ->orWhere('slug', \Str::slug($categoryName));
                        })
                        ->first();

                    if (!$category) {
                        // Create category if not found
                        $category = Category::create([
                            'tenant_id' => $tenant->id,
                            'type' => $type === 'income' ? 'pendapatan_lainnya' : 'pengeluaran_lainnya',
                            'name' => $categoryName,
                            'slug' => \Str::slug($categoryName),
                            'is_system' => false
                        ]);
                    }

                    Transaction::create([
                        'tenant_id' => $tenant->id,
                        'category_id' => $category->id,
                        'type' => $type,
                        'amount' => $amount,
                        'transaction_date' => $date,
                        'source' => $source ?: null,
                        'description' => $description ?: 'Imported transaction',
                        'reference_number' => $referenceNumber ?: null,
                        'status' => 'confirmed',
                        'confidence_score' => 1.0 // Manual import = 100% confidence
                    ]);

                    $imported++;

                } catch (\Exception $e) {
                    $errors[] = "Baris {$rowNumber}: " . $e->getMessage();
                    Log::error('Import error', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                        'data' => $row
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Berhasil mengimpor {$imported} transaksi",
                'imported' => $imported,
                'errors' => $errors,
                'total_rows' => count($rows)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Gagal mengimpor: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function parseDate($value): ?\Carbon\Carbon
    {
        if (!$value) {
            return null;
        }

        // Try common date formats
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y'];

        foreach ($formats as $format) {
            try {
                return \Carbon\Carbon::createFromFormat($format, $value);
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try Excel date serial number
        if (is_numeric($value)) {
            try {
                return \Carbon\Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value));
            } catch (\Exception $e) {
                //
            }
        }

        return null;
    }

    protected function parseAmount($value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Remove currency symbols and formatting
        $value = preg_replace('/[^\d.,-]/', '', $value);
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/\.(?=.*\.)/', '', $value); // Remove all dots except last

        return (float) $value;
    }
}
