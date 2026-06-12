<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Template
 */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'kind' => $this->kind,
            'title' => $this->title,
            'content' => $this->content,
            'version' => $this->version,
            'category' => $this->category,
            'product_codes' => $this->product_codes,
            'country_codes' => $this->country_codes,
            'client_category_codes' => $this->client_category_codes,
            'department_ids' => $this->department_ids,
            'current_version' => TemplateVersionResource::make($this->whenLoaded('currentVersion')),
            'updated_by_user_id' => $this->updated_by_user_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
