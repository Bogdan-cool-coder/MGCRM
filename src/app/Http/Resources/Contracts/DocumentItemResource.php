<?php

declare(strict_types=1);

namespace App\Http\Resources\Contracts;

use App\Domain\Contracts\Models\DocumentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentItem
 */
class DocumentItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'product_id' => $this->product_id,
            'plan_id' => $this->plan_id,
            'name_snapshot' => $this->name_snapshot,
            'currency' => $this->currency,
            'qty' => $this->qty,
            'unit_price' => $this->unit_price,   // kopecks
            'line_total' => $this->line_total,   // kopecks
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
