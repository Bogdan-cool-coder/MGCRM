<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained('crm_contacts')
                ->cascadeOnDelete();
            $table->string('channel_type', 16);
            $table->string('value', 255);
            $table->string('label', 64)->nullable();
            $table->boolean('is_primary_for_channel')->default(false);
            $table->timestamps();

            $table->index('contact_id');
            $table->unique(['contact_id', 'channel_type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_channels');
    }
};
