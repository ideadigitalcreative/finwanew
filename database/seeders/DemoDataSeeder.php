<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\Balance;
use App\Models\Cashflow;
use App\Models\Message;
use App\Models\Channel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-company')->first();

        if (!$tenant) {
            $this->command->error('Demo tenant not found. Please run DemoUserSeeder first.');
            return;
        }

        // Get categories
        $categories = Category::where('tenant_id', $tenant->id)->get()->keyBy('type');

        // Create demo balances
        $balances = [
            [
                'account_name' => 'Bank BCA',
                'account_number' => '1234567890',
                'account_type' => 'bank',
                'currency' => 'IDR',
                'balance' => 50000000,
                'balance_date' => now(),
            ],
            [
                'account_name' => 'Bank Mandiri',
                'account_number' => '9876543210',
                'account_type' => 'bank',
                'currency' => 'IDR',
                'balance' => 25000000,
                'balance_date' => now(),
            ],
            [
                'account_name' => 'Cash',
                'account_number' => null,
                'account_type' => 'cash',
                'currency' => 'IDR',
                'balance' => 5000000,
                'balance_date' => now(),
            ],
        ];

        foreach ($balances as $balanceData) {
            Balance::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'account_name' => $balanceData['account_name'],
                ],
                array_merge($balanceData, [
                    'is_active' => true,
                ])
            );
        }

        // Create demo channel
        $channel = Channel::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'type' => 'whatsapp',
                'channel_account' => '6281234567890',
            ],
            [
                'name' => 'WhatsApp: 6281234567890',
                'is_active' => true,
            ]
        );

        // Create demo transactions for last 3 months
        $now = Carbon::now();
        $transactions = [];

        // Income transactions
        $incomeCategories = [
            'pendapatan_gaji' => [5000000, 5500000, 6000000],
            'pendapatan_bonus' => [1000000, 0, 2000000],
            'pendapatan_investasi' => [500000, 750000, 1000000],
        ];

        // Expense transactions
        $expenseCategories = [
            'pengeluaran_makanan' => [500000, 600000, 550000],
            'pengeluaran_transport' => [300000, 350000, 400000],
            'pengeluaran_hunian' => [2000000, 2000000, 2000000],
            'pengeluaran_utilitas' => [500000, 550000, 600000],
            'pengeluaran_kesehatan' => [300000, 0, 500000],
            'pengeluaran_belanja' => [1000000, 1500000, 800000],
            'pengeluaran_hiburan' => [500000, 300000, 400000],
            'pengeluaran_tagihan' => [800000, 900000, 850000],
        ];

        // Generate transactions for last 3 months
        for ($monthOffset = 2; $monthOffset >= 0; $monthOffset--) {
            $month = $now->copy()->subMonths($monthOffset);
            $daysInMonth = $month->daysInMonth;

            // Income transactions (usually at start of month)
            foreach ($incomeCategories as $categoryType => $amounts) {
                if (!isset($categories[$categoryType])) {
                    continue;
                }

                $amount = $amounts[$monthOffset] ?? 0;
                if ($amount > 0) {
                    $transactions[] = [
                        'tenant_id' => $tenant->id,
                        'category_id' => $categories[$categoryType]->id,
                        'type' => 'income',
                        'amount' => $amount,
                        'transaction_date' => $month->copy()->day(rand(1, 5)),
                        'source' => 'Bank Transfer',
                        'description' => $this->getIncomeDescription($categoryType),
                        'status' => 'confirmed',
                        'confidence_score' => 0.95,
                    ];
                }
            }

            // Expense transactions (throughout the month)
            foreach ($expenseCategories as $categoryType => $amounts) {
                if (!isset($categories[$categoryType])) {
                    continue;
                }

                $totalAmount = $amounts[$monthOffset] ?? 0;
                if ($totalAmount > 0) {
                    // Split into multiple transactions
                    $numTransactions = rand(5, 15);
                    $avgAmount = $totalAmount / $numTransactions;

                    for ($i = 0; $i < $numTransactions; $i++) {
                        $transactions[] = [
                            'tenant_id' => $tenant->id,
                            'category_id' => $categories[$categoryType]->id,
                            'type' => 'expense',
                            'amount' => round($avgAmount * (0.5 + rand(0, 100) / 100), -3), // Random variation
                            'transaction_date' => $month->copy()->day(rand(1, $daysInMonth)),
                            'source' => $this->getExpenseSource($categoryType),
                            'description' => $this->getExpenseDescription($categoryType),
                            'status' => rand(0, 10) < 8 ? 'confirmed' : 'review', // 80% confirmed, 20% review
                            'confidence_score' => rand(70, 100) / 100,
                        ];
                    }
                }
            }
        }

        // Insert transactions in batches
        foreach (array_chunk($transactions, 100) as $chunk) {
            Transaction::insert($chunk);
        }

        // Create some review transactions
        $reviewTransactions = Transaction::where('tenant_id', $tenant->id)
            ->where('status', 'review')
            ->limit(5)
            ->get();

        $this->command->info('Demo data created:');
        $this->command->info('- ' . count($transactions) . ' transactions');
        $this->command->info('- ' . count($balances) . ' balances');
        $this->command->info('- ' . $reviewTransactions->count() . ' transactions pending review');
    }

    protected function getIncomeDescription(string $categoryType): string
    {
        $descriptions = [
            'pendapatan_gaji' => 'Gaji Bulanan',
            'pendapatan_bonus' => 'Bonus Tahunan',
            'pendapatan_investasi' => 'Dividen Investasi',
        ];

        return $descriptions[$categoryType] ?? 'Pendapatan';
    }

    protected function getExpenseDescription(string $categoryType): string
    {
        $descriptions = [
            'pengeluaran_makanan' => ['Makan Siang', 'Makan Malam', 'Sarapan', 'Snack', 'Minuman'],
            'pengeluaran_transport' => ['Ojek Online', 'Bensin', 'Parkir', 'Tol', 'Taxi'],
            'pengeluaran_hunian' => ['Sewa Rumah', 'Kontrakan', 'Cicilan Rumah'],
            'pengeluaran_utilitas' => ['Listrik', 'Air', 'Internet', 'TV Kabel'],
            'pengeluaran_kesehatan' => ['Konsultasi Dokter', 'Obat', 'Vitamin', 'Medical Checkup'],
            'pengeluaran_belanja' => ['Belanja Bulanan', 'Pakaian', 'Elektronik', 'Kebutuhan Rumah'],
            'pengeluaran_hiburan' => ['Nonton Film', 'Karaoke', 'Kafe', 'Konser'],
            'pengeluaran_tagihan' => ['Kartu Kredit', 'Asuransi', 'Telepon', 'TV Subscription'],
        ];

        $options = $descriptions[$categoryType] ?? ['Pengeluaran'];
        return $options[array_rand($options)];
    }

    protected function getExpenseSource(string $categoryType): string
    {
        $sources = [
            'pengeluaran_makanan' => ['Restoran', 'Warung Makan', 'Food Delivery'],
            'pengeluaran_transport' => ['Gojek', 'Grab', 'SPBU', 'Parkir'],
            'pengeluaran_hunian' => ['Bank BCA', 'Bank Mandiri'],
            'pengeluaran_utilitas' => ['PLN', 'PDAM', 'Indihome'],
            'pengeluaran_kesehatan' => ['Klinik', 'Apotek', 'Rumah Sakit'],
            'pengeluaran_belanja' => ['Supermarket', 'Mall', 'Online Shop'],
            'pengeluaran_hiburan' => ['Bioskop', 'Kafe', 'Karaoke'],
            'pengeluaran_tagihan' => ['Bank', 'E-Wallet', 'Auto Debit'],
        ];

        $options = $sources[$categoryType] ?? ['Cash'];
        return $options[array_rand($options)];
    }
}
