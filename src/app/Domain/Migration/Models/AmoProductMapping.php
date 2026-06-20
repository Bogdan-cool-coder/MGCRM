<?php

declare(strict_types=1);

namespace App\Domain\Migration\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hand-curated mapping of an AMO "Продукт" enum option to an MGCRM catalog
 * product/plan. Temporary migration bounded-context (dropped at M12).
 *
 * action: map (use catalog_product_id/catalog_plan_id) | skip | other.
 */
class AmoProductMapping extends Model
{
    protected $table = 'amo_product_mappings';

    protected $fillable = [
        'amo_enum_id',
        'amo_value',
        'catalog_product_id',
        'catalog_plan_id',
        'action',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amo_enum_id' => 'integer',
            'catalog_product_id' => 'integer',
            'catalog_plan_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'catalog_product_id');
    }

    /**
     * @return BelongsTo<ProductPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ProductPlan::class, 'catalog_plan_id');
    }
}
