<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AMO -> MGCRM migration maps (Phase 0 skeleton)
|--------------------------------------------------------------------------
|
| Hand-maintained terminal maps for the temporary AMO import (Domain/Migration,
| dropped at M12). These are the SMALL, manually-curated maps; the high-volume
| auto-maps (custom fields, options, products) live in the migration_maps /
| amo_product_mappings tables instead.
|
| ⚠️ N7 ships only the SKELETON. Every map below is a placeholder and MUST be
| filled to 100% before the load phase runs:
|   - status_map: must cover every AMO status id across both funnels and resolve
|     to a non-null stage_code (the load hard-gates on unmapped statuses).
|   - user_map: must cover every active AMO user id; departed reps fall back to
|     fallback_user_email (the AmoImportUserSeeder service account).
| AMO well-known terminal statuses are fixed: 142 = won, 143 = lost.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Source / pipelines
    |--------------------------------------------------------------------------
    |
    | Per-pipeline import settings keyed by our pipeline code. default_currency
    | (DEC-A) is the currency AMO deals land in when the source carries none.
    |
    */
    'pipelines' => [
        // TODO N7+: confirm the real MGCRM pipeline codes (SalesPulse AMO mirror
        // funnels) and per-funnel defaults.
        'macro_global' => [
            'default_currency' => 'RUB',
        ],
        'macro_ai_global' => [
            'default_currency' => 'RUB',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Status map: amo_status_id => { pipeline_code, stage_code }
    |--------------------------------------------------------------------------
    |
    | Must be 100% complete before load (stage_code NOT NULL for every status).
    | 142 (won) / 143 (lost) are AMO's fixed terminal statuses.
    |
    */
    'status_map' => [
        // TODO N7+: fill every AMO status id across both funnels.
        // Example shape (placeholder values — replace before load):
        // 12345678 => ['pipeline_code' => 'macro_global', 'stage_code' => 'new'],
        142 => ['pipeline_code' => 'macro_global', 'stage_code' => 'won'],
        143 => ['pipeline_code' => 'macro_global', 'stage_code' => 'lost'],
    ],

    /*
    |--------------------------------------------------------------------------
    | User map: amo_user_id => mgcrm user_id
    |--------------------------------------------------------------------------
    |
    | Departed / unmapped AMO users fall back to the service account resolved by
    | fallback_user_email (see AmoImportUserSeeder, DEC-C) so owner stays NOT NULL.
    |
    */
    'user_map' => [
        // TODO N7+: fill amo_user_id => mgcrm user id for every active AMO rep.
        // 1111111 => 5,
    ],
    'fallback_user_email' => 'import-amo@mgcrm.local',

    /*
    |--------------------------------------------------------------------------
    | Task type map: amo_task_type_id => mgcrm activity kind
    |--------------------------------------------------------------------------
    */
    'task_type_map' => [
        // TODO N7+: map AMO task type ids to MGCRM activity kinds (call/meeting/...).
        // 1 => 'call',
    ],

    /*
    |--------------------------------------------------------------------------
    | Note type map: amo_note_type => mgcrm note/activity kind
    |--------------------------------------------------------------------------
    */
    'note_type_map' => [
        // TODO N7+: map AMO note types (common, call_in, call_out, ...) to MGCRM.
        // 'common' => 'note',
    ],

    /*
    |--------------------------------------------------------------------------
    | Loss reason map: amo_loss_reason_id => mgcrm lost_reason code/id
    |--------------------------------------------------------------------------
    */
    'loss_reason_map' => [
        // TODO N7+: map AMO loss reason ids to MGCRM lost_reason codes.
        // 7654321 => 'price',
    ],

];
