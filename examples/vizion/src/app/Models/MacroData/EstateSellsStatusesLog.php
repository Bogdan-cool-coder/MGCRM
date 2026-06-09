<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSellsStatusesLog extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sells_statuses_log';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'log_date' => 'timestamp',
            'deal_sum' => 'decimal:2',
        ];
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
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
