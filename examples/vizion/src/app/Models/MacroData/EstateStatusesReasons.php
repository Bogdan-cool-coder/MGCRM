<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateStatusesReasons extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_statuses_reasons';
    protected $primaryKey = 'status_reason_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }
}
