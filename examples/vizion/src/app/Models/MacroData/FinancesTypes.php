<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class FinancesTypes extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'finances_types';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
