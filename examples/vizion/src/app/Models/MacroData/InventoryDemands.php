<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryDemands extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'inventory_demands';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'date_added' => 'date',
            'date_status_changed' => 'date',
            'item_date_demand' => 'date',
            'item_date_plan' => 'date',
            'item_date_fact' => 'date',
            'item_date_received' => 'date',
            'item_price' => 'decimal:9',
            'item_quantity' => 'decimal:9',
            'item_summa' => 'decimal:9',
            'item_quantity_income' => 'decimal:9',
            'item_price_income' => 'decimal:0',
            'item_quantity_part' => 'decimal:9',
            'item_summa_part' => 'decimal:9',
            'item_quantity_outcome' => 'decimal:9',
            'demand_item_payed_summa' => 'decimal:6',
        ];
    }

    public function usersDemander(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'demander_id', 'id');
    }

    public function usersSupplier(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'supplier_id', 'id');
    }

    public function contactsSupplier(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'supplier_contact_id', 'id');
    }

    public function inventoryWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id', 'warehouse_id');
    }

    public function noms(): BelongsTo
    {
        return $this->belongsTo(Noms::class, 'noms_id', 'id');
    }

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'projects_tasks_id', 'projects_tasks_id');
    }

    public function inventoryDemandsParent(): BelongsTo
    {
        return $this->belongsTo(InventoryDemands::class, 'parent_id', 'id');
    }

    public function contacts(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'contacts_id');
    }
}
