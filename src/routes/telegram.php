<?php

declare(strict_types=1);

/** @var Nutgram $bot */

use App\Domain\Notification\Telegram\ApprovalCallbackHandler;
use App\Domain\Notification\Telegram\StartHandler;
use SergiX44\Nutgram\Nutgram;

/*
|--------------------------------------------------------------------------
| Nutgram Handlers (S2.9 — Telegram approval channel + account linking)
|--------------------------------------------------------------------------
|
| Thin bindings only. The real logic lives in App\Domain\Notification\Telegram
| handler classes (service layer per ARCHITECTURE.md). These bindings are loaded
| by NutgramServiceProvider on every Nutgram resolution, but long-polling
| (getUpdates) runs in EXACTLY ONE process — the dedicated `bot` container —
| so there is no 409 Conflict. Web/queue resolve the same singleton purely as
| an outgoing Bot API client.
|
*/

// Deeplink linking + greeting: /start link_<token> and bare /start.
$bot->onCommand('start {payload}', StartHandler::class)
    ->description('Привязка аккаунта и приветствие');

$bot->onCommand('start', StartHandler::class);

// Inline approval votes: apv:approve|reject|rework:{documentId}.
$bot->onCallbackQueryData('apv:{action}:{documentId}', ApprovalCallbackHandler::class);
