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

            // Engagement (B2)
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'engagement_tier' => $this->computeEngagementTier()->value,

            // User (when loaded)
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'full_name' => $this->owner->full_name,
            ]),

            // Channels (when loaded — only in show(), not index()) — B3
            'channels' => $this->whenLoaded('channels', fn () => ContactChannelResource::collection($this->channels)),

            // Company links (when loaded)
            'company_links' => $this->whenLoaded('companyLinks', fn () => ContactCompanyLinkResource::collection($this->companyLinks)),

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
