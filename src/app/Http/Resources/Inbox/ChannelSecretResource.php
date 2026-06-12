<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use Illuminate\Http\Request;

/**
 * ChannelSecretResource — ChannelResource plus the FULL secret_token. Returned
 * ONLY on store / reveal-token / regenerate-token (all admin/director). Never
 * used by list/get.
 */
class ChannelSecretResource extends ChannelResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'secret_token' => $this->secret_token,
        ];
    }
}
