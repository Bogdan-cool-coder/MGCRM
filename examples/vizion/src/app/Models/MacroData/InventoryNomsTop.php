<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class InventoryNomsTop extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'inventory_noms_top';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'item_avg_price' => 'decimal:13',
        ];
    }

}
