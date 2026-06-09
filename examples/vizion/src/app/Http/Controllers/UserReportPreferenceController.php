<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AssertsReportReadAccess;
use App\Models\Report;
use App\Models\UserReportPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user, per-report UI preferences. A report is now a dry table, so the
 * only synced preference is column order / hidden columns (column_order).
 * Backs the frontend localStorage key so settings follow the user across
 * devices.
 *
 * Visibility mirrors ReportController's read-side ACL: if the user can't see
 * the report, they can't read or write its preferences. The trait throws 403
 * directly — no need for an explicit unwrap in the actions below.
 */
class UserReportPreferenceController extends Controller
{
    use AssertsReportReadAccess;

    /**
     * Return the current user's preferences for the given report.
     *
     * Always 200 with a default-filled payload when no row exists — the
     * frontend treats absent record === defaults, and saving a 404→empty round
     * trip into a fetch flow is needless ceremony.
     */
    public function show(Request $request, Report $report): JsonResponse
    {
        $this->assertReportAccess($request, $report);

        $pref = UserReportPreference::where('user_id', $request->user()->id)
            ->where('report_id', $report->id)
            ->first();

        return response()->json($this->serialize($report->id, $pref));
    }

    /**
     * Partial upsert of preferences for the given report.
     *
     * Semantics:
     *  - Any field present in the payload (even if explicitly null) is written.
     *  - Any field absent from the payload is left untouched.
     *  - First write for a (user, report) pair creates the row; subsequent
     *    writes update it (unique index on (user_id, report_id) guarantees
     *    one-row-per-pair).
     */
    public function update(Request $request, Report $report): JsonResponse
    {
        $this->assertReportAccess($request, $report);

        $validated = $request->validate([
            // Mirrors the frontend useColumnOrder() shape. `order` is the
            // user-chosen column sequence by field key; `hidden` is the
            // matching list of explicitly-hidden field keys. Both halves
            // are optional — an explicit `null` for the whole object clears
            // the preference.
            'column_order'                  => ['sometimes', 'nullable', 'array'],
            'column_order.order'            => ['sometimes', 'array'],
            'column_order.order.*'          => ['string'],
            'column_order.hidden'           => ['sometimes', 'array'],
            'column_order.hidden.*'         => ['string'],
        ]);

        // Build the attribute patch from keys actually present in the request
        // body — `sometimes` lets the validator skip absent keys, but
        // $validated may still contain a key that was sent as explicit null.
        // We want both behaviours: present-and-null clears the field; absent
        // leaves it untouched.
        $patch = [];
        if (array_key_exists('column_order', $validated)) {
            $patch['column_order'] = $validated['column_order'];
        }

        // `column_order` was historically a free-form jsonb with a `groups`
        // sub-key (per-field column_group overrides). The column-group feature
        // is gone, but older rows in the DB may still carry the key. Strip it
        // on write so the stored shape matches the new contract — read-side
        // serialize() does the same on the way out.
        if (array_key_exists('column_order', $patch) && is_array($patch['column_order'])) {
            unset($patch['column_order']['groups']);
        }

        $pref = UserReportPreference::updateOrCreate(
            [
                'user_id'   => $request->user()->id,
                'report_id' => $report->id,
            ],
            $patch
        );

        return response()->json($this->serialize($report->id, $pref));
    }

    /**
     * Uniform response shape for both show() (where the row may be missing)
     * and update() (where the row always exists). Fronts the same defaults the
     * frontend would otherwise apply locally so the client never has to
     * branch on "preferences exist or not".
     */
    private function serialize(int $reportId, ?UserReportPreference $pref): array
    {
        // column_order historically carried a `groups` sub-key (per-field
        // column_group overrides). The column-group feature was dropped; we
        // still tolerate legacy rows with a `groups` key in the jsonb payload
        // but silently strip it on the way out so the response shape matches
        // the current contract.
        $columnOrder = $pref->column_order ?? null;
        if (is_array($columnOrder) && array_key_exists('groups', $columnOrder)) {
            unset($columnOrder['groups']);
        }

        return [
            'report_id'    => $reportId,
            'column_order' => $columnOrder,
        ];
    }
}
