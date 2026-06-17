<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,

            // Group: inline id + name for convenience
            'group_id' => $this->group_id,
            'group_name' => $this->whenLoaded('group', fn () => $this->group?->name),
            'group' => $this->whenLoaded('group', fn () => $this->group ? new ProductGroupResource($this->group) : null),

            'pricing_type' => $this->pricing_type?->value,
            'maps_to_product_code' => $this->maps_to_product_code,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,

            'plans' => $this->whenLoaded('plans', fn () => ProductPlanResource::collection($this->plans)),
            'prices' => $this->whenLoaded('prices', fn () => ProductPriceResource::collection($this->prices)),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
