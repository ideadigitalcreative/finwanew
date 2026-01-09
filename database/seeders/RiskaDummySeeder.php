<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\Category;
use App\Models\Budget;
use Carbon\Carbon;

class RiskaDummySeeder extends Seeder
{
    public function run()
    {
        $email = 'riska45@outlook.com';
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->command->error("User {$email} not found! Creating user...");
            // Create user if strictly needed
             $user = User::create([
                'name' => 'Riska',
                'email' => $email,
                'password' => bcrypt('password'),
            ]);
            
            // Create tenant for user
            $tenant = Tenant::create([
                'name' => $user->name . "'s Team",
                'user_id' => $user->id,
            ]);
            
            $user->current_tenant_id = $tenant->id;
            $user->save();
             
             // Create pivot
            \DB::table('tenant_user')->insert([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => 'owner',
            ]);
        }
        
        $tenant = $user->tenants()->first();
        if (!$tenant) {
             $tenant = Tenant::where('user_id', $user->id)->first();
             if(!$tenant) {
                 $tenant = Tenant::create([
                    'name' => $user->name . "'s Team",
                    'user_id' => $user->id,
                ]);
                 \DB::table('tenant_user')->insert([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'role' => 'owner',
                ]);
             }
        }

        $this->command->info("Seeding data for User: {$user->name}, Tenant ID: {$tenant->id}");

        // 1. Categories
        // Format: 'Enum Type' => ['Name' => 'Icon']
        $categoriesMapping = [
            'pendapatan_gaji' => ['Gaji' => '💰'],
            'pendapatan_lainnya' => ['Bisnis' => '💼', 'Pendapatan Lain' => '💵'],
            'pendapatan_investasi' => ['Investasi' => '📈'],
            'pendapatan_bonus' => ['Bonus' => '🎁'],
            
            'pengeluaran_makanan' => ['Makanan' => '🍔'],
            'pengeluaran_transport' => ['Transportasi' => '🚗'],
            'pengeluaran_belanja' => ['Kebutuhan' => '🏠'],
            'pengeluaran_pendidikan' => ['Pendidikan' => '🎓'],
            'pengeluaran_hiburan' => ['Hiburan' => '🎬'],
            'pengeluaran_kesehatan' => ['Kesehatan' => '💊']
        ];

        $catIds = []; // [Enum Type => ID] 

        foreach ($categoriesMapping as $type => $list) {
            foreach ($list as $name => $icon) {
                // Determine transaction type based on category type prefix
                $transType = str_starts_with($type, 'pendapatan') ? 'income' : 'expense';
                
                $cat = Category::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'type' => $type, 'name' => $name], 
                    ['icon' => $icon, 'slug' => \Str::slug($name)]
                );
                
                // Save ID by Name for easy retrieval
                $catIds[$transType][$name] = $cat->id;
            }
        }

        // 2. Clear existing transactions for clean slate (Optional)
        Transaction::where('tenant_id', $tenant->id)->delete();
        Budget::where('tenant_id', $tenant->id)->delete();

        // 3. Generate Transactions (Last 6 months)
        $now = Carbon::now();
        
        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i);
            $daysInMonth = $month->daysInMonth;
            
            // Income: Gaji (Fixed date)
            if (isset($catIds['income']['Gaji'])) {
                Transaction::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catIds['income']['Gaji'],
                    'amount' => 28300000,
                    'type' => 'income',
                    'description' => 'Gaji Bulanan',
                    'transaction_date' => $month->copy()->day(25),
                    'status' => 'confirmed'
                ]);
            }

            // Income: Bisnis (Random dates)
            if (isset($catIds['income']['Bisnis'])) {
                for ($k = 0; $k < 3; $k++) {
                    Transaction::create([
                        'tenant_id' => $tenant->id,
                        'category_id' => $catIds['income']['Bisnis'],
                        'amount' => rand(5000000, 15000000),
                        'type' => 'income',
                        'description' => 'Pendapatan Bisnis Toko',
                        'transaction_date' => $month->copy()->day(rand(1, 28)),
                        'status' => 'confirmed'
                    ]);
                }
            }
            
             // Income: Investasi (Random)
            if (isset($catIds['income']['Investasi']) && rand(0, 1)) {
                Transaction::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catIds['income']['Investasi'],
                    'amount' => rand(2000000, 10000000),
                    'type' => 'income',
                    'description' => 'Dividen Saham',
                    'transaction_date' => $month->copy()->day(rand(10, 20)),
                    'status' => 'confirmed'
                ]);
            }

            // Expenses: Create many small transactions
            // Makanan (~30 transactions)
             if (isset($catIds['expense']['Makanan'])) {
                for ($k = 0; $k < 15; $k++) {
                    Transaction::create([
                        'tenant_id' => $tenant->id,
                        'category_id' => $catIds['expense']['Makanan'],
                        'amount' => rand(50000, 500000),
                        'type' => 'expense',
                        'description' => 'Makan Siang/Malam',
                        'transaction_date' => $month->copy()->day(rand(1, $daysInMonth)),
                        'status' => 'confirmed'
                    ]);
                }
            }
            
            // Kebutuhan (~5 transactions) - Listrik, Air, etc
             if (isset($catIds['expense']['Kebutuhan'])) {
                Transaction::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catIds['expense']['Kebutuhan'],
                    'amount' => rand(1500000, 2500000),
                    'type' => 'expense',
                    'description' => 'Bayar Listrik & Air',
                    'transaction_date' => $month->copy()->day(5),
                    'status' => 'confirmed'
                ]);
                 Transaction::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catIds['expense']['Kebutuhan'],
                    'amount' => rand(3000000, 5000000),
                    'type' => 'expense',
                    'description' => 'Belanja Bulanan',
                    'transaction_date' => $month->copy()->day(2),
                    'status' => 'confirmed'
                ]);
             }

            // Pendidikan (Fixed)
             if (isset($catIds['expense']['Pendidikan'])) {
                Transaction::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catIds['expense']['Pendidikan'],
                    'amount' => 4500000,
                    'type' => 'expense',
                    'description' => 'SPP Sekolah',
                    'transaction_date' => $month->copy()->day(10),
                    'status' => 'confirmed'
                ]);
            }
            
             // Transport
             if (isset($catIds['expense']['Transportasi'])) {
                for ($k = 0; $k < 8; $k++) {
                    Transaction::create([
                        'tenant_id' => $tenant->id,
                        'category_id' => $catIds['expense']['Transportasi'],
                        'amount' => rand(50000, 300000),
                        'type' => 'expense',
                        'description' => 'Bensin/Grab',
                        'transaction_date' => $month->copy()->day(rand(1, $daysInMonth)),
                        'status' => 'confirmed'
                    ]);
                }
            }
        }

        // 4. Create Budgets (Bulanan)
        $budgets = [
            ['Makanan', 10000000],
            ['Kebutuhan', 8000000],
            ['Transportasi', 3000000],
            ['Pendidikan', 5000000], 
        ];

        foreach ($budgets as $b) {
            if (isset($catIds['expense'][$b[0]])) {
                $catId = $catIds['expense'][$b[0]];
                Budget::create([
                    'tenant_id' => $tenant->id,
                    'category_id' => $catId,
                    'amount' => $b[1],
                    'period' => 'monthly',
                    'is_active' => true,
                    'start_date' => Carbon::now()->startOfMonth(),
                ]);
            }
        }

        $this->command->info("Dummy data created successfully!");
    }
}
