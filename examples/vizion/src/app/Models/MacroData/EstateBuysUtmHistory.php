<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstateBuysUtmHistory extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_buys_utm_history';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'timestamp',
        ];
    }

    public function estateBuys(): BelongsTo
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }
}
