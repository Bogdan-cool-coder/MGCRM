<?php

declare(strict_types=1);

use App\Domain\Iam\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the `users.role` mirror column. After IAM-1 spatie/laravel-permission
     * is the single authoritative role store (on the `sanctum` guard); the column
     * was only a synced convenience copy. The User model now exposes `role` as a
     * non-persisted accessor backed by the user's single spatie role, so every
     * call site keeps reading `$user->role` unchanged.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            return;
        }

        // The original column was created with `->index()` (index name
        // `users_role_index`). Drop the index via the schema builder so both the
        // pgsql schema and the SQLite table-recreate path stop referencing the
        // column before it is dropped, then drop the column itself.
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_role_index');
            $table->dropColumn('role');
        });
    }

    /**
     * Re-add the column and backfill it from the authoritative spatie role so a
     * rollback restores the mirror exactly.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'role')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('manager')->index();
        });

        // Backfill the mirror from the authoritative spatie assignment (sanctum
        // guard) so existing users keep their role after a rollback.
        if (Schema::hasTable('model_has_roles') && Schema::hasTable('roles')) {
            DB::statement(<<<'SQL'
                UPDATE users
                SET role = COALESCE((
                    SELECT r.name
                    FROM model_has_roles mhr
                    JOIN roles r ON r.id = mhr.role_id
                    WHERE mhr.model_id = users.id
                      AND mhr.model_type = ?
                      AND r.guard_name = ?
                    LIMIT 1
                ), role)
            SQL, [User::class, 'sanctum']);
        }
    }
};
