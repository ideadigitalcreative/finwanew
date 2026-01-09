<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExportController extends Controller
{
    public function exportTransactions(Request $request)
    {
        // Get tenant from request (set by middleware) or session
        $tenantId = $request->tenant_id ?? session('current_tenant_id');
        
        if (!$tenantId && $request->user()) {
            // Fallback to user's first active tenant
            $firstTenant = $request->user()->activeTenants()->first();
            if ($firstTenant) {
                $tenantId = $firstTenant->id;
            }
        }
        
        if (!$tenantId) {
            abort(403, 'Tenant ID tidak ditemukan');
        }
        
        $tenant = Tenant::findOrFail($tenantId);

        $format = $request->input('format', 'excel'); // excel or pdf
        $request->validate([
            'format' => 'in:excel,pdf',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'type' => 'nullable|in:income,expense',
            'status' => 'nullable|in:confirmed,review,rejected'
        ]);

        $query = Transaction::where('tenant_id', $tenant->id)
            ->with(['category', 'message'])
            ->orderBy('transaction_date', 'desc');

        if ($request->has('start_date') && $request->start_date) {
            $query->where('transaction_date', '>=', $request->start_date);
        }

        if ($request->has('end_date') && $request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
        }

        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $transactions = $query->get();

        if ($format === 'pdf') {
            return $this->exportPdf($transactions, $tenant, $request);
        }

        return $this->exportExcel($transactions, $tenant);
    }

    protected function exportCsv($transactions, Tenant $tenant)
    {
        $filename = 'transactions_' . $tenant->id . '_' . Carbon::now()->format('Y-m-d_His') . '.csv';
        $path = 'exports/' . $filename;

        $headers = [
            'Tanggal',
            'Tipe',
            'Kategori',
            'Jumlah',
            'Sumber/Tujuan',
            'Deskripsi',
            'No. Referensi',
            'Status',
            'Confidence Score',
            'Dibuat Pada'
        ];

        $content = implode(',', $headers) . "\n";

        foreach ($transactions as $tx) {
            $row = [
                $tx->transaction_date->format('Y-m-d'),
                ucfirst($tx->type),
                $tx->category->name ?? '-',
                number_format($tx->amount, 2, ',', '.'),
                $tx->source ?? '-',
                $tx->description,
                $tx->reference_number ?? '-',
                ucfirst($tx->status),
                number_format($tx->confidence_score ?? 0, 2),
                $tx->created_at->format('Y-m-d H:i:s')
            ];

            // Escape commas and quotes
            $row = array_map(function ($field) {
                $field = str_replace('"', '""', $field);
                return '"' . $field . '"';
            }, $row);

            $content .= implode(',', $row) . "\n";
        }

        Storage::disk('local')->put($path, $content);

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    protected function exportExcel($transactions, Tenant $tenant)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = [
            'Tanggal',
            'Tipe',
            'Kategori',
            'Jumlah',
            'Sumber/Tujuan',
            'Deskripsi',
            'No. Referensi',
            'Status',
            'Confidence Score',
            'Dibuat Pada'
        ];

        $sheet->fromArray([$headers], null, 'A1');

        // Style header
        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E0E0E0']
            ]
        ];
        $sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

        // Data
        $row = 2;
        foreach ($transactions as $tx) {
            $sheet->setCellValue('A' . $row, $tx->transaction_date->format('Y-m-d'));
            $sheet->setCellValue('B' . $row, ucfirst($tx->type));
            $sheet->setCellValue('C' . $row, $tx->category->name ?? '-');
            $sheet->setCellValue('D' . $row, $tx->amount);
            $sheet->setCellValue('E' . $row, $tx->source ?? '-');
            $sheet->setCellValue('F' . $row, $tx->description);
            $sheet->setCellValue('G' . $row, $tx->reference_number ?? '-');
            $sheet->setCellValue('H' . $row, ucfirst($tx->status));
            $sheet->setCellValue('I' . $row, $tx->confidence_score ?? 0);
            $sheet->setCellValue('J' . $row, $tx->created_at->format('Y-m-d H:i:s'));

            // Format number
            $sheet->getStyle('D' . $row)->getNumberFormat()
                ->setFormatCode('#,##0.00');
            $sheet->getStyle('I' . $row)->getNumberFormat()
                ->setFormatCode('0.00');

            $row++;
        }

        // Auto size columns
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'transactions_' . $tenant->id . '_' . Carbon::now()->format('Y-m-d_His') . '.xlsx';
        $path = 'exports/' . $filename;

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        Storage::disk('local')->put($path, $content);
        unlink($tempFile);

        return Storage::disk('local')->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    protected function exportPdf($transactions, Tenant $tenant, Request $request)
    {
        // Configure Dompdf
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        
        $dompdf = new Dompdf($options);

        // Calculate totals
        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netAmount = $totalIncome - $totalExpense;

        // Build HTML
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            margin: 20px;
        }
        h1 {
            color: #1f2937;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header-info {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f3f4f6;
            border-radius: 5px;
        }
        .header-info p {
            margin: 5px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #3b82f6;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        .amount-income {
            color: #10b981;
            font-weight: bold;
        }
        .amount-expense {
            color: #ef4444;
            font-weight: bold;
        }
        .summary {
            margin-top: 30px;
            padding: 15px;
            background-color: #f3f4f6;
            border-radius: 5px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #d1d5db;
        }
        .summary-row:last-child {
            border-bottom: none;
            font-weight: bold;
            font-size: 12pt;
            margin-top: 10px;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 9pt;
            font-weight: bold;
        }
        .status-confirmed {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-review {
            background-color: #fef3c7;
            color: #92400e;
        }
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            color: #6b7280;
            font-size: 9pt;
        }
    </style>
