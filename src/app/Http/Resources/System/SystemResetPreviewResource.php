<?php

declare(strict_types=1);

namespace App\Http\Resources\System;

use App\Support\System\Data\ResetPreviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response shape of GET /api/system/reset/preview (contract §6.1). Live per-category
 * counts + the prerequisite matrix so the FE drives checkbox logic from the API
 * (single source of truth — no FE-hardcoded dependency map).
 *
 * @mixin ResetPreviewData
 */
class SystemResetPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => $this->enabled,
            'confirmation_phrase' => $this->confirmationPhrase,
            'categories' => $this->categories,
        ];
    }
}
