<?php

declare(strict_types=1);

namespace Database\Factories\Inbox;

use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company().' channel',
            'kind' => ChannelKind::WebForm,
            'secret_token' => Str::random(48),
            'config' => [],
            'default_lead_source' => 'form',
            'default_owner_id' => null,
            'default_pipeline_id' => null,
            'default_stage_id' => null,
            'is_active' => true,
        ];
    }

    public function kind(ChannelKind $kind): static
    {
        return $this->state(fn (): array => [
            'kind' => $kind,
            'default_lead_source' => $kind->defaultLeadSource(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
