<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CompanyClientStatusLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyClientStatusLog */
class CompanyClientStatusLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'old_status' => $this->old_status?->value,
            'new_status' => $this->new_status->value,
            'changed_by' => $this->changed_by,
            'changed_by_user' => $this->whenLoaded(
                'changedBy',
                fn () => $this->changedBy
                    ? ['id' => $this->changedBy->id, 'full_name' => $this->changedBy->full_name]
                    : null,
            ),
            'changed_at' => $this->changed_at?->toIso8601String(),
            'reason_id' => $this->reason_id,
            'reason' => $this->whenLoaded(
                'reason',
                fn () => $this->reason
                    ? ['id' => $this->reason->id, 'name' => $this->reason->name]
                    : null,
            ),
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
