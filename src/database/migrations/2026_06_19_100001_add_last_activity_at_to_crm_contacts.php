<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add last_activity_at (Engagement signal) to crm_contacts.
 * Denormalised cache column — updated by EngagementService::touch()
 * after every Activity::create. Never computed in-flight (N+1 prevention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', static function (Blueprint $table): void {
            $table->timestamp('last_activity_at')->nullable()->after('extra_fields');
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', static function (Blueprint $table): void {
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn('last_activity_at');
        });
    }
};
