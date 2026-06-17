<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\City;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin City */
class CityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'country_code' => $this->country_code,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
