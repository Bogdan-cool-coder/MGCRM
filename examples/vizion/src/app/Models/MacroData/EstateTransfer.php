<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateTransfer extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_transfer';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'plan_date' => 'timestamp',
            'finish_date' => 'timestamp',
            'formal_signed_date' => 'datetime',
        ];
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function usersResponsible()
    {
        return $this->belongsTo(Users::class, 'out_responsible_id', 'id');
    }
}
