<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();

            // ----- Classification -----
            $table->string('kind', 32)->default('contract')->index('ix_documents_kind');
            $table->string('number', 64)->nullable()->unique('ix_documents_number');
            $table->string('title', 512)->nullable();

            // ----- Product / Geography -----
            $table->string('product_code', 32);
            $table->string('country_code', 2);
            $table->string('city', 128)->nullable();
            $table->string('city_code', 8)->nullable();

            // ----- Cross-domain links (nullable, FK + nullOnDelete) -----
            $table->unsignedBigInteger('source_deal_id')->nullable();
            $table->foreign('source_deal_id')
                ->references('id')
                ->on('deals')
                ->nullOnDelete();

            $table->unsignedBigInteger('source_company_id')->nullable();
            $table->foreign('source_company_id')
                ->references('id')
                ->on('crm_companies')
                ->nullOnDelete();

            // ----- Ownership -----
            $table->foreignId('author_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // ----- Status machine -----
            $table->string('status', 32)->default('draft');

            // ----- Context (JSONB / json, cross-driver) -----
            $table->json('context')->default('{}');

            // ----- Template reference -----
            $table->unsignedBigInteger('template_version')->nullable();
            $table->foreign('template_version')
                ->references('id')
                ->on('template_versions')
                ->nullOnDelete();

            // ----- File paths -----
            $table->string('docx_path', 512)->nullable();
            $table->string('pdf_path', 512)->nullable();
            $table->string('drive_folder_url', 1024)->nullable();
            $table->string('drive_docx_url', 1024)->nullable();
            $table->string('drive_pdf_url', 1024)->nullable();

            // ----- Integrations -----
            $table->unsignedBigInteger('telegram_message_id')->nullable();

            // ----- Lifecycle timestamps -----
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('signed_at')->nullable();

            // ----- Money (kopecks per ARCHITECTURE.md §3) -----
            $table->string('currency', 8)->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('total_rub')->nullable();
            $table->decimal('fx_rate', 18, 6)->nullable();
            $table->date('fx_rate_date')->nullable();

            // ----- Custom fields (scope=document) -----
            $table->json('extra_fields')->default('{}');

            $table->timestamps();

            // ----- Composite / hot-path indexes -----
            $table->index('status', 'ix_documents_status');
            $table->index(['product_code', 'country_code'], 'ix_documents_product_country');
            $table->index('author_user_id', 'ix_documents_author');
            $table->index('source_deal_id', 'ix_documents_source_deal');
            $table->index('source_company_id', 'ix_documents_source_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
