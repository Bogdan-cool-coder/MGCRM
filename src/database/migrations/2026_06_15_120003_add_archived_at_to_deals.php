<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
