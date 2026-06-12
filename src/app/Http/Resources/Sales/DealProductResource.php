<?php

declare(strict_types=1);

namespace App\Http\Resources\Sales;

use App\Domain\Sales\Models\DealProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DealProduct */
class DealProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
            ]),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan === null ? null : [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ]),
            'product_id' => $this->product_id,
            'plan_id' => $this->plan_id,
            'quantity' => (float) $this->quantity,
            'unit_price' => $this->unit_price,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'sort_order' => $this->sort_order,
        ];
    }
}
