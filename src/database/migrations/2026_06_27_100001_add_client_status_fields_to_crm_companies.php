<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            // Lifecycle status: prospect (default) / active / disconnected
            $table->string('client_status', 16)->default('prospect')->after('last_activity_at');

            // Date of first won deal (signed_at); set once, never cleared
            $table->date('unique_client_since')->nullable()->after('client_status');

            // Disconnection metadata
            $table->timestamp('disconnected_at')->nullable()->after('unique_client_since');

            $table->foreignId('disconnect_reason_id')
                ->nullable()
                ->after('disconnected_at')
                ->constrained('disconnect_reasons')
                ->nullOnDelete();

            // FK to document/scan — type is bigint to match typical document PK;
            // actual constraint added by N6/contract when the documents table is ready.
            // Kept nullable bigint here so the column exists for N5 service methods.
            $table->unsignedBigInteger('disconnect_doc_id')->nullable()->after('disconnect_reason_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_companies', function (Blueprint $table): void {
            $table->dropForeign(['disconnect_reason_id']);
            $table->dropColumn([
                'client_status',
                'unique_client_since',
                'disconnected_at',
                'disconnect_reason_id',
                'disconnect_doc_id',
            ]);
        });
    }
};
