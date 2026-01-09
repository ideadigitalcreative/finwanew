<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Owner',
                'slug' => 'owner',
                'description' => 'Pemilik sistem dengan akses penuh',
                'permissions' => ['*'], // All permissions
                'is_system' => true,
            ],
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Administrator dengan akses penuh kecuali pengaturan tenant',
                'permissions' => [
                    'transactions.*',
                    'categories.*',
                    'balances.*',
                    'cashflows.*',
                    'channels.*',
                    'messages.*',
                    'users.*',
                    'exports.*',
                    'reports.*',
                ],
                'is_system' => true,
            ],
            [
                'name' => 'Finance',
                'slug' => 'finance',
                'description' => 'Tim keuangan dengan akses transaksi, saldo, dan laporan',
                'permissions' => [
                    'transactions.*',
                    'categories.read',
                    'balances.*',
                    'cashflows.*',
                    'messages.read',
                    'exports.*',
                    'reports.*',
                ],
                'is_system' => true,
            ],
            [
                'name' => 'Staff',
                'slug' => 'staff',
                'description' => 'Staff dengan akses pencatatan transaksi',
                'permissions' => [
                    'transactions.create',
                    'transactions.read',
                    'transactions.update',
                    'categories.read',
                    'balances.read',
                    'messages.read',
                ],
                'is_system' => true,
            ],
            [
                'name' => 'Auditor',
                'slug' => 'auditor',
                'description' => 'Auditor dengan akses read-only untuk audit',
                'permissions' => [
                    'transactions.read',
                    'categories.read',
                    'balances.read',
                    'cashflows.read',
                    'messages.read',
                    'audit_logs.*',
                    'reports.read',
                ],
                'is_system' => true,
            ],
        ];

        // Create roles for each tenant
        $tenants = Tenant::all();
        
        if ($tenants->isEmpty()) {
            // If no tenants exist, create a default tenant first
            $tenant = Tenant::create([
                'name' => 'Default Tenant',
                'slug' => 'default',
                'is_active' => true,
            ]);
            $tenants = collect([$tenant]);
        }

        foreach ($tenants as $tenant) {
            foreach ($roles as $roleData) {
                Role::firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'slug' => $roleData['slug'],
                    ],
                    [
                        'name' => $roleData['name'],
                        'description' => $roleData['description'],
                        'permissions' => $roleData['permissions'],
                        'is_system' => $roleData['is_system'],
                    ]
                );
            }
        }
    }
}
