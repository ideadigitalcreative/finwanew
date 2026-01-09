<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add pengeluaran_pulsa_token category to all existing tenants
        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->get();
        
        foreach ($tenants as $tenant) {
            // Check if category already exists
            $exists = DB::table('categories')
                ->where('tenant_id', $tenant->id)
                ->where('type', 'pengeluaran_pulsa_token')
                ->exists();
            
            if (!$exists) {
                DB::table('categories')->insert([
                    'tenant_id' => $tenant->id,
                    'type' => 'pengeluaran_pulsa_token',
                    'name' => 'Pulsa & Token',
                    'slug' => 'pulsa-token',
                    'description' => 'Pengeluaran untuk pulsa, token listrik, voucher, dan sejenisnya',
                    'icon' => '📱',
                    'color' => '#ef4444',
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove pengeluaran_pulsa_token category from all tenants
        DB::table('categories')->where('type', 'pengeluaran_pulsa_token')->delete();
    }
};