</head>
<body>
    <h1>Laporan Transaksi Keuangan</h1>
    
    <div class="header-info">
        <p><strong>Tenant:</strong> ' . htmlspecialchars($tenant->name ?? 'N/A') . '</p>
        <p><strong>Periode:</strong> ' . 
            ($request->start_date ? date('d/m/Y', strtotime($request->start_date)) : 'Semua') . 
            ' - ' . 
            ($request->end_date ? date('d/m/Y', strtotime($request->end_date)) : 'Semua') . 
        '</p>
        <p><strong>Total Transaksi:</strong> ' . $transactions->count() . ' transaksi</p>
        <p><strong>Tanggal Export:</strong> ' . date('d/m/Y H:i:s') . '</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Tanggal</th>
                <th>Tipe</th>
                <th>Kategori</th>
                <th>Jumlah</th>
                <th>Sumber</th>
                <th>Deskripsi</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>';

        foreach ($transactions as $tx) {
            $amountClass = $tx->type === 'income' ? 'amount-income' : 'amount-expense';
            $amountPrefix = $tx->type === 'income' ? '+' : '-';
            $statusClass = 'status-' . $tx->status;
            
            $html .= '<tr>
                <td>' . $tx->transaction_date->format('d/m/Y') . '</td>
                <td>' . ucfirst($tx->type === 'income' ? 'Pemasukan' : 'Pengeluaran') . '</td>
                <td>' . htmlspecialchars($tx->category->name ?? '-') . '</td>
                <td class="' . $amountClass . '">' . $amountPrefix . ' Rp ' . number_format($tx->amount, 0, ',', '.') . '</td>
                <td>' . htmlspecialchars($tx->source ?? '-') . '</td>
                <td>' . htmlspecialchars($tx->description) . '</td>
                <td><span class="status-badge ' . $statusClass . '">' . ucfirst($tx->status) . '</span></td>
            </tr>';
        }

        $html .= '</tbody>
    </table>

    <div class="summary">
        <h3 style="margin-top: 0; margin-bottom: 15px;">Ringkasan</h3>
        <div class="summary-row">
            <span>Total Pemasukan:</span>
            <span class="amount-income">Rp ' . number_format($totalIncome, 0, ',', '.') . '</span>
        </div>
        <div class="summary-row">
            <span>Total Pengeluaran:</span>
            <span class="amount-expense">Rp ' . number_format($totalExpense, 0, ',', '.') . '</span>
        </div>
        <div class="summary-row">
            <span>Saldo Bersih:</span>
            <span class="' . ($netAmount >= 0 ? 'amount-income' : 'amount-expense') . '">Rp ' . number_format(abs($netAmount), 0, ',', '.') . '</span>
        </div>
    </div>

    <div class="footer">
        <p>Dibuat oleh Keuangan AI - ' . date('d/m/Y H:i:s') . '</p>
    </div>
</body>
</html>';

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = 'transactions_' . $tenant->id . '_' . Carbon::now()->format('Y-m-d_His') . '.pdf';
        
        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
