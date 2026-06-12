<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Iam\Enums\Role;
use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Sales\Enums\PipelineKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo inbound channel + public form for QA (idempotent — insert-missing keyed
 * by a stable slug/name, never truncate). The form has a fixed public_slug so a
 * QA curl can hit POST /api/forms/public/{slug}/submit deterministically.
 *
 * Must run after PipelineSeeder (sales pipeline + `new` stage) and AdminSeeder
 * (default owner). Re-running keeps the existing secret_token.
 */
class InboxSeeder extends Seeder
{
    /** Stable slug so QA always knows the public form URL. */
    public const DEMO_FORM_SLUG = 'demo-lead-form';

    public const DEMO_CHANNEL_NAME = 'Demo Web Form';

    public function run(): void
    {
        $owner = User::query()
            ->where('role', Role::Admin)
            ->orderBy('id')
            ->first()
                ?? User::query()->orderBy('id')->first();

        $pipeline = Pipeline::query()
            ->where('kind', PipelineKind::Sales->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $newStageId = $pipeline !== null
            ? PipelineStage::query()
                ->where('pipeline_id', $pipeline->id)
                ->where('code', 'new')
                ->value('id')
            : null;

        $channel = Channel::firstOrCreate(
            ['name' => self::DEMO_CHANNEL_NAME],
            [
                'kind' => ChannelKind::WebForm,
                'secret_token' => Str::random(48),
                'config' => [],
                'default_lead_source' => 'form',
                'default_owner_id' => $owner?->id,
                'default_pipeline_id' => $pipeline?->id,
                'default_stage_id' => $newStageId,
                'is_active' => true,
            ],
        );

        Form::firstOrCreate(
            ['public_slug' => self::DEMO_FORM_SLUG],
            [
                'name' => 'Заявка с сайта (демо)',
                'fields' => [
                    ['name' => 'name', 'label' => 'Имя', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false],
                    ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => false],
                    ['name' => 'message', 'label' => 'Сообщение', 'type' => 'textarea', 'required' => false],
                ],
                'channel_id' => $channel->id,
                'thank_you_text' => 'Спасибо! Мы свяжемся с вами.',
                'is_active' => true,
                'created_by_user_id' => $owner?->id,
            ],
        );
    }
}
