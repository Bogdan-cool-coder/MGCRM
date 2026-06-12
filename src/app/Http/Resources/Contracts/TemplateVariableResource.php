<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\TemplateVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TemplateVariable
 */
class TemplateVariableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'help_text' => $this->help_text,
            'var_type' => $this->var_type,
            'options' => $this->options,
            'default_value' => $this->default_value,
            'required' => $this->required,
            'group' => $this->group,
            'sort_order' => $this->sort_order,
            'product_codes' => $this->product_codes,
            'country_codes' => $this->country_codes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
