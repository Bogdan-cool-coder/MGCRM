<?php

declare(strict_types=1);

namespace App\Http\Resources\Crm;

use App\Domain\Crm\Enums\EngagementTier;
use App\Domain\Crm\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'position' => $this->position,
            'phone' => $this->phone,
            'email' => $this->email,
            'tg_username' => $this->tg_username,
            'notes' => $this->notes,
            'source' => $this->source,
            'status' => $this->status?->value,
            'tags' => $this->tags ?? [],
            'extra_fields' => $this->extra_fields ?? [],
            'owner_id' => $this->owner_id,
            'created_by_id' => $this->created_by_id,

            // Responsible user object (owner — who is currently working with this contact).
            // Key: 'owner' (loaded via ->with('owner')).
            // Used by the front to display the "Ответственный" column.
            'owner' => $this->whenLoaded('owner', fn () => $this->owner ? [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ] : null),

            // Author (creator) user object — who originally created the card.
            // Immutable: never changes after creation.
            // Key: 'author' (loaded via ->with('creator')).
            // Used by the front to display the "Автор" column.
            'author' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'full_name' => $this->creator->full_name,
            ] : null),

            // Marketing — acquisition channel
            'acquisition_channel_id' => $this->acquisition_channel_id,
            'acquisition_channel' => $this->whenLoaded(
                'acquisitionChannel',
                fn () => $this->acquisitionChannel
                    ? ['id' => $this->acquisitionChannel->id, 'name' => $this->acquisitionChannel->name]
                    : null,
            ),

            // Engagement (B2)
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'engagement_tier' => $this->computeEngagementTier()->value,

            // Channels (when loaded — only in show(), not index()) — B3
            'channels' => $this->whenLoaded('channels', fn () => ContactChannelResource::collection($this->channels)),

            // Company links (when loaded)
            'company_links' => $this->whenLoaded('companyLinks', fn () => ContactCompanyLinkResource::collection($this->companyLinks)),

            // KPI block (available on show() only — set via ->additional(['kpi' => ...]))
            // Fields:
            //   deals_count      — total number of deals this contact participates in (via deal_contacts)
            //   deals_sum        — total deal amounts in base currency (kopecks); null if FX rate unavailable
            //   deals_sum_currency — ISO 4217 base currency for deals_sum
            //   last_touch_at    — ISO 8601 timestamp of last engagement (mirrors last_activity_at column)
            //   open_tasks_count — number of open (not closed, not done) task-like activities targeting this contact
            //   companies_count  — number of companies this contact is linked to
            'kpi' => $this->additional['kpi'] ?? null,

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

        $warmDays = (int) config('crm.engagement.contact.warm_days', 14);
        $coldDays = (int) config('crm.engagement.contact.cold_days', 45);
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
