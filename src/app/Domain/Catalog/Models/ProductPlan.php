<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BillingUnit;
use Database\Factories\Catalog\ProductPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductPlan extends Model
{
    /** @use HasFactory<ProductPlanFactory> */
    use HasFactory;

    protected static function newFactory(): ProductPlanFactory
    {
        return ProductPlanFactory::new();
    }

    protected $table = 'catalog_product_plans';

    protected $fillable = [
        'product_id',
        'code',
        'name',
        'unit',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit' => BillingUnit::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'plan_id');
    }
}
