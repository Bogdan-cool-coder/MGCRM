<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M9 dept-visibility backfill (audit §3.6 MED).
 *
 * The manager visibility scope was promoted own → department
 * (2026_07_02_100000_promote_manager_visibility_to_department) but the live
 * `deals` never had `department_id` populated for rows created before the
 * scope existed. Result: 28/31 live deals carry a NULL department_id and are
 * invisible to the department scope (a manager only sees them if they OWN
 * them — the "owner_user_id OR department_id" OR-branch never matches a NULL
 * department). This stamps department_id from the deal owner's current
 * department for every NULL-department deal, matching exactly what
 * DealService::create()/update()/BulkDealService::changeOwner() do at write
 * time (department is stamped from the OWNER, not the company).
 *
 * WIDENS VISIBILITY (department members will now see these deals) — this is
 * the intended M9 activation and was PM-approved as part of Э5.
 *
 * SAFE / IDEMPOTENT / DRIVER-AGNOSTIC:
 *   - Only touches deals where department_id IS NULL, so a re-run stamps
 *     nothing new (a second run matches the shrinking NULL set only if a
 *     genuinely-new NULL deal appeared — harmless, same rule).
 *   - Only sets a department when the owner actually HAS one — a deal whose
 *     owner is department-less stays NULL (correct: nothing to inherit).
 *   - Chunked by primary key so a large table never loads into memory or
 *     locks in one statement; uses portable DB::table() builder verbs
 *     (no pg-only / sqlite-only SQL), so it runs identically on the
 *     :memory: test DB and on Postgres.
 *
 * IRREVERSIBLE data backfill — down() is a documented no-op: after the fact
 * there is no way to distinguish a department_id we stamped here from one set
 * legitimately by a later write, so re-nulling would corrupt valid data. The
 * schema is untouched, so rolling PAST this migration is still safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lastId = 0;
        $chunk = 500;

        do {
            // Pull the next window of NULL-department deals joined to their
            // owner's department in one indexed, keyset-paginated read.
            $rows = DB::table('deals')
                ->join('users', 'users.id', '=', 'deals.owner_user_id')
                ->whereNull('deals.department_id')
                ->whereNotNull('users.department_id')
                ->where('deals.id', '>', $lastId)
                ->orderBy('deals.id')
                ->limit($chunk)
                ->get(['deals.id as deal_id', 'users.department_id as department_id']);

            foreach ($rows as $row) {
                DB::table('deals')
                    ->where('id', $row->deal_id)
                    ->update(['department_id' => $row->department_id]);
                $lastId = (int) $row->deal_id;
            }
        } while ($rows->count() === $chunk);
    }

    public function down(): void
    {
        // Irreversible data backfill — see class docblock. No-op by design.
    }
};
