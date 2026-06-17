<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Sales\Models\Pipeline;
use Database\Seeders\PipelineSeeder;

/**
 * Shared helpers for Inbox feature tests: seed the sales funnel and create a
 * channel/form wired for routing.
 */
trait InboxTestHelpers
{
    protected function seedSalesPipeline(): Pipeline
    {
        $this->seed(PipelineSeeder::class);

        return Pipeline::with('stages')->where('name', 'Продажи')->firstOrFail();
    }

    protected function newStageId(Pipeline $pipeline): int
    {
        return (int) $pipeline->stages->firstWhere('code', 'new')->id;
    }

    /**
     * A web_form channel wired to the sales `new` stage with the given owner.
     */
    protected function makeWebFormChannel(?User $owner = null, ?Pipeline $pipeline = null): Channel
    {
        $pipeline ??= $this->seedSalesPipeline();

        return Channel::factory()->create([
            'kind' => ChannelKind::WebForm,
            'default_owner_id' => $owner?->id,
            'default_pipeline_id' => $pipeline->id,
            'default_stage_id' => $this->newStageId($pipeline),
        ]);
    }

    protected function makeForm(Channel $channel, string $slug = 'lead-form', ?array $fields = null): Form
    {
        return Form::factory()->create([
            'public_slug' => $slug,
            'channel_id' => $channel->id,
            'fields' => $fields ?? [
                ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
                ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => false],
            ],
        ]);
    }
}
