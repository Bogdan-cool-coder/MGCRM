<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateMortgage extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_mortgage';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'status_changed_at' => 'timestamp',
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'approved_percent' => 'decimal:2',
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
}
