<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Inbox\Enums\ChannelKind;
use App\Domain\Sales\Models\Pipeline;
use App\Domain\Sales\Models\PipelineStage;
use Database\Factories\Inbox\ChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Channel — inbound receiving point. All business logic lives in ChannelService
 * and InboundRoutingService. Model: fillable, casts, relations + maskedToken().
 */
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected static function newFactory(): ChannelFactory
    {
        return ChannelFactory::new();
    }

    protected $table = 'channels';

    protected $fillable = [
        'name',
        'kind',
        'secret_token',
        'config',
        'default_lead_source',
        'default_owner_id',
        'default_pipeline_id',
        'default_stage_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ChannelKind::class,
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Masked token for wide listings: only the last 4 chars are revealed. The
     * full secret_token is the sole defence of the unauthenticated webhook, so
     * it must never leak to every reader — it is returned only on create /
     * reveal / regenerate (ChannelSecretResource).
     */
    public function maskedToken(): string
    {
        $token = $this->secret_token;

        if ($token === null || $token === '') {
            return '****';
        }

        return strlen($token) > 8 ? '****'.substr($token, -4) : '****';
    }

    // ---- Relations ----

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }

    public function defaultPipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'default_pipeline_id');
    }

    public function defaultStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'default_stage_id');
    }
}
