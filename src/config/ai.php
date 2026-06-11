<?php

// Per-cascade default model IDs. These are the *primary* models for each chat
// type and can be overridden per-environment via .env without a redeploy.
//
// MGCRM is Anthropic-only: the Z.AI / GLM provider and the mixed-provider
// cascades from the Vizion reference were intentionally removed (PLAN §3.1 —
// our cascade is Anthropic-only). Every stage runs on Anthropic Claude; the
// retry / timeout policy lives in the single `anthropic` provider namespace.
//
//   - report_generation / widget_generation / document_template (with tools):
//       Claude Sonnet (primary, retried).
//   - quick_qa (no tools): Claude Haiku (cheap/fast tier for short Q&A).
//
// Override any model with the matching env var when an environment needs a
// different model — no code change required.
$reportModel = env('AI_REPORT_MODEL', 'claude-sonnet-4-6');
$widgetModel = env('AI_WIDGET_MODEL', 'claude-sonnet-4-6');
$documentModel = env('AI_DOCUMENT_MODEL', 'claude-sonnet-4-6');
$quickQaModel = env('AI_QUICKQA_MODEL', 'claude-haiku-4-5-20251001');

return [
    // The "home" provider namespace. The retry service resolves the active
    // provider config (timeout / retry / cascades) from this key. MGCRM is
    // Anthropic-only, so this is always 'anthropic'.
    'provider' => env('AI_PROVIDER', 'anthropic'),

    'model' => env('AI_MODEL', $reportModel),

    'providers' => [
        // Pure-Anthropic namespace — the only provider MGCRM uses. Anthropic's
        // Prism provider implements asStream() (extended thinking + per-token
        // text deltas), so streaming endpoints are supported when wired up.
        'anthropic' => [
            'prism_provider' => 'anthropic',
            'default_model' => $reportModel,
            'supports_stream' => true,

            // Guzzle HTTP client timeout (seconds). Default Guzzle is 120s which
            // is too tight for multi-tool AI flows: a report_generation turn with
            // probe_data → create_report can run 200-300s on heavy datasets.
            // Aligned with nginx (fastcgi_read_timeout 600s).
            'timeout' => 420,

            // Single-provider cascades: each stage is Anthropic. The retry
            // service walks the stages in order, retrying within a stage before
            // advancing to the next.
            'report_generation' => [
                ['model' => $reportModel, 'attempts' => 3],
            ],
            'widget_generation' => [
                ['model' => $widgetModel, 'attempts' => 3],
            ],
            'document_template' => [
                ['model' => $documentModel, 'attempts' => 3],
            ],
            'quick_qa' => [
                ['model' => $quickQaModel, 'attempts' => 3],
            ],

            'retry' => [
                'delay_ms' => 3000,    // initial delay between retries (huge prompts need time)
                'multiplier' => 1.5,   // gentle exponential backoff
                'max_delay_ms' => 30000, // maximum delay cap
            ],
        ],
    ],

    // Dry-run + pre-validation policy for AI-generated reports. When enabled,
    // each create_report / update_report tool invocation runs the data fetch
    // immediately after the row is saved; a failing fetch tags the row as a
    // debug artefact (it is NOT deleted) and hides it from listings.
    // max_semantic_retries caps how many consecutive failed dry-runs the LLM may
    // attempt in one chat turn before the tool injects a stop-trying directive.
    'dry_run' => [
        'enabled' => env('AI_DRY_RUN_ENABLED', true),
        'max_semantic_retries' => env('AI_DRY_RUN_MAX_RETRIES', 2),
    ],

    // Error patterns that should trigger a retry.
    'retryable_errors' => [
        'rate limit',
        'rate-limit',
        'rate_limit',
        'timeout',
        'connection',
        '503',
        '502',
        '500',
        'temporary',
        'try again',
        'server error',
        'overloaded',
    ],
];
