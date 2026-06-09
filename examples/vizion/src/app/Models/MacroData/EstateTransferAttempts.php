<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateTransferAttempts extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_transfer_attempts';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_added' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }


}
