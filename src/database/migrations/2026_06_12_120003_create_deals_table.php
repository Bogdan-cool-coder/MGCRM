<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table): void {
            $table->id();

            // ----- Pipeline placement -----
            $table->foreignId('pipeline_id')
                ->constrained('pipelines')
                ->restrictOnDelete();
            $table->foreignId('stage_id')
                ->constrained('pipeline_stages')
                ->restrictOnDelete();

            // ----- Master: Deal-on-Company (NOT NULL) -----
            $table->foreignId('company_id')
                ->constrained('crm_companies')
                ->restrictOnDelete();

            // ----- Core -----
            $table->string('title', 255);
            $table->unsignedBigInteger('amount')->default(0); // derived from deal_products (kopecks)
            $table->string('currency', 8);

            // ----- Ownership / scope -----
            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            // ----- Links (contract FK lands in S2) -----
            $table->unsignedBigInteger('contract_id')->nullable();

            // ----- Lost -----
            $table->text('lost_reason')->nullable();
            $table->foreignId('lost_reason_id')
                ->nullable()
                ->constrained('lost_reasons')
                ->nullOnDelete();

            // ----- Tags & custom fields (json for PG+SQLite portability; native text[] deferred to S1.7) -----
            $table->json('tags')->nullable();
            $table->json('extra_fields')->default('{}');

            // ----- Forecast dates -----
            $table->date('expected_close_date')->nullable();
            $table->date('expected_sign_date')->nullable();
            $table->date('expected_payment_date')->nullable();

            // ----- Lifecycle timestamps -----
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['pipeline_id', 'stage_id'], 'ix_deals_pipeline_stage');
            $table->index('company_id', 'ix_deals_company');
            $table->index('owner_user_id', 'ix_deals_owner');
            $table->index('department_id', 'ix_deals_department');
            $table->index('stage_changed_at', 'ix_deals_stage_changed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
