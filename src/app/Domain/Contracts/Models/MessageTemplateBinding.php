<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Factories\Contracts\MessageTemplateBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MessageTemplateBinding — одна запись = одно ограничение привязки шаблона.
 *
 * Матч: AND-логика по всем непустым полям биндинга.
 * Score = количество совпавших непустых полей (чем больше — тем точнее).
 * Wildcard-биндинг (все поля NULL) → score=0, fallback последнего шанса.
 *
 * Immutable после создания: нет updated_at, нет update-методов.
 */
class MessageTemplateBinding extends Model
{
    /** @use HasFactory<MessageTemplateBindingFactory> */
    use HasFactory;

    protected static function newFactory(): MessageTemplateBindingFactory
    {
        return MessageTemplateBindingFactory::new();
    }

    protected $table = 'message_template_bindings';

    /** Нет updated_at — биндинг immutable. */
    public $timestamps = false;

    protected $fillable = [
        'message_template_id',
        'channel_kind',
        'pipeline_id',
        'pipeline_stage_id',
        'activity_type',
        'automation_slot',
    ];

    protected function casts(): array
    {
        return [
            'channel_kind' => ChannelKind::class,
            'activity_type' => ActivityType::class,
            'created_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    /** @return BelongsTo<MessageTemplate, $this> */
    public function messageTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }
}
