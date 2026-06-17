<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Models\Contact;
use Database\Factories\Sales\DealContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DealContact — M2M link between a deal and a contact (own model, not a bare pivot).
 * At most one primary contact per deal (enforced by a partial unique index).
 */
class DealContact extends Model
{
    /** @use HasFactory<DealContactFactory> */
    use HasFactory;

    protected static function newFactory(): DealContactFactory
    {
        return DealContactFactory::new();
    }

    protected $table = 'deal_contacts';

    protected $fillable = [
        'deal_id',
        'contact_id',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    // ---- Relations ----

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
