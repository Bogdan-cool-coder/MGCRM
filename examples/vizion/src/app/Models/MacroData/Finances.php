<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Finances extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'finances';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'date_added' => 'datetime',
            'date_to' => 'datetime',
            'summa' => 'decimal:2',
            'accepted_summa' => 'decimal:2',
            'approved_date' => 'timestamp',
            'accepted_date' => 'timestamp',
        ];
    }

    public function financesTypes()
    {
        return $this->belongsTo(FinancesTypes::class, 'types_id', 'id');
    }

    public function financesSubtypes()
    {
        return $this->belongsTo(FinancesSubtypes::class, 'subtypes_id', 'id');
    }

    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function usersManager()
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    public function usersResponsManager()
    {
        return $this->belongsTo(Users::class, 'respons_manager_id', 'id');
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function contacts()
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function inventoryDemands()
    {
        return $this->belongsTo(InventoryDemands::class, 'inventory_demands_id', 'demand_id');
    }

    public function usersApproved()
    {
        return $this->belongsTo(Users::class, 'approved_by', 'id');
    }

    public function usersAccepted()
    {
        return $this->belongsTo(Users::class, 'accepted_by', 'id');
    }

    public function financesAccountsIn()
    {
        return $this->belongsTo(FinancesAccounts::class, 'account_in_id', 'account_id');
    }

    public function financesAccountsOut()
    {
        return $this->belongsTo(FinancesAccounts::class, 'account_out_id', 'account_id');
    }

    public function contactsIn()
    {
        return $this->belongsTo(Contacts::class, 'contact_in_id', 'id');
    }

    public function contactsOut()
    {
        return $this->belongsTo(Contacts::class, 'contact_out_id', 'id');
    }
}
