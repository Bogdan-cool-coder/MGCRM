<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Source */
class SourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
