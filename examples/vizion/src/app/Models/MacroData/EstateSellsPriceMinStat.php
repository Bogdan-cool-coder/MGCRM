<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSellsPriceMinStat extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sells_price_min_stat';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'calculation_date' => 'date',
            'price' => 'decimal:2',
            'area' => 'decimal:4',
        ];
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }
}
