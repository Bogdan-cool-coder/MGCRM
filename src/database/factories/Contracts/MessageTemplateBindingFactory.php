<?php

declare(strict_types=1);

namespace Database\Factories\Contracts;

use App\Domain\Contracts\Models\MessageTemplate;
use App\Domain\Contracts\Models\MessageTemplateBinding;
use App\Domain\Inbox\Enums\ChannelKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplateBinding>
 */
class MessageTemplateBindingFactory extends Factory
{
    protected $model = MessageTemplateBinding::class;

    public function definition(): array
    {
        return [
            'message_template_id' => MessageTemplate::factory(),
            'channel_kind' => null,
            'pipeline_id' => null,
            'pipeline_stage_id' => null,
            'activity_type' => null,
            'automation_slot' => null,
        ];
    }

    /**
     * Binding for a specific channel kind.
     */
    public function forChannel(ChannelKind $kind): static
    {
        return $this->state(['channel_kind' => $kind->value]);
    }

    /**
     * Binding for a specific automation slot.
     */
    public function forSlot(string $slot): static
    {
        return $this->state(['automation_slot' => $slot]);
    }

    /**
     * Wildcard binding (all fields null — matches any context, lowest priority).
     */
    public function wildcard(): static
    {
        return $this->state([
            'channel_kind' => null,
            'pipeline_id' => null,
            'pipeline_stage_id' => null,
            'activity_type' => null,
            'automation_slot' => null,
        ]);
    }
}
