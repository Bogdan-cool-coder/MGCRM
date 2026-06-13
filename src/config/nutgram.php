<?php

declare(strict_types=1);

// Nutgram (Telegram bot framework) configuration — MGCRM S2.9.
//
// env() is only read here (ARCHITECTURE.md §3). Domain code reads the bot token
// via config('crm.telegram.*'); it never touches env() directly. The same boot
// singleton (app(Nutgram::class)) is reused both for long-polling (only in the
// dedicated `bot` container) and for outgoing Bot API calls from web/queue
// processes — those never call getUpdates, so there is no 409 Conflict.

return [
    // The Telegram BOT api token (boevoy, already present in src/.env).
    // Coalesce an empty string to null so the NutgramServiceProvider's
    // `?? FakeNutgram::TOKEN` fallback kicks in — a missing/empty token must
    // leave the bot idle (placeholder token), NOT crash the `bot` container in a
    // restart loop (plan §З).
    'token' => env('TELEGRAM_BOT_TOKEN') ?: null,

    // Validate the incoming IP range is from a Telegram server (webhook mode only).
    'safe_mode' => env('APP_ENV', 'local') === 'production',

    // Extra / specific Nutgram configuration (api defaults, polling, etc.).
    'config' => [],

    // Auto-load handlers from routes/telegram.php on every Nutgram resolution.
    // Handlers are pure bindings; long-polling only runs in the `bot` container.
    'routes' => true,

    // Nutgram mixins disabled — we use explicit handler classes.
    'mixins' => false,

    // Namespace for nutgram:make-generated files. Our handlers live under the
    // Notification bounded-context Telegram namespace (DDD).
    'namespace' => app_path('Domain/Notification/Telegram'),

    // Log channel for bot errors.
    'log_channel' => env('TELEGRAM_LOG_CHANNEL', 'stack'),
];
