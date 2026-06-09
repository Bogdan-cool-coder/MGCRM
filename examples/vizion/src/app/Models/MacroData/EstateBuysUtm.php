<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateBuysUtm extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_buys_utm';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_added' => 'date',
            'updated_at' => 'timestamp',
        ];
    }

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }
}
