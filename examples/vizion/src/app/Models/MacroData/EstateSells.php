<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSells extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sells';
    protected $primaryKey = 'estate_sell_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'estate_area' => 'decimal:4',
            'estate_price' => 'decimal:4',
            'estate_price_action' => 'decimal:4',
            'estate_price_m2' => 'decimal:4',
            'estate_areaBti' => 'decimal:4',
            'estate_areaBti_koef' => 'decimal:4',
            'estate_area_inside' => 'decimal:4',
            'estate_areaBti_inside' => 'decimal:4',
            'estate_areaBti_terrace' => 'decimal:4',
            'estate_restoration_price' => 'decimal:4',
            'estate_dealAreaBeforeBtiRecalc' => 'decimal:4',
        ];
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function estateHousesSourceParent()
    {
        return $this->belongsTo(EstateHouses::class, 'source_parent_id', 'house_id');
    }

    public function contactsSeller()
    {
        return $this->belongsTo(Contacts::class, 'seller_contacts_id', 'id');
    }

    public function estateRestoration()
    {
        return $this->belongsTo(EstateRestoration::class, 'estate_restoration_id', 'id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function estateSellsAttrs()
    {
        return $this->hasMany(EstateSellsAttr::class, 'estate_sell_id', 'estate_sell_id');
    }
}
