<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_client_status_log', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('crm_companies')
                ->cascadeOnDelete();

            $table->string('old_status', 16)->nullable();
            $table->string('new_status', 16);

            // Who triggered the change (null = system / job)
            $table->foreignId('changed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('changed_at');

            // Optional: the disconnect reason chosen at the time of change
            $table->foreignId('reason_id')
                ->nullable()
                ->constrained('disconnect_reasons')
                ->nullOnDelete();

            // Arbitrary context (e.g. signed_at date, source job name)
            $table->json('meta')->nullable();

            // No updated_at — log rows are immutable
            $table->timestamp('created_at')->useCurrent();

            $table->index('company_id');
            $table->index('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_client_status_log');
    }
};
