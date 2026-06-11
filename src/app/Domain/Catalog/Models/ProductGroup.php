<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\Catalog\ProductGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductGroup extends Model
{
    /** @use HasFactory<ProductGroupFactory> */
    use HasFactory;

    protected static function newFactory(): ProductGroupFactory
    {
        return ProductGroupFactory::new();
    }

    protected $table = 'catalog_product_groups';

    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'group_id');
    }
}
