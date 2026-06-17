<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TelegramLinkResource (S2.9) — the deeplink + TTL returned by
 * POST /api/me/telegram-link. The frontend renders the t.me link as a
 * "Привязать Telegram" button.
 *
 * Wraps the {deeplink, expires_in_minutes} array from TelegramLinkService.
 *
 * $wrap = null so the payload is a flat top-level object (project convention,
 * same as DashboardResource/KpiResource) rather than a {data: …} envelope.
 */
class TelegramLinkResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{deeplink: string, expires_in_minutes: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'deeplink' => $this->resource['deeplink'],
            'expires_in_minutes' => $this->resource['expires_in_minutes'],
        ];
    }
}
