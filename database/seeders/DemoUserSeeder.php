<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Demo Tenant
        $demoTenant = Tenant::firstOrCreate(
            ['slug' => 'demo-company'],
            [
                'name' => 'Demo Company',
                'is_active' => true,
            ]
        );

        // Get roles that should already exist from RoleSeeder
        // If RoleSeeder hasn't run yet, create them
        $ownerRole = Role::where('tenant_id', $demoTenant->id)
            ->where('slug', 'owner')
            ->first();

        $financeRole = Role::where('tenant_id', $demoTenant->id)
            ->where('slug', 'finance')
            ->first();

        $staffRole = Role::where('tenant_id', $demoTenant->id)
            ->where('slug', 'staff')
            ->first();

        // If roles don't exist, create them
        if (! $ownerRole) {
            $ownerRole = Role::create([
                'tenant_id' => $demoTenant->id,
                'name' => 'Owner',
                'slug' => 'owner',
                'permissions' => ['*'],
                'description' => 'Tenant owner with full access',
                'is_system' => true,
            ]);
        }

        if (! $financeRole) {
            $financeRole = Role::create([
                'tenant_id' => $demoTenant->id,
                'name' => 'Finance',
                'slug' => 'finance',
                'permissions' => ['transactions.view', 'transactions.create', 'transactions.update', 'reports.view'],
                'description' => 'Finance staff with transaction access',
                'is_system' => true,
            ]);
        }

        if (! $staffRole) {
            $staffRole = Role::create([
                'tenant_id' => $demoTenant->id,
                'name' => 'Staff',
                'slug' => 'staff',
                'permissions' => ['transactions.view', 'transactions.create'],
                'description' => 'Staff with limited access',
                'is_system' => true,
            ]);
        }

        // Create Demo Users
        $users = [
            [
                'name' => 'Demo Owner',
                'email' => 'demo@keuangan.ai',
                'password' => Hash::make('password'),
                'role' => $ownerRole,
            ],
            [
                'name' => 'Finance Manager',
                'email' => 'finance@keuangan.ai',
                'password' => Hash::make('password'),
                'role' => $financeRole,
            ],
            [
                'name' => 'Staff Member',
                'email' => 'staff@keuangan.ai',
                'password' => Hash::make('password'),
                'role' => $staffRole,
            ],
        ];

        foreach ($users as $userData) {
            $role = $userData['role'];
            unset($userData['role']);

            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                array_merge($userData, [
                    'tenant_id' => $demoTenant->id,
                    'role_id' => $role->id,
                ])
            );

            // Create user_tenant relationship
            UserTenant::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'tenant_id' => $demoTenant->id,
                ],
                [
                    'role_id' => $role->id,
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Demo users created:');
        $this->command->info('- demo@keuangan.ai / password (Owner)');
        $this->command->info('- finance@keuangan.ai / password (Finance)');
        $this->command->info('- staff@keuangan.ai / password (Staff)');
    }
}
