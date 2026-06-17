<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contact_company_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contact_id')
                ->constrained('crm_contacts')
                ->cascadeOnDelete();

            $table->foreignId('company_id')
                ->constrained('crm_companies')
                ->cascadeOnDelete();

            // Position at this company (free text + optional ref to directory)
            $table->string('position', 128)->nullable();
            $table->foreignId('position_id')
                ->nullable()
                ->constrained('crm_contact_positions')
                ->nullOnDelete();

            // Employment status: works | left
            $table->string('employment_status', 16)->default('works');

            // True = this is the contact's primary company
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            // UNIQUE pair — one link per contact+company
            $table->unique(['contact_id', 'company_id']);

            // Covering index for "all employees of a company, primary first"
            $table->index(['company_id', 'is_primary']);
            $table->index(['contact_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_company_links');
    }
};
