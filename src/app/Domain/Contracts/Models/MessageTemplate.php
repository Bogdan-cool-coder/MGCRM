<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\Contracts\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MessageTemplate — текстовый шаблон рассылки (TG / WA / Email / SMS).
 *
 * Метки подстановки: {{key}} с dot-notation пространства имён
 * (deal.name, company.inn, date.today и т.д.).
 *
 * Soft-delete — через is_active=false (не SoftDeletes trait).
 * Биндинги к каналам/воронкам/стадиям/типам активностей — через MessageTemplateBinding.
 */
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use HasFactory;

    protected static function newFactory(): MessageTemplateFactory
    {
        return MessageTemplateFactory::new();
    }

    protected $table = 'message_templates';

    protected $fillable = [
        'title',
        'subject',
        'body',
        'description',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ---- Relations ----

    /** @return HasMany<MessageTemplateBinding> */
    public function bindings(): HasMany
    {
        return $this->hasMany(MessageTemplateBinding::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    // ---- Scopes ----

    /** @param Builder<MessageTemplate> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
