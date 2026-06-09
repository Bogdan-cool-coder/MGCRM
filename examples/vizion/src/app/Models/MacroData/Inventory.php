<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'inventory';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_added' => 'date',
            'date_received' => 'date',
        ];
    }

    public function inventoryDemands(): BelongsTo
    {
        return $this->belongsTo(InventoryDemands::class, 'demand_id', 'demand_id');
    }

    public function noms(): BelongsTo
    {
        return $this->belongsTo(Noms::class, 'noms_id', 'id');
    }
}
