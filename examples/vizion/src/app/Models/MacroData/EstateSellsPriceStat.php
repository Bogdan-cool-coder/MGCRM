<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSellsPriceStat extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sells_price_stat';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'date_stat' => 'date',
        ];
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }
}
