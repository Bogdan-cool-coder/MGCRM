<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use Database\Factories\Sales\LostReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LostReason (registry of deal-loss reasons).
 * Model: fillable, casts, relations only.
 */
class LostReason extends Model
{
    /** @use HasFactory<LostReasonFactory> */
    use HasFactory;

    protected static function newFactory(): LostReasonFactory
    {
        return LostReasonFactory::new();
    }

    protected $table = 'lost_reasons';

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
