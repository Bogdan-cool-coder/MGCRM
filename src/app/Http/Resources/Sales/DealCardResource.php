<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lightweight Kanban card. Deliberately small to keep board payloads cheap.
 *
 * @mixin Deal
 */
class DealCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'amount' => $this->amount, // kopecks
            'currency' => $this->currency,
            'stage_id' => $this->stage_id,
            'company_id' => $this->company_id,
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ]),
            // S1.6 placeholder for the next scheduled task.
            'next_task' => null,
        ];
    }
}
