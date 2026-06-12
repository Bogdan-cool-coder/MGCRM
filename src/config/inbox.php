<?php

// Inbox (S1.9) runtime configuration. Application code reads config('inbox.*')
// instead of hard-coding the whitelists/limits below (ARCHITECTURE.md §3 —
// env() only here). Mirrors the constants from examples/contracts inbox.py.

return [

    /*
    |--------------------------------------------------------------------------
    | Channel kinds
    |--------------------------------------------------------------------------
    | Allowed Channel.kind values (backed by App\Domain\Inbox\Enums\ChannelKind).
    */
    'channel_kinds' => ['tg', 'wa', 'email', 'web_form', 'api'],

    /*
    |--------------------------------------------------------------------------
    | Lead sources
    |--------------------------------------------------------------------------
    | Whitelist for Channel.default_lead_source. The kind-derived sources plus
    | the manual/import sources used elsewhere in the CRM.
    */
    'lead_sources' => ['tg', 'wa', 'email', 'form', 'api', 'manual', 'import'],

    /*
    |--------------------------------------------------------------------------
    | Sales entry stage
    |--------------------------------------------------------------------------
    | The stage code an inbound lead lands in when the channel declares no
    | default stage (the AmoCRM-style "Новые лиды"). Locked — see PipelineSeeder.
    */
    'sales_stage_code_new' => 'new',

    /*
    |--------------------------------------------------------------------------
    | Public form submission protection
    |--------------------------------------------------------------------------
    | honeypot_field         — hidden field; if filled, the submission is a bot.
    | dedup_window_seconds   — window for the stable external_id (double-click /
    |                          refresh dedup); same contact + slug in window → one Deal.
    | max_field_value_len    — per-value length cap (anti-garbage).
    | max_submission_fields  — cap on number of submitted keys.
    */
    'honeypot_field' => 'website',
    'form_dedup_window_seconds' => 6 * 60 * 60,
    'max_field_value_len' => 2000,
    'max_submission_fields' => 50,

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    | Per-IP attempts/minute for the public inbound endpoints (throttle:inbound).
    | Webhook keys additionally by channel id (channel:ip).
    */
    'rate_limit_per_minute' => env('INBOX_RATE_LIMIT_PER_MINUTE', 30),

];
