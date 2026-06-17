<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPlan;
use Database\Factories\Contracts\DocumentItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentItem — a single line item in a Document.
 *
 * name_snapshot / unit_price are immutable snapshots taken at the time of
 * item creation (copy from catalog). They do NOT follow price changes.
 *
 * line_total = round(qty * unit_price) — always in kopecks (integer).
 * qty is decimal(8,3) because quantities can be fractional (e.g. 1.5 hours).
 *
 * Document.subtotal / total are recalculated by DocumentService after every
 * add / update / delete of items.
 */
class DocumentItem extends Model
{
    /** @use HasFactory<DocumentItemFactory> */
    use HasFactory;

    protected static function newFactory(): DocumentItemFactory
    {
        return DocumentItemFactory::new();
    }

    protected $table = 'document_items';

    protected $fillable = [
        'document_id',
        'product_id',
        'plan_id',
        'name_snapshot',
        'currency',
        'qty',
        'unit_price',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // ---- Relations ----

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
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
