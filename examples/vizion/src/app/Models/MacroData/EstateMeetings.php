<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateMeetings extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_meetings';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'date_added' => 'timestamp',
            'meeting_date' => 'date',
        ];
    }

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }

    public function contacts()
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function estateHousesComplex()
    {
        return $this->belongsTo(EstateHouses::class, 'complex_id', 'complex_id');
    }
}
