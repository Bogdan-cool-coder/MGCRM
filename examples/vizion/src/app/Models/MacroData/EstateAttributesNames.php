<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateAttributesNames extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_attributes_names';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
