<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyChannel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * CompanyChannelService — CRUD for company communication channels.
 * All business logic (uniqueness guard, list, create, update, delete) lives here.
 */
class CompanyChannelService
{
    /**
     * @return Collection<int, CompanyChannel>
     */
    public function list(Company $company): Collection
    {
        return $company->channels()->get();
    }

    /**
     * Create a channel for a company.
     * Enforces uniqueness on (company_id, channel_type, value).
     * If is_primary_for_channel=true, atomically unsets the flag on all other
     * channels of the same channel_type so there is at most one primary per type.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): CompanyChannel
    {
        $this->guardDuplicate($company, (string) $data['channel_type'], (string) $data['value']);

        if (! empty($data['is_primary_for_channel'])) {
            $this->clearPrimaryForType($company->id, (string) $data['channel_type']);
        }

        return $company->channels()->create($data);
    }

    /**
     * Update an existing channel.
     * Re-checks uniqueness if channel_type or value changes.
     * If is_primary_for_channel is being set to true, atomically unsets the flag
     * on all OTHER channels of the same channel_type for this company.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CompanyChannel $channel, array $data): CompanyChannel
    {
        $newType = $data['channel_type'] ?? $channel->channel_type->value;
        $newValue = $data['value'] ?? $channel->value;

        $typeChanged = (string) $newType !== $channel->channel_type->value;
        $valueChanged = (string) $newValue !== $channel->value;

        if ($typeChanged || $valueChanged) {
            $this->guardDuplicate($channel->company, (string) $newType, (string) $newValue, $channel->id);
        }

        if (! empty($data['is_primary_for_channel'])) {
            $this->clearPrimaryForType($channel->company_id, (string) $newType, $channel->id);
        }

        $channel->update($data);

        return $channel->fresh();
    }

    public function delete(CompanyChannel $channel): void
    {
        $channel->delete();
    }

    // ---- Private helpers ----

    /**
     * Unset is_primary_for_channel on all channels of $channelType for $companyId,
     * optionally skipping $excludeId (the channel being updated/created right after).
     * Atomic single UPDATE — no N+1.
     */
    private function clearPrimaryForType(int $companyId, string $channelType, ?int $excludeId = null): void
    {
        CompanyChannel::where('company_id', $companyId)
            ->where('channel_type', $channelType)
            ->where('is_primary_for_channel', true)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_primary_for_channel' => false]);
    }

    private function guardDuplicate(Company $company, string $channelType, string $value, ?int $excludeId = null): void
    {
        $query = CompanyChannel::where('company_id', $company->id)
            ->where('channel_type', $channelType)
            ->where('value', $value);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'value' => ['This channel value already exists for the company.'],
            ]);
        }
    }
}
