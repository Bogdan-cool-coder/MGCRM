<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\CompanyType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyType */
class CompanyTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
