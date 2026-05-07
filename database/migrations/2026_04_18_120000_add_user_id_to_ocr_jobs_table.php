<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'user_id', 'created_at'], 'ocr_jobs_tenant_user_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->dropIndex('ocr_jobs_tenant_user_created_idx');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
