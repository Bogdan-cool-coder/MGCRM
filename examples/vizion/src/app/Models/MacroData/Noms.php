<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Noms extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'noms';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function nomsCategory()
    {
        return $this->belongsTo(NomsCategory::class, 'noms_parent_id', 'id');
    }
}
