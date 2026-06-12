<?php

declare(strict_types=1);

namespace App\Http\Resources\Inbox;

use App\Domain\Inbox\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ChannelResource — list/get view. The secret_token is MASKED (preview only):
 * the full token is the sole defence of the unauthenticated webhook and is
 * never exposed here. The full token lives in ChannelSecretResource
 * (store / reveal / regenerate).
 *
 * @mixin Channel
 */
class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'secret_token_preview' => $this->maskedToken(),
            'config' => $this->config ?? [],
            'default_lead_source' => $this->default_lead_source,
            'default_owner_id' => $this->default_owner_id,
            'default_pipeline_id' => $this->default_pipeline_id,
            'default_stage_id' => $this->default_stage_id,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
