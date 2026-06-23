<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('crm_companies')
                ->cascadeOnDelete();
            $table->string('channel_type', 16);
            $table->string('value', 255);
            $table->string('label', 64)->nullable();
            $table->boolean('is_primary_for_channel')->default(false);
            $table->timestamps();

            $table->index('company_id');
            $table->unique(['company_id', 'channel_type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_channels');
    }
};
