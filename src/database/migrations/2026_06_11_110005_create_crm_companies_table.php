<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_companies', function (Blueprint $table): void {
            $table->id();

            // ----- Identity -----
            $table->string('name', 255);               // обиходное / trade name (required)
            $table->string('legal_name', 255)->nullable();
            $table->string('short_name', 128)->nullable();

            // ----- Legal requisites -----
            $table->string('full_legal_form', 255)->nullable();
            $table->string('legal_form', 64)->nullable();
            $table->string('gender_ending_oe', 16)->nullable();
            $table->string('director_position', 128)->nullable();
            $table->string('director_genitive', 255)->nullable();
            $table->string('director_short', 128)->nullable();
            $table->string('acts_basis', 64)->nullable();
            $table->string('tax_id_label', 16)->nullable();   // «БИН», «ИНН» etc.
            $table->string('tax_id', 32)->nullable();          // actual identifier
            $table->text('address')->nullable();
            $table->string('bank', 255)->nullable();
            $table->string('bank_code_label', 32)->nullable();
            $table->string('bank_code', 64)->nullable();
            $table->string('account', 64)->nullable();

            // ----- Contact -----
            $table->string('phone', 64)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('notes')->nullable();

            // ----- Geo -----
            $table->string('country_code', 2)->nullable()->default('kz');
            $table->string('city', 128)->nullable();

            // ----- Classification -----
            $table->string('source', 32)->nullable();   // crm_sources.code (no FK, stored as string)
            $table->string('industry', 64)->nullable();
            $table->foreignId('company_type_id')
                ->nullable()
                ->constrained('crm_company_types')
                ->nullOnDelete();

            // ----- Holding -----
            $table->foreignId('holding_id')
                ->nullable()
                ->constrained('crm_companies')
                ->nullOnDelete();
            $table->string('holding_role', 16)->nullable();  // HoldingRole enum

            // ----- Ownership / visibility -----
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('owner_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            // ----- Tags & Custom fields -----
            // SQLite doesn't support native ARRAY, using JSON for portability in tests
            $table->json('tags')->default('[]');
            $table->json('extra_fields')->default('{}');

            // ----- Category cache (no engine in S1.1) -----
            $table->string('category_code', 8)->nullable();
            $table->bigInteger('turnover_rub')->nullable();   // kopecks
            $table->timestamp('category_recalc_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('name');
            $table->index('tax_id');
            $table->index('email');
            $table->index('source');
            $table->index('company_type_id');
            $table->index('category_code');
            $table->index('country_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_companies');
    }
};
