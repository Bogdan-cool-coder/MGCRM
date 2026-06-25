<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * visibility_settings — the role × visibility-scope matrix that drives
 * VisibilityResolver at runtime (replaces the hardcoded VisibilityScope::forRole
 * map). One row per RBAC role; scope is one of all | department | own.
 *
 * Stored in the DB (not config/JSON) so changes are atomic, auditable
 * (entity_logs) and admin-editable from Settings → Access Control. Reads are
 * cached (VisibilityConfigService) and busted on every write.
 *
 * Seeded (VisibilitySettingSeeder) with the CURRENT behavior — admin/director/
 * lawyer = all, manager/accountant/cfo = own — so authz is unchanged until an
 * admin edits the matrix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_settings', function (Blueprint $table): void {
            $table->id();
            // One row per RBAC role name (admin/director/lawyer/manager/accountant/cfo).
            $table->string('role', 30)->unique();
            // Visibility scope: all | department | own (VisibilityScope enum value).
            $table->string('scope', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_settings');
    }
};
