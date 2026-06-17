<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Country */
class CountryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'phone_prefix' => $this->phone_prefix,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
