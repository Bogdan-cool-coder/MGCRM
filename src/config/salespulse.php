<?php

declare(strict_types=1);

// SalesPulse (AMO oversight bot port) — Slice 0 runtime configuration.
//
// env() is only read here (ARCHITECTURE.md §3). Domain code reads config('salespulse.*')
// instead of hard-coding the stage meta / SLA thresholds below. This mirrors the
// constants from the action amo-assistant-bot (pipelines.py / formatting). The map
// is keyed by PipelineStage.code so it extends to a second funnel (AI Global) by
// adding rows — every lookup falls back to a sane default for unknown codes.

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    | The day window for plan/fact collection (spec §1.1) is the calendar day in
    | this timezone, NOT the app default. The AMO bot ran on Asia/Dubai and the
    | scheduler (a later slice) fires at Dubai wall-clock times. The "+4h" AMO
    | deadline hack is NOT ported — our due_at/completed_at are TZ-correct.
    */
    'timezone' => env('SALESPULSE_TIMEZONE', 'Asia/Dubai'),

    /*
    |--------------------------------------------------------------------------
    | SalesPulse Telegram bot (Slice 3 — SECOND, SEPARATE bot)
    |--------------------------------------------------------------------------
    | SalesPulse runs on its OWN bot token, distinct from the contract bot's
    | TELEGRAM_BOT_TOKEN. Two tokens = two independent getUpdates streams, so the
    | two bots never 409 each other. The long-polling INVARIANT still holds PER
    | TOKEN: exactly ONE process may poll the SalesPulse token (the `salespulse-bot`
    | compose service, replicas:1). An empty token leaves the bot idle on a
    | FakeNutgram fallback (the `salespulse:run` command / container must not crash
    | without a token) — mirrors config('crm.telegram') for the contract bot.
    |
    | env() is only read here (ARCHITECTURE.md §3); handlers read config('salespulse.*').
    */
    'bot' => [
        'token' => env('SALESPULSE_BOT_TOKEN') ?: null,
        'run_polling' => filter_var(env('SALESPULSE_RUN_POLLING', false), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Poll lock (single-poller guard — self-healing)
    |--------------------------------------------------------------------------
    | The `salespulse:run` poller takes a cluster-wide Cache lock so only ONE
    | getUpdates stream exists per token (defence in depth on top of replicas:1).
    |
    | Prod incident (fixed): a container killed mid-poll never ran its finally{}
    | release, so a NO-TTL lock stayed orphaned forever; every new container then
    | saw the held lock, exited 0, and `restart: unless-stopped` re-ran it ~every
    | 2s in a tight loop. The fix makes the lock SELF-HEAL:
    |
    |   - the lock carries a TTL (`lock_ttl`) so it cannot outlive a dead holder,
    |   - the live poller writes a heartbeat every `heartbeat_interval` seconds,
    |   - on startup a held-but-STALE lock (heartbeat older than `stale_after`) is
    |     auto-stolen instead of blocking — no manual --steal needed,
    |   - a held-and-FRESH lock is a real conflict → the command exits NON-ZERO so
    |     Docker's restart backoff applies (no exit-0 tight loop).
    |
    | All windows are in SECONDS. Defaults: heartbeat every 30s; a lock whose
    | heartbeat is >120s old is considered orphaned; the lock TTL (600s) is a
    | generous backstop so even a missed heartbeat key self-expires.
    */
    'poll_lock' => [
        'key' => 'salespulse:poll-lock',
        'lock_ttl' => (int) env('SALESPULSE_POLL_LOCK_TTL', 600),
        'heartbeat_interval' => (int) env('SALESPULSE_POLL_HEARTBEAT_INTERVAL', 30),
        'stale_after' => (int) env('SALESPULSE_POLL_STALE_AFTER', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Teams (spec §8 — caller → team → manager resolution)
    |--------------------------------------------------------------------------
    | A Team binds a Telegram chat to a set of MGCRM sales pipelines + a roster of
    | managers + the admin usernames. Decoded from SALESPULSE_TEAMS_JSON (a JSON
    | array, schema below) so ops can re-roster without a deploy; falls back to the
    | inline `teams` array when the env var is absent/invalid.
    |
    | Schema (spec §8):
    |   [{ "chat_id": "-100...", "name": "MACRO Global",
    |      "pipelines": [<mgcrm_pipeline_id>, ...],
    |      "admins": ["Bogdan_MACRO"],          // tg usernames, case-insensitive
    |      "managers": [{"user_id": <mgcrm>, "tg": "ilyarogov", "name": "Илья Рогов"}, ...]
    |   }]
    |
    | TeamResolver consumes this: team_by_chat(chat.id), manager_by_slug
    | (tg-username / first_name / user_id), is_admin (username ∈ admins).
    */
    'teams' => json_decode((string) env('SALESPULSE_TEAMS_JSON', '[]'), true) ?: [],

    /*
    |--------------------------------------------------------------------------
    | Private-chat TEST MODE (config-gated, off in prod)
    |--------------------------------------------------------------------------
    | A dev/QA convenience so the bot logic can be exercised in a 1-on-1 DM with
    | the test bot — WITHOUT a configured group chat. In a private chat the
    | Telegram chat.id equals the user's own id (never in TEAMS_JSON), so a normal
    | resolve yields no team and every command is silently ignored. Test mode adds
    | a SECOND, narrow resolution path:
    |
    |   test_mode.enabled && message is a PRIVATE chat && from.username ∈ admins
    |     → synthesise a "ТЕСТ" team whose chat_id is THIS private chat (replies go
    |       to the tester's DM), whose admin is the tester (full access, incl. the
    |       admin-only commands), and whose roster is the seeded test accounts
    |       (manager1/2/3@mgcrm.test from SalesPulseDemoSeeder).
    |
    | This NEVER affects real traffic: group chats resolve via TEAMS_JSON exactly
    | as before, and a private chat from a non-admin (or with the flag off) keeps
    | the old "silently ignore" behaviour. In prod SALESPULSE_TEST_MODE=false.
    |
    | `team.pipelines` is resolved at runtime by canonical pipeline NAME (the two
    | AMO funnels) → id, or the special marker 'all_active_sales' to use every
    | active sales pipeline. `team.managers[].email` is resolved to a live user_id
    | at runtime (a missing account is skipped with a log line). env() stays here.
    */
    'test_mode' => [
        'enabled' => filter_var(env('SALESPULSE_TEST_MODE', false), FILTER_VALIDATE_BOOL),

        // tg usernames (case-insensitive) allowed to drive the test team in a DM.
        'admins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SALESPULSE_TEST_ADMINS', 'Bogdan_MACRO')),
        ))),

        'team' => [
            'name' => 'ТЕСТ',

            // Resolve to ids at runtime: the two canonical AMO funnel names, or the
            // marker 'all_active_sales' for every active sales pipeline.
            'pipelines' => ['MACRO Global', 'MACRO AI Global'],

            // Mapped onto the seeded test accounts (resolve user_id by email; a
            // missing account is dropped with a log line).
            'managers' => [
                ['email' => 'manager1@mgcrm.test', 'tg' => 'manager1', 'name' => 'Менеджер 1'],
                ['email' => 'manager2@mgcrm.test', 'tg' => 'manager2', 'name' => 'Менеджер 2'],
                ['email' => 'manager3@mgcrm.test', 'tg' => 'manager3', 'name' => 'Менеджер 3'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cold-stage detection
    |--------------------------------------------------------------------------
    | A "cold" stage is the funnel's freeze bucket (spec §1.3 — position -1, a
    | downgrade when a real deal moves into it). We detect it primarily by code
    | so a second pipeline can declare its own cold code here. When a stage's
    | code is unknown, StageClassificationService falls back to a FLAG heuristic:
    | hidden_by_default && !is_won && !is_lost (true for the seeded `cold` stage,
    | false for `lost` which carries is_lost). Documented assumption: the flag
    | heuristic only fires for stages that have no explicit code entry below.
    |
    | All three seeded funnels (the locked "Продажи" plus the two AMO mirrors
    | "MACRO Global" / "MACRO AI Global" — AmoPipelineSeeder) name their freeze
    | bucket `cold`, so the single code covers every pipeline.
    */
    'cold_stage_codes' => ['cold'],

    /*
    |--------------------------------------------------------------------------
    | Stage meta — emoji + SLA thresholds (keyed by stage.code)
    |--------------------------------------------------------------------------
    | emoji        — stage colour glyph (spec §7), prepended to every stage label.
    | sla_days     — DAILY SLA: max days a deal may sit in this stage before the
    |                /dayresults SLA flag fires (spec §5.1).
    | sla_weekly   — WEEKLY SLA: top_stuck threshold for /weeklyreport (spec §5.2).
    |
    | The first block maps the locked "Продажи" funnel (PipelineSeeder). The second
    | block maps the two AMO mirror funnels (AmoPipelineSeeder — "MACRO Global" /
    | "MACRO AI Global"), whose codes (unsorted/partner/long_term/outbound/inbound/
    | qualification/schedule/walking/trial + shared meeting/cold/warm/hot/lost) get
    | their own rows so SLA flags + glyphs match the AMO bot 1:1 (spec §5.1/§5.2/§7).
    | The `success` win code shares the ⭐/0-SLA shape with "Продажи" won/paid.
    */
    'stages' => [
        // ---- Locked "Продажи" funnel (PipelineSeeder) ----
        // code              => [emoji, sla_days, sla_weekly]
        'new' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'qualify' => ['emoji' => '🟡', 'sla_days' => 5, 'sla_weekly' => 7],
        'schedule_meeting' => ['emoji' => '🟢', 'sla_days' => 3, 'sla_weekly' => 3],
        'meeting' => ['emoji' => '🟣', 'sla_days' => 1, 'sla_weekly' => 3],
        'cold' => ['emoji' => '🔵', 'sla_days' => 30, 'sla_weekly' => 30],
        'warm' => ['emoji' => '🟠', 'sla_days' => 3, 'sla_weekly' => 5],
        'hot' => ['emoji' => '🔴', 'sla_days' => 1, 'sla_weekly' => 2],
        'won' => ['emoji' => '⭐', 'sla_days' => 0, 'sla_weekly' => 0],
        'await_payment' => ['emoji' => '⭐', 'sla_days' => 0, 'sla_weekly' => 0],
        'paid' => ['emoji' => '⭐', 'sla_days' => 0, 'sla_weekly' => 0],

        // ---- AMO mirror funnels (AmoPipelineSeeder) ----
        // sla_weekly = top_stuck threshold from the AMO spec (hot=2, warm/trial=5,
        // meeting/walking/schedule=3, qualif/inbound/outbound=7, cold=30). sla_days
        // tracks the §5.1 daily-flag windows. Intake buckets are all 🆕.
        'unsorted' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'partner' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'long_term' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'outbound' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'inbound' => ['emoji' => '🆕', 'sla_days' => 7, 'sla_weekly' => 7],
        'qualification' => ['emoji' => '🟡', 'sla_days' => 5, 'sla_weekly' => 7],
        'schedule' => ['emoji' => '🟢', 'sla_days' => 3, 'sla_weekly' => 3],
        'walking' => ['emoji' => '🟣', 'sla_days' => 1, 'sla_weekly' => 3],
        'trial' => ['emoji' => '🟠', 'sla_days' => 3, 'sla_weekly' => 5],
        'success' => ['emoji' => '⭐', 'sla_days' => 0, 'sla_weekly' => 0],

        // Shared terminal/freeze code used by every funnel.
        'lost' => ['emoji' => '☠️', 'sla_days' => 0, 'sla_weekly' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stage meta defaults
    |--------------------------------------------------------------------------
    | Fallback used by StageMeta when a stage.code is missing from the map above
    | (unknown future stages / null stage). 🔘 is a neutral glyph; the weekly SLA
    | of 7 matches the AMO "qualif/inbound/outbound" bucket (spec §5.2).
    */
    'stage_default' => ['emoji' => '🔘', 'sla_days' => 7, 'sla_weekly' => 7],

];
