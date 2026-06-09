<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDealsAddons extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals_addons';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'deal_date_combined' => 'date',
            'addon_price_default' => 'decimal:2',
            'addon_price' => 'decimal:2',
        ];
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }
}
