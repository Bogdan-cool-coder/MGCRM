<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\PricingType;
use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $table = 'catalog_products';

    protected $fillable = [
        'code',
        'name',
        'description',
        'group_id',
        'pricing_type',
        'maps_to_product_code',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }
}
