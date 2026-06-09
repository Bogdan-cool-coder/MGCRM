<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSalesPlans extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sales_plans';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
