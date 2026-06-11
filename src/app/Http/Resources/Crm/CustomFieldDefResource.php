<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CustomFieldDef;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomFieldDef */
class CustomFieldDefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entity_scope' => $this->entity_scope?->value,
            'code' => $this->code,
            'label' => $this->label,
            'help_text' => $this->help_text,
            'field_type' => $this->field_type?->value,
            'options' => $this->options ?? [],
            'default_value' => $this->default_value,
            'required' => $this->required,
            'group' => $this->group,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
