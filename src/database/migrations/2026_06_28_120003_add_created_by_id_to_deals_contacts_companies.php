<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-class "who created this card" provenance on the three top-level CRM
 * entities. Needed because AMO carries created_by but our schema only had owner;
 * for deals of departed reps owner is the fallback import user, so created_by_id
 * preserves the real author. nullable + nullOnDelete (legacy/system-created rows
 * have no author; deleting a user must not delete their cards).
 *
 * Additive only — the create-flow is not rewritten here; the ETL populates the
 * column and any future native wiring is out of N7 scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table): void {
            $table->foreignId('created_by_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->foreignId('created_by_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->foreignId('created_by_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropForeign(['created_by_id']);
            $table->dropColumn('created_by_id');
        });

        Schema::table('crm_contacts', function (Blueprint $table): void {
            $table->dropForeign(['created_by_id']);
            $table->dropColumn('created_by_id');
        });

        Schema::table('deals', function (Blueprint $table): void {
            $table->dropForeign(['created_by_id']);
            $table->dropColumn('created_by_id');
        });
    }
};
