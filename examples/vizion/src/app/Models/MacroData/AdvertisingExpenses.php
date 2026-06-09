<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class AdvertisingExpenses extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'advertising_expenses';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'expenses_date' => 'date',
            'expenses_summa' => 'decimal:2',
        ];
    }
}
