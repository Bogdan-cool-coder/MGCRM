<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateHousesPriceStat extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_houses_price_stat';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'month_stat_date' => 'date',
        ];
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }
}
