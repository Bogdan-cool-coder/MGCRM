<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use Database\Factories\Catalog\ProductPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPrice extends Model
{
    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }

    protected $table = 'catalog_product_prices';

    protected $fillable = [
        'product_id',
        'plan_id',
        'currency_code',
        'amount',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', // integer kopecks — ARCHITECTURE.md §3
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'plan_id');
    }
}
