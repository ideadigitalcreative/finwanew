<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UserWhatsAppNumber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $tenant = Tenant::findOrFail($request->tenant_id);

        // Rentang periode: pakai filter tanggal dari request, default bulan berjalan
        $startDate = $request->filled('start_date')
            ? Carbon::parse($request->start_date)->startOfDay()
            : now()->startOfMonth();
        $endDate = $request->filled('end_date')
            ? Carbon::parse($request->end_date)->endOfDay()
            : now()->endOfMonth();

        $transactions = Transaction::where('tenant_id', $tenant->id)
            ->where('transaction_date', '>=', $startDate->format('Y-m-d'))
            ->where('transaction_date', '<=', $endDate->format('Y-m-d'))
            ->get();

        $totalIncome = $transactions->where('type', 'income')->sum('amount');
        $totalExpense = $transactions->where('type', 'expense')->sum('amount');
        $netCashflow = $totalIncome - $totalExpense;

        // Ringkasan arus kas per nomor WhatsApp (anggota) pada rentang terpilih
        $memberGroups = $transactions
            ->whereNotNull('user_whatsapp_number_id')
            ->groupBy('user_whatsapp_number_id');

        $memberSummary = collect();
        if ($memberGroups->isNotEmpty()) {
            $numbers = UserWhatsAppNumber::where('tenant_id', $tenant->id)
                ->whereIn('id', $memberGroups->keys())
                ->get(['id', 'whatsapp_number', 'name'])
                ->keyBy('id');

            $memberSummary = $memberGroups->map(function ($group, $numberId) use ($numbers) {
                $number = $numbers->get($numberId);

                return [
                    'number_id' => $numberId,
                    'name' => $number && $number->name ? $number->name : ($number ? $number->whatsapp_number : 'Nomor #'.$numberId),
                    'whatsapp_number' => $number?->whatsapp_number,
                    'total_income' => (float) $group->where('type', 'income')->sum('amount'),
                    'total_expense' => (float) $group->where('type', 'expense')->sum('amount'),
                    'count' => $group->count(),
                ];
            })
                ->sortByDesc(fn ($item) => $item['total_income'] + $item['total_expense'])
                ->values();
        }

        // Daftar nomor aktif untuk chip/filter pada halaman
        $whatsappNumbers = UserWhatsAppNumber::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('is_lid', false)
            ->whereRaw("whatsapp_number REGEXP '^62[0-9]{9,13}$'")
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['id', 'whatsapp_number', 'name', 'is_primary', 'is_lid']);

        return Inertia::render('Laporan/Index', [
            'tenantName' => $tenant->name,
            'summary' => [
                'totalIncome' => $totalIncome,
                'totalExpense' => $totalExpense,
                'netCashflow' => $netCashflow,
                'transactionCount' => $transactions->count(),
                'periodLabel' => $startDate->format('d/m/Y').' - '.$endDate->format('d/m/Y'),
            ],
            'memberSummary' => $memberSummary->values(),
            'whatsappNumbers' => $whatsappNumbers,
            'filters' => [
                'start_date' => $request->input('start_date', $startDate->format('Y-m-d')),
                'end_date' => $request->input('end_date', $endDate->format('Y-m-d')),
            ],
        ]);
    }
}
