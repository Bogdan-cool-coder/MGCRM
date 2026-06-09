<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateBuysAttr extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_buys_attr';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'timestamp',
        ];
    }
}
