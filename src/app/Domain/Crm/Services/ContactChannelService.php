<?php

declare(strict_types=1);

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\ContactChannel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * ContactChannelService — CRUD for contact communication channels.
 * All business logic (uniqueness guard, list, create, update, delete) lives here.
 */
class ContactChannelService
{
    /**
     * @return Collection<int, ContactChannel>
     */
    public function list(Contact $contact): Collection
    {
        return $contact->channels()->get();
    }

    /**
     * Create a channel for a contact.
     * Enforces uniqueness on (contact_id, channel_type, value).
     * If is_primary_for_channel=true, atomically unsets the flag on all other
     * channels of the same channel_type so there is at most one primary per type.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Contact $contact, array $data): ContactChannel
    {
        $this->guardDuplicate($contact, (string) $data['channel_type'], (string) $data['value']);

        if (! empty($data['is_primary_for_channel'])) {
            $this->clearPrimaryForType($contact->id, (string) $data['channel_type']);
        }

        return $contact->channels()->create($data);
    }

    /**
     * Update an existing channel.
     * Re-checks uniqueness if channel_type or value changes.
     * If is_primary_for_channel is being set to true, atomically unsets the flag
     * on all OTHER channels of the same channel_type for this contact.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ContactChannel $channel, array $data): ContactChannel
    {
        $newType = $data['channel_type'] ?? $channel->channel_type->value;
        $newValue = $data['value'] ?? $channel->value;

        $typeChanged = (string) $newType !== $channel->channel_type->value;
        $valueChanged = (string) $newValue !== $channel->value;

        if ($typeChanged || $valueChanged) {
            $this->guardDuplicate($channel->contact, (string) $newType, (string) $newValue, $channel->id);
        }

        if (! empty($data['is_primary_for_channel'])) {
            $this->clearPrimaryForType($channel->contact_id, (string) $newType, $channel->id);
        }

        $channel->update($data);

        return $channel->fresh();
    }

    public function delete(ContactChannel $channel): void
    {
        $channel->delete();
    }

    // ---- Private helpers ----

    /**
     * Unset is_primary_for_channel on all channels of $channelType for $contactId,
     * optionally skipping $excludeId (the channel being updated/created right after).
     * Atomic single UPDATE — no N+1.
     */
    private function clearPrimaryForType(int $contactId, string $channelType, ?int $excludeId = null): void
    {
        ContactChannel::where('contact_id', $contactId)
            ->where('channel_type', $channelType)
            ->where('is_primary_for_channel', true)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_primary_for_channel' => false]);
    }

    private function guardDuplicate(Contact $contact, string $channelType, string $value, ?int $excludeId = null): void
    {
        $query = ContactChannel::where('contact_id', $contact->id)
            ->where('channel_type', $channelType)
            ->where('value', $value);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'value' => ['This channel value already exists for the contact.'],
            ]);
        }
    }
}
