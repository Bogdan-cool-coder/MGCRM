<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DedupCandidateResource — wraps a Contact or Company model when returned
 * as a dedup scan candidate. Surfaces fields useful for:
 *   - The merge step (main fields + per-field override UI)
 *   - The "Будут добавлены" append block (channels + aggregate counts)
 *   - The "Будут удалены" list (name + id)
 *   - Candidate table (key column, created_at date)
 *
 * Aggregate fields (open_deals_count, company_links_count, activities_count) are
 * set dynamically on the model by DedupService::scanAll* — they default to 0 if
 * the model was loaded without the aggregates (e.g., per-entity scan).
 *
 * For Companies: currentRequisite is eager-loaded by scanAllCompanies / scanCompany.
 */
class DedupCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Works for both Contact and Company
        $base = [
            'id' => $this->id,
            'created_at' => $this->created_at?->toIso8601String(),
            // Aggregate counts for the append-block (B-1).
            // Set dynamically by DedupService; default to 0 if absent.
            'open_deals_count' => (int) ($this->open_deals_count ?? 0),
            'company_links_count' => (int) ($this->company_links_count ?? 0),
            'activities_count' => (int) ($this->activities_count ?? 0),
        ];

        if ($this->resource instanceof Contact) {
            return $base + $this->contactFields();
        }

        if ($this->resource instanceof Company) {
            return $base + $this->companyFields();
        }

        // Fallback: duck-type by attribute presence (legacy behaviour)
        if (isset($this->full_name)) {
            return $base + $this->contactFields();
        }

        return $base + $this->companyFields();
    }

    /**
     * Contact-specific fields.
     * Channels (phone/email duplicates + tg) are included as a flat list
     * so the append-block can compute unique additions without extra requests.
     */
    private function contactFields(): array
    {
        // Load channels if the relation is already loaded (avoids N+1 when
        // DedupService is extended to eager-load them); fall back to main columns.
        $channels = [];
        if ($this->resource->relationLoaded('channels')) {
            $channels = $this->resource->channels
                ->map(fn ($ch) => ['type' => $ch->channel_type, 'value' => $ch->value])
                ->values()
                ->all();
        }

        return [
            'type' => 'contact',
            'full_name' => $this->full_name,
            'position' => $this->position,
            'email' => $this->email,
            'phone' => $this->phone,
            'tg_username' => $this->tg_username ?? null,
            'notes' => $this->notes,
            'source' => $this->source,
            'status' => $this->status?->value ?? $this->status,
            'channels' => $channels,
        ];
    }

    /**
     * Company-specific fields.
     * Includes current requisite fields for the "Реквизиты" section in the
     * merge-field table (3.4) and for grouping by account number.
     */
    private function companyFields(): array
    {
        // Requisite fields — loaded via currentRequisite eager-load.
        $requisite = $this->resource->relationLoaded('currentRequisite')
            ? $this->resource->currentRequisite
            : null;

        $bankDetails = is_array($requisite?->bank_details) ? $requisite->bank_details : [];

        // Channels for the append-block
        $channels = [];
        if ($this->resource->relationLoaded('channels')) {
            $channels = $this->resource->channels
                ->map(fn ($ch) => ['type' => $ch->channel_type, 'value' => $ch->value])
                ->values()
                ->all();
        }

        return [
            'type' => 'company',
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'short_name' => $this->short_name,
            'tax_id' => $this->tax_id,
            'city' => $this->city,
            'address' => $this->address,
            'website' => $this->website,
            'email' => $this->email,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'source' => $this->source,
            'channels' => $channels,
            // Requisite fields (3.4) — from current requisite if available.
            'requisite_account' => $bankDetails['account'] ?? null,
            'requisite_bank_code' => $bankDetails['bank_bic'] ?? $bankDetails['bank_code'] ?? null,
            'requisite_bank_name' => $bankDetails['bank_name'] ?? null,
            'requisite_label' => $requisite?->label,
        ];
    }
}
