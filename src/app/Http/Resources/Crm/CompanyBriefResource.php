<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompanyBriefResource — the minimal company shape sideloaded on a
 * contact↔company link. Data-Layer-Audit-2026-07 §3.5: ContactCompanyLinkResource
 * previously embedded the FULL CompanyResource (~60 fields: all legal/bank
 * requisites, notes, tags, extra_fields, lifecycle) for EVERY link, on both the
 * contacts list (companyLinks per row) and every card that renders links. The
 * only consumer of a link's nested company is the display name (contacts list
 * column, contact-card companies panel), so the fat payload was pure overhead.
 *
 * This brief keeps {id, name, country_code, category_code} — name is what the FE
 * reads; country_code/category_code are a small forward-safety margin. The full
 * CompanyResource is still used everywhere a company is fetched directly.
 */
class CompanyBriefResource extends JsonResource
{
    /** @var Company */
    public $resource;

    /**
     * @return array{id: int, name: string, country_code: ?string, category_code: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'country_code' => $this->resource->country_code,
            'category_code' => $this->resource->category_code?->value,
        ];
    }
}
