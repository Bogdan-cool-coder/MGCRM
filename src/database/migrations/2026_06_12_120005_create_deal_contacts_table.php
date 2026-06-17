<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deal_id')
                ->constrained('deals')
                ->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('crm_contacts')
                ->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['deal_id', 'contact_id'], 'ix_deal_contacts_deal_contact');
            $table->index('deal_id', 'ix_deal_contacts_deal');
            $table->index('contact_id', 'ix_deal_contacts_contact');
        });

        // Partial unique: at most one primary contact per deal.
        // Both PostgreSQL and SQLite support partial (filtered) unique indexes
        // via "WHERE", so one raw statement works for both drivers.
        DB::statement(
            'CREATE UNIQUE INDEX ix_deal_contacts_one_primary '
            .'ON deal_contacts (deal_id) WHERE is_primary = true'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ix_deal_contacts_one_primary');
        Schema::dropIfExists('deal_contacts');
    }
};
