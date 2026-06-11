<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\Catalog\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    protected static function newFactory(): ExchangeRateFactory
    {
        return ExchangeRateFactory::new();
    }

    protected $table = 'catalog_exchange_rates';

    public $timestamps = false; // only created_at

    protected $fillable = [
        'from_code',
        'to_code',
        'rate',
        'date',
        'source',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            // date is stored as plain Y-m-d string — no Carbon cast to avoid
            // datetime serialisation in SQLite (ARCHITECTURE.md lesson: "нормализация в PHP").
            'created_at' => 'datetime',
        ];
    }

    /**
     * Scope: latest rate for a currency pair on or before a given date.
     * $date must be a 'Y-m-d' string.
     */
    public function scopeLatestForPair(Builder $query, string $from, string $to, string $date): Builder
    {
        return $query
            ->where('from_code', $from)
            ->where('to_code', $to)
            ->where('date', '<=', $date) // date stored as Y-m-d string — string comparison works
            ->orderByDesc('date')
            ->limit(1);
    }
}
