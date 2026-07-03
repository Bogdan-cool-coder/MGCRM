<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Sales\Enums\MotivationCardItemKind;
use Database\Factories\Sales\MotivationCardItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MotivationCardItem — one salary-component row on a MotivationCard.
 *
 * Money = integer kopecks (plan/fact indicator + salary plan/fact columns).
 * `params` jsonb holds flexible per-kind config/metadata ONLY — never money
 * as float (see contract §2.2.1 for the per-kind shape).
 */
class MotivationCardItem extends Model
{
    /** @use HasFactory<MotivationCardItemFactory> */
    use HasFactory;

    protected static function newFactory(): MotivationCardItemFactory
    {
        return MotivationCardItemFactory::new();
    }

    protected $table = 'motivation_card_items';

    protected $fillable = [
        'motivation_card_id',
        'kind',
        'name',
        'plan_amount_kopecks',
        'fact_amount_kopecks',
        'salary_plan_kopecks',
        'salary_fact_kopecks',
        'currency',
        'params',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'kind' => MotivationCardItemKind::class,
            'plan_amount_kopecks' => 'integer',
            'fact_amount_kopecks' => 'integer',
            'salary_plan_kopecks' => 'integer',
            'salary_fact_kopecks' => 'integer',
            'params' => 'array',
            'sort' => 'integer',
        ];
    }

    // ---- Relations ----

    public function card(): BelongsTo
    {
        return $this->belongsTo(MotivationCard::class, 'motivation_card_id');
    }
}
