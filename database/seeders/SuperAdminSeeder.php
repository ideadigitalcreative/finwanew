<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Tenant;
use App\Models\UserTenant;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or get default tenant for super admin
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'system'],
            [
                'name' => 'System',
                'is_active' => true,
            ]
        );

        // Get or create Owner role for tenant
        $ownerRole = Role::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slug' => 'owner'
            ],
            [
                'name' => 'Owner',
                'permissions' => ['*'], // Full access
                'description' => 'Tenant owner with full access',
                'is_system' => true
            ]
        );

        // Create super admin user
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@keuangan.ai'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
                'whatsapp_number' => '6281234567890',
                'tenant_id' => $tenant->id,
                'role_id' => $ownerRole->id,
                'is_super_admin' => true,
            ]
        );

        // Ensure is_super_admin is set to true (in case user already exists)
        if (!$superAdmin->is_super_admin) {
            $superAdmin->is_super_admin = true;
            $superAdmin->save();
        }

        // Create user_tenant relationship
        UserTenant::firstOrCreate(
            [
                'user_id' => $superAdmin->id,
                'tenant_id' => $tenant->id,
            ],
            [
                'role_id' => $ownerRole->id,
                'is_active' => true,
            ]
        );

        $this->command->info('Super Admin user created:');
        $this->command->info('Email: admin@keuangan.ai');
        $this->command->info('Password: admin123');
        $this->command->info('⚠️  Please change the password after first login!');
    }
}
