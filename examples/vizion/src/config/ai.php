<?php

// Per-cascade default model IDs. These are the *primary* models for each chat
// type and can be overridden per-environment via .env without a redeploy.
//
// TEMPORARY (2026-05-29): the three TOOL-CALLING cascades lead with GLM-5.1
// (Z.AI), with Anthropic Claude Sonnet 4.6 as the FALLBACK stage. This reverts
// the earlier Anthropic-primary order because our Anthropic plan caps input at
// ~30K tokens/minute (ITPM): a single report_generation turn re-sends the
// ~250 KB REPORTS_GUIDE on every tool step and pushes ~200-450K input tokens,
// so Sonnet-primary was perpetually rate-limited. GLM has the larger budget for
// these huge prompts. To restore Sonnet-primary once the Anthropic ITPM tier is
// upgraded, either set the AI_REPORT_MODEL / AI_WIDGET_MODEL / AI_DOCUMENT_MODEL
// env vars + swap the cascade stage order back, or override per-env.
//
//   - report_generation / widget_generation / document_template (with tools):
//       GLM-5.1 (Z.AI) primary, Anthropic Claude Sonnet 4.6 fallback.
//   - quick_qa (no tools): Anthropic Claude Haiku 4.5 primary, GLM-5.1 fallback.
//       (Unchanged: the quick_qa prompt is small and never approaches the 30K
//       ITPM wall, so Haiku stays primary there.)
//
// $toolModel is the Anthropic Sonnet model used as the FALLBACK stage of the
// three tool-calling cascades. $fallbackModel is GLM-5.1 (now the PRIMARY stage
// for those three). Override any of these with the matching env var when an
// environment needs a different model — no code change required.
$reportModel   = env('AI_REPORT_MODEL', 'claude-sonnet-4-6');
$widgetModel   = env('AI_WIDGET_MODEL', 'claude-sonnet-4-6');
$documentModel = env('AI_DOCUMENT_MODEL', 'claude-sonnet-4-6');
$quickQaModel  = env('AI_QUICKQA_MODEL', 'claude-haiku-4-5-20251001');

// Shared GLM model: GLM-5.1 via Z.AI (the `z` prism provider). Now the PRIMARY
// stage of the three tool-calling cascades AND the fallback stage of quick_qa.
// Z.AI's API model identifier for "GLM 5.1" is the bare lowercase `glm-5.1`.
$fallbackModel = env('AI_FALLBACK_MODEL', 'glm-5.1');

