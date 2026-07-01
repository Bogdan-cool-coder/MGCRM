<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // Identity
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'short_name' => $this->short_name,

            // Legal requisites
            'full_legal_form' => $this->full_legal_form,
            'legal_form' => $this->legal_form,
            'gender_ending_oe' => $this->gender_ending_oe,
            'director_position' => $this->director_position,
            'director_genitive' => $this->director_genitive,
            'director_short' => $this->director_short,
            'acts_basis' => $this->acts_basis,
            'tax_id_label' => $this->tax_id_label,
            'tax_id' => $this->tax_id,
            'address' => $this->address,
            'bank' => $this->bank,
            'bank_code_label' => $this->bank_code_label,
            'bank_code' => $this->bank_code,
            'account' => $this->account,

            // Contact
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'notes' => $this->notes,

            // Geo
            'country_code' => $this->country_code,
            'city' => $this->city,

            // Classification
            'source' => $this->source,
            'industry' => $this->industry,
            'specialization' => $this->specialization?->value,
            'company_type_id' => $this->company_type_id,
            'company_type' => $this->whenLoaded('companyType', fn () => new CompanyTypeResource($this->companyType)),

            // Marketing — acquisition channel
            'acquisition_channel_id' => $this->acquisition_channel_id,
            'acquisition_channel' => $this->whenLoaded(
                'acquisitionChannel',
                fn () => $this->acquisitionChannel
                    ? ['id' => $this->acquisitionChannel->id, 'name' => $this->acquisitionChannel->name]
                    : null,
            ),

            // Holding
            'holding_id' => $this->holding_id,
            'holding_role' => $this->holding_role?->value,

            // Ownership & authorship
            //   owner_user_id      — primary owner (used for row-level visibility scope)
            //   responsible_user_id — the person currently responsible for this account
            //   created_by_id      — original creator of the card (immutable)
            'responsible_user_id' => $this->responsible_user_id,
            'owner_user_id' => $this->owner_user_id,
            'created_by_id' => $this->created_by_id,
            'department_id' => $this->department_id,

            // Responsible user object (loaded via ->with('responsibleUser')).
            // Displayed in the "Ответственный" column in company lists.
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser ? [
                'id' => $this->responsibleUser->id,
                'full_name' => $this->responsibleUser->full_name,
            ] : null),

            // Owner user object (loaded via ->with('ownerUser')).
            'owner_user' => $this->whenLoaded('ownerUser', fn () => $this->ownerUser ? [
                'id' => $this->ownerUser->id,
                'full_name' => $this->ownerUser->full_name,
            ] : null),

            // Author (creator) user object — who originally created the card.
            // Immutable: never changes after creation.
            // Key: 'author' (loaded via ->with('creator')).
            // Used by the front to display the "Автор" column.
            'author' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
            ] : null),

            // Tags & Custom fields
            'tags' => $this->tags ?? [],
            'extra_fields' => $this->extra_fields ?? [],

            // Category cache
            'category_code' => $this->category_code?->value,
            'turnover_rub' => $this->turnover_rub,
            'category_recalc_at' => $this->category_recalc_at?->toIso8601String(),

            // Engagement (B2)
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'engagement_tier' => $this->computeEngagementTier()->value,

            // Contact links (when loaded)
            'contact_links' => $this->whenLoaded('contactLinks', fn () => ContactCompanyLinkResource::collection($this->contactLinks)),

            // Current requisite set (when loaded via with('currentRequisite'))
            'current_requisite' => $this->whenLoaded(
                'currentRequisite',
                fn () => $this->currentRequisite
                    ? new CompanyRequisiteResource($this->currentRequisite)
                    : null,
            ),

            // All requisite sets (when loaded via with('requisites'))
            'requisites' => $this->whenLoaded('requisites', fn () => CompanyRequisiteResource::collection($this->requisites)),

            // Communication channels (phone, email, website, etc.) — loaded on show()
            'channels' => $this->whenLoaded('channels', fn () => CompanyChannelResource::collection($this->channels)),

            // Client lifecycle (N5)
            'client_status' => $this->client_status?->value,
            'unique_client_since' => $this->unique_client_since?->toDateString(),
            'disconnected_at' => $this->disconnected_at?->toIso8601String(),
            'disconnect_reason_id' => $this->disconnect_reason_id,
            'disconnect_reason' => $this->whenLoaded(
                'disconnectReason',
                fn () => $this->disconnectReason
                    ? ['id' => $this->disconnectReason->id, 'name' => $this->disconnectReason->name]
                    : null,
            ),
            'disconnect_doc_id' => $this->disconnect_doc_id,

            // Deal totals (when set via ->additional(['deal_totals' => ...]) — B6)
            'deal_totals' => $this->additional['deal_totals'] ?? null,

            // KPI block (available on show() only — set via ->additional(['kpi' => ...]))
            // Fields:
            //   open_deals_count  — number of open (non-closed) deals linked to this company
            //   deals_sum         — base-currency total of open deals (kopecks); null if FX rate unavailable
            //   deals_sum_currency— ISO 4217 base currency for deals_sum
            //   employees_count   — number of contact links (employees) for this company
            //   documents_count   — number of non-archived documents linked to this company
            //   last_activity_at  — ISO 8601 timestamp of last engagement (from crm_companies column)
            'kpi' => $this->additional['kpi'] ?? null,

            // Number of direct subsidiary companies in the holding group (preview chip).
            // Available on show() only (set via ->additional(['holding_company_count' => ...])).
            'holding_company_count' => $this->additional['holding_company_count'] ?? null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Compute engagement tier as a pure function of last_activity_at and config thresholds.
     * No DB query — safe inside Resource::toArray(). Mirrors EngagementService::computeTier().
     */
    private function computeEngagementTier(): EngagementTier
    {
        $lastActivity = $this->last_activity_at;

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
