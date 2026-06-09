<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Calls extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'calls';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'call_date' => 'timestamp',
            'callback_date' => 'timestamp',
        ];
    }

    public function contacts()
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function usersFirstManager()
    {
        return $this->belongsTo(Users::class, 'first_manager_id', 'id');
    }

    public function usersManager()
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_id', 'estate_buy_id');
    }

    public function estateAudience()
    {
        return $this->belongsTo(EstateAudience::class, 'audience_id', 'estate_audience_id');
    }

    public function callsCallback()
    {
        return $this->belongsTo(Calls::class, 'callback_id', 'calls_id');
    }

    public function usersCallback()
    {
        return $this->belongsTo(Users::class, 'callback_users_id', 'id');
    }
}
