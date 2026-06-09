<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateBuysStatusesLog extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_buys_statuses_log';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'log_date' => 'timestamp',
            'deal_sum' => 'decimal:2',
        ];
    }

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function estateStatusesFrom()
    {
        return $this->belongsTo(EstateStatuses::class, 'status_from', 'status_id');
    }

    public function estateStatusesTo()
    {
        return $this->belongsTo(EstateStatuses::class, 'status_to', 'status_id');
    }
}
