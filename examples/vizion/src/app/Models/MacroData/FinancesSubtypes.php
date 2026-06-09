<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class FinancesSubtypes extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'finances_subtypes';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function financesTypes()
    {
        return $this->belongsTo(FinancesTypes::class, 'types_id', 'id');
    }
}
