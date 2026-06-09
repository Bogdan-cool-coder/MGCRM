<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSellsAttr extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sells_attr';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

}