return [
    // The "home" provider namespace. AiRetryService resolves the active
    // provider config (timeout / retry / cascades) from this key. Cascades
    // below are MIXED-provider: individual stages carry their own `provider`
    // key (anthropic | glm), so the home namespace only supplies the retry /
    // timeout policy — NOT a single hard provider for every stage. Keep this
    // 'glm' so the cross-provider context-overflow fallback (below) still
    // treats Anthropic as the rescue provider with the bigger window.
    'provider' => env('AI_PROVIDER', 'glm'),

    'model' => env('AI_MODEL', $fallbackModel),

    'providers' => [
        'glm' => [
            'prism_provider' => 'z',
            'default_model' => $fallbackModel,

            // Whether the underlying Prism provider implements asStream().
            // Z.AI in the pinned Prism version (^0.100.1) inherits the default
            // Provider::stream() which throws PrismException
            // "Provider::stream is not supported by Z". Flip to true once the
            // upstream Z provider ships a real stream handler — no other code
            // change required. AiRetryService still has a defensive catch on
            // PrismException::isUnsupportedStream() as a safety net in case
            // this flag drifts out of sync with provider capabilities.
            'supports_stream' => false,

            // Guzzle HTTP client timeout (seconds). Default Guzzle is 120s which
            // is too tight for multi-tool AI flows: report_generation with
            // probe_data → create_report can run 200-300s on heavy datasets.
            // Aligned with nginx (host: proxy_read/send 480s, container nginx:
            // fastcgi_read 420s). cURL error 28 ("Operation timed out") on the
            // 3rd-4th message was caused by Guzzle hitting 120s before GLM
            // returned the final tool-call cycle.
            'timeout' => 420,

            // MIXED-provider cascades. Each stage names its own provider:
            //   - `anthropic` → Prism `anthropic` provider (Claude).
            //   - `glm`       → this namespace's prism_provider (`z`, Z.AI GLM).
            // A stage without `provider` inherits this namespace's
            // prism_provider (`z`). AiRetryService walks the stages in order,
            // retrying within a stage before advancing to the next.

            // report_generation (with tools): GLM-5.1 → Claude Sonnet 4.6.
            // TEMPORARY GLM-primary (2026-05-29): the huge REPORTS_GUIDE prompt
            // re-sent per tool step blows Anthropic's 30K ITPM tier, so GLM
            // (larger input budget) leads and Sonnet is the fallback. Revert to
            // Anthropic-primary once the ITPM tier is upgraded. NOTE: GLM has no
            // native streaming, so the primary path here is buffered (events:
            // started → thinking{connecting} → tool_call/result → final_message;
            // no per-chunk text_delta). final_message/error is still guaranteed
            // by ChatService (always emits TYPE_FINAL_MESSAGE after the buffered
            // call) / ProcessChatMessageJob (terminal error event on throw).
            'report_generation' => [
                ['provider' => 'glm', 'model' => $fallbackModel, 'attempts' => 3],
                ['provider' => 'anthropic', 'model' => $reportModel, 'attempts' => 2],
            ],

            // widget_generation (with tools): GLM-5.1 → Claude Sonnet 4.6.
            // TEMPORARY GLM-primary (2026-05-29) — same ITPM rationale as
            // report_generation above.
            'widget_generation' => [
                ['provider' => 'glm', 'model' => $fallbackModel, 'attempts' => 3],
                ['provider' => 'anthropic', 'model' => $widgetModel, 'attempts' => 2],
            ],

            // document_template (with tools): GLM-5.1 → Claude Sonnet 4.6.
            // TEMPORARY GLM-primary (2026-05-29) — same ITPM rationale as
            // report_generation above.
            'document_template' => [
                ['provider' => 'glm', 'model' => $fallbackModel, 'attempts' => 3],
                ['provider' => 'anthropic', 'model' => $documentModel, 'attempts' => 2],
            ],

            // quick_qa (no tools): Claude Haiku 4.5 → GLM-5.1. Haiku is the
            // cheap/fast tier for short textual Q&A; GLM-5.1 is the fallback.
            'quick_qa' => [
                ['provider' => 'anthropic', 'model' => $quickQaModel, 'attempts' => 2],
                ['provider' => 'glm', 'model' => $fallbackModel, 'attempts' => 3],
            ],

            'retry' => [
                'delay_ms' => 3000, // initial delay between retries (huge prompts need time)
                'multiplier' => 1.5, // gentle exponential backoff
                'max_delay_ms' => 30000, // maximum delay cap
            ],
        ],
        // Pure-Anthropic namespace. Two roles:
        //   1. Cross-provider overflow rescue: when a GLM stage trips a
        //      context-overflow (code 1261), AiRetryService re-runs the whole
        //      request against THIS namespace's cascade (Claude's 200K window).
        //      These cascades are intentionally pure-Anthropic — no GLM
        //      fallback, because a prompt that overflowed GLM would overflow it
        //      again, so re-adding a GLM stage here would be futile.
        //   2. Manual override: set AI_PROVIDER=anthropic to route every
        //      request straight to Claude (no GLM fallback at all).
        'anthropic' => [
            'prism_provider' => 'anthropic',
            'default_model' => $reportModel,
            // Anthropic's Prism provider implements asStream() — extended
            // thinking + per-token text deltas are surfaced through the SSE
            // event log on the frontend.
            'supports_stream' => true,
            'timeout' => 420, // see 'glm.timeout' for rationale
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
                'delay_ms' => 3000,
                'multiplier' => 1.5,
                'max_delay_ms' => 30000,
            ],
        ],
    ],

    // Dry-run + pre-validation policy for AI-generated reports.
    //
    // When `enabled`, every create_report / update_report tool invocation runs
    // ReportDataService::getData($report, $company, $user, ['page'=>1, 'per_page'=>1])
    // immediately after the Report row is saved. If getData() throws, the tool
    // result returns success=false and the Report is tagged with metadata.dry_run_failed=true
    // (it is NOT deleted — kept as a debug artefact, hidden from ReportController::index).
    //
    // `max_semantic_retries` caps how many consecutive create_report/update_report
    // calls in one chat turn may return success=false before ReportTool injects a
    // stop-trying directive into the tool result. The AI sees that directive on
    // its next step and emits a final assistant message instead of looping.
    'dry_run' => [
        'enabled' => env('AI_DRY_RUN_ENABLED', true),
        'max_semantic_retries' => env('AI_DRY_RUN_MAX_RETRIES', 2),
    ],

    // Cross-provider fallback on context overflow.
    //
    // The three tool-calling cascades lead with GLM-5.1 (~128K window), so the
    // PRIMARY stage is the overflow risk: a heavy report_generation turn
    // (REPORTS_GUIDE ~250 KB + probe_data + history) can trip GLM's limit with
    // HTTP 400 code 1261 ("Prompt exceeds max length"). Retrying the same prompt
    // on another GLM model is futile (same window). Instead, when a
    // context-overflow error is detected AND the active home provider is not
    // already the overflow-fallback provider, AiRetryService transparently
    // re-runs the whole request against the fallback provider's cascade
    // (Anthropic, 200K window). This is distinct from — and complementary to —
    // the in-cascade Anthropic *fallback stage*: that stage catches GLM
    // rate-limit / 5xx errors, while this overflow rescue catches the
    // prompt-too-long case that no GLM retry can fix.
    //
    // Note: because the home provider namespace stays 'glm' (it holds the mixed
    // cascades), the overflow rescue still flips to the 'anthropic' namespace —
    // the bigger-window provider — which is exactly what we want.
    //
    // Requires the fallback provider's API key to be configured (ANTHROPIC_API_KEY).
    // If the fallback provider also overflows or errors, the original error
    // surfaces and ProcessChatMessageJob turns it into a friendly
    // context_overflow message.
    'context_overflow_fallback' => [
        'enabled'  => env('AI_CONTEXT_OVERFLOW_FALLBACK', true),
        'provider' => env('AI_CONTEXT_OVERFLOW_FALLBACK_PROVIDER', 'anthropic'),
    ],

    // Error patterns that should trigger a retry
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
