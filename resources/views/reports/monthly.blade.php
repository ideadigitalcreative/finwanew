<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Keuangan - {{ $month }}</title>
    <style>
        :root {
            --fw-primary: #0d9488;
            --fw-primary-dark: #0f766e;
            --fw-bg: #f5f7fa;
            --fw-text: #151a1f;
            --fw-muted: #6b7280;
            --fw-border: #e5e8ec;
            --fw-card: #ffffff;
            --fw-soft: #f2f4f6;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: var(--fw-text); line-height: 1.4; background: var(--fw-bg); }
        .container { padding: 20px; background: var(--fw-card); border: 1px solid var(--fw-border); border-radius: 10px; }
        .header { text-align: center; margin-bottom: 18px; padding-bottom: 10px; border-bottom: 2px solid var(--fw-primary); }
        .header h1 { font-size: 20px; color: var(--fw-primary); margin-bottom: 5px; }
        .header p { color: var(--fw-muted); font-size: 10px; }
        
        .summary { margin-bottom: 20px; }
        .summary-row { margin-bottom: 8px; padding: 10px; background: var(--fw-soft); border: 1px solid var(--fw-border); border-radius: 8px; }
        .summary-row.income { border-left: 4px solid #22c55e; }
        .summary-row.expense { border-left: 4px solid #ef4444; }
        .summary-row.cashflow { border-left: 4px solid var(--fw-primary); }
        .summary-label { font-size: 10px; color: var(--fw-muted); text-transform: uppercase; }
        .summary-value { font-size: 16px; font-weight: bold; }
        
        .section { margin-bottom: 20px; }
        .section-title { font-size: 12px; font-weight: bold; color: var(--fw-primary); margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px solid var(--fw-border); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid var(--fw-border); font-size: 10px; }
        th { background: var(--fw-soft); font-weight: 600; color: var(--fw-muted); }
        .text-right { text-align: right; }
        
        .bar-container { margin-bottom: 6px; }
        .bar-label { display: inline-block; width: 100px; font-size: 10px; }
        .bar-amount { display: inline-block; width: 80px; font-size: 10px; text-align: right; }
        .bar { display: inline-block; height: 12px; background: var(--fw-primary); border-radius: 2px; }
        
        .progress-bar { background: #e5e7eb; height: 6px; border-radius: 3px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 3px; }
        .progress-safe { background: #10b981; }
        .progress-warning { background: #f59e0b; }
        .progress-danger { background: #ef4444; }
        
        .badge { display: inline-block; padding: 2px 6px; border-radius: 8px; font-size: 9px; font-weight: 600; }
        .badge-income { background: #d1fae5; color: #059669; }
        .badge-expense { background: #fee2e2; color: #dc2626; }
        
        .footer { margin-top: 20px; padding-top: 10px; border-top: 1px solid var(--fw-border); text-align: center; color: #9ca3af; font-size: 9px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Laporan Keuangan</h1>
            <p><strong>{{ $month }}</strong></p>
            <p>Dibuat: {{ $generatedAt }}</p>
        </div>

        <div class="summary">
            <div class="summary-row income">
                <div class="summary-label">Pemasukan</div>
                <div class="summary-value">Rp {{ number_format($totalIncome, 0, ',', '.') }}</div>
            </div>
            <div class="summary-row expense">
                <div class="summary-label">Pengeluaran</div>
                <div class="summary-value">Rp {{ number_format($totalExpense, 0, ',', '.') }}</div>
                @if($expenseChange != 0)
                    <div style="font-size: 9px; margin-top: 4px;">
                        {{ $expenseChange > 0 ? '+' : '' }}{{ $expenseChange }}% dari bulan lalu
                    </div>
                @endif
            </div>
            <div class="summary-row cashflow">
                <div class="summary-label">Cashflow</div>
                <div class="summary-value">Rp {{ number_format($netCashflow, 0, ',', '.') }}</div>
                <div style="font-size: 9px; margin-top: 4px;">{{ $transactionCount }} transaksi</div>
            </div>
        </div>

        @if(count($expenseByCategory) > 0)
        <div class="section">
            <div class="section-title">Pengeluaran per Kategori</div>
            
            @if(!empty($pieChartData))
            @php 
                $chartColors = ['#0d9488', '#22c55e', '#f59e0b', '#ef4444', '#6366f1', '#8b5cf6', '#ec4899', '#14b8a6'];
            @endphp
            @foreach($pieChartData as $index => $segment)
            <div style="margin-bottom: 8px;">
                <div style="display: inline-block; width: 100px; font-size: 10px;">{{ \Illuminate\Support\Str::limit($segment['name'], 12) }}</div>
                <div style="display: inline-block; width: {{ min($segment['percentage'] * 2, 200) }}px; height: 14px; background: {{ $chartColors[$index % count($chartColors)] }};"></div>
                <span style="font-size: 10px; margin-left: 5px; font-weight: 600;">{{ $segment['percentage'] }}%</span>
                <span style="font-size: 9px; color: #666; margin-left: 5px;">Rp {{ number_format($segment['total'], 0, ',', '.') }}</span>
            </div>
            @endforeach
            @else
            @php $maxExpense = collect($expenseByCategory)->max('total'); @endphp
            @foreach(array_slice($expenseByCategory, 0, 6) as $cat)
            <div class="bar-container">
                <span class="bar-label">{{ \Illuminate\Support\Str::limit($cat['name'], 12) }}</span>
                <span class="bar" style="width: {{ $maxExpense > 0 ? min(($cat['total'] / $maxExpense * 150), 150) : 5 }}px;"></span>
                <span class="bar-amount">Rp {{ number_format($cat['total'], 0, ',', '.') }}</span>
            </div>
            @endforeach
            @endif
        </div>
        @endif

        @if($budgets->count() > 0)
        <div class="section">
            <div class="section-title">Status Budget</div>
            <table>
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th class="text-right">Budget</th>
                        <th class="text-right">Terpakai</th>
                        <th class="text-right">%</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($budgets as $budget)
                    <tr>
                        <td>{{ $budget['category'] }}</td>
                        <td class="text-right">Rp {{ number_format($budget['budget'], 0, ',', '.') }}</td>
                        <td class="text-right">Rp {{ number_format($budget['spent'], 0, ',', '.') }}</td>
                        <td class="text-right">
                            <span class="badge {{ $budget['percentage'] > 100 ? 'badge-expense' : 'badge-income' }}">
                                {{ $budget['percentage'] }}%
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($recentTransactions->count() > 0)
        <div class="section">
            <div class="section-title">Transaksi Terbaru</div>
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Kategori</th>
                        <th>Deskripsi</th>
                        <th class="text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentTransactions as $tx)
                    <tr>
                        <td>{{ $tx->transaction_date->format('d/m') }}</td>
                        <td>{{ \Illuminate\Support\Str::limit(optional($tx->category)->name ?? 'Lainnya', 15) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($tx->description ?? '-', 20) }}</td>
                        <td class="text-right">
                            <span class="badge {{ $tx->type === 'income' ? 'badge-income' : 'badge-expense' }}">
                                {{ $tx->type === 'income' ? '+' : '-' }}{{ number_format($tx->amount, 0, ',', '.') }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="footer">
            <p><strong>FinWa</strong> - Asisten Keuangan via WhatsApp</p>
            <p>https://finwa.web.id</p>
        </div>
    </div>
</body>
</html>
