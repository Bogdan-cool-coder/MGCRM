<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->foreignId('acquisition_channel_id')
                ->nullable()
                ->after('specialization')
                ->constrained('acquisition_channels')
                ->nullOnDelete();
        });

        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->foreignId('acquisition_channel_id')
                ->nullable()
                ->after('source')
                ->constrained('acquisition_channels')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->dropForeign(['acquisition_channel_id']);
            $table->dropColumn('acquisition_channel_id');
        });

        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropForeign(['acquisition_channel_id']);
            $table->dropColumn('acquisition_channel_id');
        });
    }
};
