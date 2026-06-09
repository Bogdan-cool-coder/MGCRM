<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Promos extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'promos';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'promo_discount' => 'decimal:2',
            'promo_date_from' => 'date',
            'promo_date_to' => 'date',
        ];
    }
}
