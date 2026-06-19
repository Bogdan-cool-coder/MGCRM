<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use Database\Factories\Sales\DealProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DealProduct — line item on a deal. unit_price/currency are a price snapshot
 * taken at add time (kopecks). discount is a manual per-line reduction (kopecks).
 * amount = max(0, round(quantity * unit_price) - discount) — net of discount.
 * Deal.amount is denormalised from the sum of these rows (DealService::recalcAmount).
 */
class DealProduct extends Model
{
    /** @use HasFactory<DealProductFactory> */
    use HasFactory;

    protected static function newFactory(): DealProductFactory
    {
        return DealProductFactory::new();
    }

    protected $table = 'deal_products';

    protected $fillable = [
        'deal_id',
        'product_id',
        'plan_id',
        'quantity',
        'unit_price',
        'discount',
        'currency',
        'amount',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'integer', // kopecks
            'discount' => 'integer',   // kopecks (manual per-line discount)
            'amount' => 'integer',     // kopecks (net of discount)
            'sort_order' => 'integer',
        ];
    }

    // ---- Relations ----

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
