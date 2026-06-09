<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Tags extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'tags';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
