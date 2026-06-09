<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWarehouseStocks extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'inventory_warehouse_stocks';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'demand_date_added' => 'date',
            'summa_left' => 'decimal:18',
            'task_date_finish_fact' => 'date',
            'date_received' => 'date',
        ];
    }

    public function inventoryDemands(): BelongsTo
    {
        return $this->belongsTo(InventoryDemands::class, 'demand_id', 'demand_id');
    }

    public function inventoryWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'task_id', 'projects_tasks_id');
    }

    public function noms(): BelongsTo
    {
        return $this->belongsTo(Noms::class, 'noms_id', 'id');
    }
}
