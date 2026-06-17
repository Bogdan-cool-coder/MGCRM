<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\DealStageHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealStageHistory */
class DealStageHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_stage' => $this->whenLoaded('fromStage', fn () => $this->fromStage === null ? null : [
                'id' => $this->fromStage->id,
                'name' => $this->fromStage->name,
            ]),
            'to_stage' => $this->whenLoaded('toStage', fn () => [
                'id' => $this->toStage->id,
                'name' => $this->toStage->name,
            ]),
            'from_stage_id' => $this->from_stage_id,
            'to_stage_id' => $this->to_stage_id,
            'user' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => $this->user->id,
                'full_name' => $this->user->full_name,
            ]),
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
