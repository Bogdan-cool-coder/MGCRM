<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWarehouse extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'inventory_warehouse';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }
}
