<?php

declare(strict_types=1);

namespace App\Http\Controllers\Notification;

use App\Domain\Notification\Services\TelegramLinkService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Notification\TelegramLinkResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TelegramLinkController (S2.9) — the user's own Telegram link management.
 *
 *   POST   /api/me/telegram-link  → issue a fresh deeplink token (TelegramLinkResource)
 *   DELETE /api/me/telegram       → unlink (clears telegram_user_id)
 *
 * Thin controller (ARCHITECTURE.md §3) — all logic in TelegramLinkService. Only
 * the authenticated owner can issue/unlink their own link (auth:sanctum).
 */
class TelegramLinkController extends Controller
{
    public function __construct(
        private readonly TelegramLinkService $linkService,
    ) {}

    public function issue(Request $request): TelegramLinkResource
    {
        $data = $this->linkService->issueFor($request->user());

        return new TelegramLinkResource($data);
    }

    public function unlink(Request $request): JsonResponse
    {
        $this->linkService->unlink($request->user());

        return response()->json(['telegram_user_id' => null]);
    }
}
