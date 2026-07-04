<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompanyListResource — the lean row shape for GET /api/companies (list).
 *
 * Data-Layer-Audit-2026-07 §3.5: the index() endpoint reused the FULL
 * CompanyResource, shipping ~20 legal/bank/requisite fields (legal_name,
 * director_*, tax_id, address, bank, account, …) plus notes + extra_fields on
 * EVERY row — none of which the companies table or its search pickers render (the
 * list reads name/country/city/category/tags/engagement/owner/…). Those card-only
 * fields are dropped here; the full CompanyResource still serves the company card
 * (show/store/update).
 *
 * Everything the list, its filter/sort columns and the /companies search pickers
 * read is preserved verbatim, so the row shape is behaviourally identical minus
 * the fat requisite payload. Relational objects (company_type / owner_user /
 * responsible_user / author) are still emitted when their relation is eager
 * loaded, matching the full resource's whenLoaded contract.
 */
class CompanyListResource extends JsonResource
{
    /** @var Company */
    public $resource;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,

            // Identity (name only — legal/short names are card-only requisites).
            'name' => $this->resource->name,

            // Contact (list may show phone/email; website/notes are card-only).
            'phone' => $this->resource->phone,
            'email' => $this->resource->email,
            'website' => $this->resource->website,

            // Geo
            'country_code' => $this->resource->country_code,
            'city' => $this->resource->city,

            // Classification
            'source' => $this->resource->source,
            'industry' => $this->resource->industry,
            'specialization' => $this->resource->specialization?->value,
            'company_type_id' => $this->resource->company_type_id,
            'company_type' => $this->whenLoaded('companyType', fn () => new CompanyTypeResource($this->resource->companyType)),

            // Marketing — acquisition channel
            'acquisition_channel_id' => $this->resource->acquisition_channel_id,
            'acquisition_channel' => $this->whenLoaded(
                'acquisitionChannel',
                fn () => $this->resource->acquisitionChannel
                    ? ['id' => $this->resource->acquisitionChannel->id, 'name' => $this->resource->acquisitionChannel->name]
                    : null,
            ),

            // Holding
            'holding_id' => $this->resource->holding_id,
            'holding_role' => $this->resource->holding_role?->value,

            // Ownership & authorship
            'responsible_user_id' => $this->resource->responsible_user_id,
            'owner_user_id' => $this->resource->owner_user_id,
            'created_by_id' => $this->resource->created_by_id,
            'department_id' => $this->resource->department_id,

            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->resource->responsibleUser ? [
                'id' => $this->resource->responsibleUser->id,
                'full_name' => $this->resource->responsibleUser->full_name,
            ] : null),
            'owner_user' => $this->whenLoaded('ownerUser', fn () => $this->resource->ownerUser ? [
                'id' => $this->resource->ownerUser->id,
                'full_name' => $this->resource->ownerUser->full_name,
            ] : null),
            'author' => $this->whenLoaded('creator', fn () => $this->resource->creator ? [
                'id' => $this->resource->creator->id,
                'full_name' => $this->resource->creator->full_name,
            ] : null),

            // Tags (list renders tag chips) — extra_fields is card-only, dropped.
            'tags' => $this->resource->tags ?? [],

            // Category cache
            'category_code' => $this->resource->category_code?->value,
            'turnover_rub' => $this->resource->turnover_rub,
            'category_recalc_at' => $this->resource->category_recalc_at?->toIso8601String(),

            // Engagement (B2)
            'last_activity_at' => $this->resource->last_activity_at?->toIso8601String(),
            'engagement_tier' => $this->computeEngagementTier()->value,

            // Client lifecycle (N5) — the list shows the client-status badge.
            'client_status' => $this->resource->client_status?->value,
            'unique_client_since' => $this->resource->unique_client_since?->toDateString(),
            'disconnected_at' => $this->resource->disconnected_at?->toIso8601String(),
            'disconnect_reason_id' => $this->resource->disconnect_reason_id,

            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Compute engagement tier as a pure function of last_activity_at and config
     * thresholds — identical to CompanyResource::computeEngagementTier() so the
     * list and card agree. No DB query — safe inside toArray().
     */
    private function computeEngagementTier(): EngagementTier
    {
        $lastActivity = $this->resource->last_activity_at;

        if ($lastActivity === null) {
            return EngagementTier::Cold;
        }

        $warmDays = (int) config('crm.engagement.company.warm_days', 30);
        $coldDays = (int) config('crm.engagement.company.cold_days', 90);
        $days = (int) $lastActivity->copy()->startOfDay()->diffInDays(now()->startOfDay());

        if ($days <= $warmDays) {
            return EngagementTier::Fresh;
        }

        if ($days <= $coldDays) {
            return EngagementTier::Cooling;
        }

        return EngagementTier::Cold;
    }
}
