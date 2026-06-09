<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateRestoration extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_restoration';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
