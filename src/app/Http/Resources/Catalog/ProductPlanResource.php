<?php

declare(strict_types=1);

namespace App\Http\Resources\Catalog;

use App\Domain\Catalog\Models\ProductPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductPlan */
class ProductPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit?->value,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'prices' => $this->whenLoaded('prices', fn () => ProductPriceResource::collection($this->prices)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
