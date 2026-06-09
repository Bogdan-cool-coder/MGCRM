<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDealsDiscounts extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals_discounts';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'amount' => 'decimal:2',
            'rule_value' => 'decimal:2',
        ];
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function promos()
    {
        return $this->belongsTo(Promos::class, 'promo_id', 'promo_id');
    }
}
