<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * N6 (contract): add FK constraint to crm_companies.disconnect_doc_id → documents.id.
 *
 * The column was added by N5 (2026_06_27_100001) without a FK because the
 * documents table did not yet have the termination_agreement kind. The FK is
 * added here now that the Contracts domain owns the TerminationAgreement kind.
 *
 * nullOnDelete: if the TerminationAgreement document is somehow deleted, the
 * company loses the reference but is NOT un-disconnected (that state lives in
 * client_status / disconnected_at, not in this pointer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->foreign('disconnect_doc_id')
                ->references('id')
                ->on('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropForeign(['disconnect_doc_id']);
        });
    }
};
