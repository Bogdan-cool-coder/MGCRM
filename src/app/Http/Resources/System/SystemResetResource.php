<?php

declare(strict_types=1);

namespace App\Http\Resources\System;

use App\Support\System\Data\ResetResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape of POST /api/system/reset (contract §6.2 success). Best-effort:
 * `deleted` is per-category (committed only), `failed` names rolled-back categories.
 * `requires_relogin` is always false for the selective reset — users/tokens are
 * never touched (contract §2), so the admin's session survives (unlike full-wipe).
 *
 * @mixin ResetResultData
 */
class SystemResetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reset' => true,
            'deleted' => $this->deleted,
            'total_deleted' => $this->totalDeleted(),
            'failed' => $this->failed,
            'requires_relogin' => false,
        ];
    }
}
