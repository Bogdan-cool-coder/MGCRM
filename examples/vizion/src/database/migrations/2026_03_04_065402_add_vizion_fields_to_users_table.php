<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->default('viewer');
            $table->string('locale', 5)->default('ru');
            $table->string('iframe_token')->nullable()->unique();
            $table->json('company_accesses')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn([
                'company_id', 'role', 'locale', 'iframe_token', 'company_accesses'
            ]);
        });
    }
};
