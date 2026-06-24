<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\ApprovalRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRoute
 */
class ApprovalRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'document_kind' => $this->document_kind,
            'template_id' => $this->template_id,
            'template_code' => $this->whenLoaded('template', fn () => $this->template?->code),
            'is_default' => $this->is_default,
            'stages' => $this->stages,
            'is_active' => $this->is_active,
            'created_by_user_id' => $this->created_by_user_id,
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
