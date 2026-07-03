<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Iam\Models\User;
use App\Domain\Sales\Enums\FactSourceKind;
use App\Domain\Sales\Enums\MotivationCardStatus;
use Database\Factories\Sales\MotivationCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MotivationCard (МК) — header, one per user x period.
 *
 * All business logic (compute engine, status machine, CRUD orchestration)
 * lives in MotivationCardService. Model: fillable, casts, relations only.
 */
class MotivationCard extends Model
{
    /** @use HasFactory<MotivationCardFactory> */
    use HasFactory;

    protected static function newFactory(): MotivationCardFactory
    {
        return MotivationCardFactory::new();
    }

    protected $table = 'motivation_cards';

    protected $fillable = [
        'user_id',
        'pipeline_id',
        'period_year',
        'period_month',
        'status',
        'base_currency',
        'fact_source',
        'supervisor_user_id',
        'finalized_at',
        'finalized_by_user_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'status' => MotivationCardStatus::class,
            'fact_source' => FactSourceKind::class,
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MotivationCardItem::class)->orderBy('sort');
    }
}
