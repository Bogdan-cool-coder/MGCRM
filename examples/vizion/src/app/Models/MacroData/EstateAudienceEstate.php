<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstateAudienceEstate extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_audience_estate';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function estateAudience(): BelongsTo
    {
        return $this->belongsTo(EstateAudience::class, 'audience_id', 'estate_audience_id');
    }

    public function estateBuys(): BelongsTo
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }
}
