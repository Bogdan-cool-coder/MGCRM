<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Services;

use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Inbox\Models\Channel;
use App\Domain\Inbox\Models\Form;
use App\Domain\Sales\Models\PipelineStage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ChannelService — all Channel CRUD logic. Controller is thin (authorize →
 * service → resource). The secret_token is generated here on create / regenerate
 * and never mutated through update().
 */
class ChannelService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return Channel::query()
            ->when(isset($filters['kind']), fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when(isset($filters['is_active']), fn (Builder $q) => $q->where('is_active', (bool) $filters['is_active']))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Channel
    {
        $this->validatePipelineStage(
            $data['default_pipeline_id'] ?? null,
            $data['default_stage_id'] ?? null,
        );

        $kind = $data['kind'] instanceof ChannelKind ? $data['kind'] : ChannelKind::from($data['kind']);
        $data['secret_token'] = $this->generateToken();
        $data['default_lead_source'] ??= $kind->defaultLeadSource();

        return Channel::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Channel $channel, array $data): Channel
    {
        // secret_token is immutable through update (regenerateToken only).
        unset($data['secret_token']);

        // Validate the resulting (pipeline, stage) pair, falling back to current.
        if (array_key_exists('default_pipeline_id', $data) || array_key_exists('default_stage_id', $data)) {
            $this->validatePipelineStage(
                array_key_exists('default_pipeline_id', $data) ? $data['default_pipeline_id'] : $channel->default_pipeline_id,
                array_key_exists('default_stage_id', $data) ? $data['default_stage_id'] : $channel->default_stage_id,
            );
        }

        $channel->update($data);
        $channel->refresh();

        return $channel;
    }

    /**
     * Delete a channel. Refused (409) when forms are attached and $force is
     * false — forms.channel_id is nullOnDelete, so deleting silently orphans
     * them (they stop creating deals). With $force the forms are kept (channel_id
     * nulled by the FK).
     */
    public function delete(Channel $channel, bool $force = false): void
    {
        if (! $force) {
            $formCount = Form::query()->where('channel_id', $channel->id)->count();
            if ($formCount > 0) {
                throw ValidationException::withMessages([
                    'channel' => "Channel has {$formCount} attached form(s); they will stop creating deals. Repeat with ?force=1 to confirm.",
                ])->status(409);
            }
        }

        $channel->delete();
    }

    public function regenerateToken(Channel $channel): Channel
    {
        $channel->update(['secret_token' => $this->generateToken()]);
        $channel->refresh();

        return $channel;
    }

    /**
     * The target stage must belong to the target pipeline (when both given).
     */
    public function validatePipelineStage(?int $pipelineId, ?int $stageId): void
    {
        if ($stageId === null) {
            return;
        }

        $stage = PipelineStage::query()->find($stageId);
        if ($stage === null) {
            throw ValidationException::withMessages([
                'default_stage_id' => 'Stage not found.',
            ]);
        }

        if ($pipelineId !== null && (int) $stage->pipeline_id !== $pipelineId) {
            throw ValidationException::withMessages([
                'default_stage_id' => 'Stage does not belong to the selected pipeline.',
            ]);
        }
    }

    /** url-safe 48-char token (≤ 64 column). */
    private function generateToken(): string
    {
        return Str::random(48);
    }
}
