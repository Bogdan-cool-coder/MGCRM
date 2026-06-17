<?php

declare(strict_types=1);

/**
 * Automation engine config (M7).
 *
 * Centralises the action-handler whitelists and the outbound-webhook SSRF
 * policy so they are not scattered as magic constants across handlers. Mirrors
 * the old project's module-level SET_FIELD_WHITELIST / DATE_FIELDS /
 * DEFAULT_ALLOWED_PORTS, narrowed to the MVP target (deal only).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | set_field whitelist
    |--------------------------------------------------------------------------
    |
    | Columns the set_field action may write directly on a Deal. Anything not
    | listed (and not a defined custom field) is rejected as `skipped` — this is
    | the security boundary that stops an automation from patching stage_id,
    | owner, amount/currency or any sensitive column. Stage moves go through the
    | change_stage action (DealMoveService); owner re-assign through change_owner.
    |
    */
    'set_field' => [
        'deal' => ['title', 'tags'],
    ],

    /*
    |--------------------------------------------------------------------------
    | date_field_approaching whitelist
    |--------------------------------------------------------------------------
    |
    | Deal date columns the cron date-field scanner (P2) may watch. Kept here so
    | the scanner and its validation share one source. MVP: deal only.
    |
    */
    'date_fields' => [
        'deal' => [
            'expected_close_date',
            'expected_sign_date',
            'expected_payment_date',
            'closed_at',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound webhook (SSRF guard)
    |--------------------------------------------------------------------------
    |
    | allowed_ports — only these destination ports are permitted (empty list =
    | any port). allow_private — when true the private/loopback/link-local block
    | list is bypassed (self-hosted internal targets only; default false). The
    | full retry/signature infra is owned by integration-specialist; MVP ships a
    | plain POST plus this guard.
    |
    */
    'webhook' => [
        'allowed_ports' => [80, 443],
        'allow_private' => (bool) env('AUTOMATION_WEBHOOK_ALLOW_PRIVATE', false),
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | automation_runs retention
    |--------------------------------------------------------------------------
    |
    | How many days of automation_runs (the audit + idempotency journal) to keep.
    | The daily `automation:prune-runs` command (routes/console.php) deletes rows
    | older than this by created_at; its --days flag overrides this value for
    | ad-hoc pruning. Keeps the table bounded as every trigger appends a row.
    |
    */
    'retention_days' => (int) env('AUTOMATION_RUN_RETENTION_DAYS', 90),
];
