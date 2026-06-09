<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateAudience extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_audience';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

}
