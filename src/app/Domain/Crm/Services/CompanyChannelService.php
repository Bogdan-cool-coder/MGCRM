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
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): CompanyChannel
    {
        $this->guardDuplicate($company, (string) $data['channel_type'], (string) $data['value']);

        return $company->channels()->create($data);
    }

    /**
     * Update an existing channel.
     * Re-checks uniqueness if channel_type or value changes.
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

        $channel->update($data);

        return $channel->fresh();
    }

    public function delete(CompanyChannel $channel): void
    {
        $channel->delete();
    }

    // ---- Private helpers ----

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
