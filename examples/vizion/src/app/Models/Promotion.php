<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Promotion — per-company discount campaign bounding the discount applicable to
 * an HTML commercial proposal ([discount_min, discount_max]).
 *
 * M1: model + table only. CRUD controller, range validation and application
 * inside HtmlDocumentService land in M3.
 */
class Promotion extends Model
{
    use HasFactory;
    use HasTranslations;

    public const TYPE_PERCENT  = 'percent';
    public const TYPE_ABSOLUTE = 'absolute';

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'discount_type',
        'discount_min',
        'discount_max',
        'is_active',
        'sort_order',
        'created_by',
    ];

    /** @var array<int, string> */
    public $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'discount_min' => 'decimal:2',
            'discount_max' => 'decimal:2',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
