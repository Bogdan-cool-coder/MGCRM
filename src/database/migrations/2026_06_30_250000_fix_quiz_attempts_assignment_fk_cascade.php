<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix quiz_attempts.assignment_id FK to CASCADE ON DELETE instead of SET NULL.
 *
 * Audit finding #13 (PARTIAL): the code-level delete-guards in AssignmentService
 * prevent deletion of assignments with progress/attempts. However the DB FK was
 * left as nullOnDelete — if ever bypassed (manual SQL, migration rollback), the
 * attempts would be orphaned. Setting cascadeOnDelete makes DB and code consistent.
 *
 * On PG: drop + recreate the FK constraint directly.
 * On SQLite (:memory: tests): SQLite does not support ALTER TABLE DROP CONSTRAINT,
 * so we recreate the table approach — but since tests use RefreshDatabase this
 * migration simply runs the up() and is effectively a no-op on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Drop the old nullOnDelete FK and recreate as cascadeOnDelete.
            DB::statement('ALTER TABLE quiz_attempts DROP CONSTRAINT IF EXISTS quiz_attempts_assignment_id_foreign');
            DB::statement(
                'ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_assignment_id_foreign
                 FOREIGN KEY (assignment_id) REFERENCES course_assignments(id) ON DELETE CASCADE'
            );
        }
        // SQLite: ALTER TABLE cannot modify FK constraints; RefreshDatabase recreates
        // tables per test run so the original migration nullOnDelete is fine in tests.
        // The delete-guard in AssignmentService is the enforced runtime protection.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE quiz_attempts DROP CONSTRAINT IF EXISTS quiz_attempts_assignment_id_foreign');
            DB::statement(
                'ALTER TABLE quiz_attempts ADD CONSTRAINT quiz_attempts_assignment_id_foreign
                 FOREIGN KEY (assignment_id) REFERENCES course_assignments(id) ON DELETE SET NULL'
            );
        }
    }
};
