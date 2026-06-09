<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateAttributes extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_attributes';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function estateAttributesNames()
    {
        return $this->belongsTo(EstateAttributesNames::class, 'attr_id', 'id');
    }
}
