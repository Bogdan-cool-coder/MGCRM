<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstatePromos extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_promos';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function promos()
    {
        return $this->belongsTo(Promos::class, 'promo_id', 'promo_id');
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }
}
