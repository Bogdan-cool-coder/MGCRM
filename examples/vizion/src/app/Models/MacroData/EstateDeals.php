<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDeals extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals';
    protected $primaryKey = 'deal_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'deal_sum' => 'decimal:2',
            'deal_price' => 'decimal:2',
            'deal_area' => 'decimal:4',
            'deal_sum_addons' => 'decimal:2',
            'deal_mediator_comission' => 'decimal:4',
            'ipoteka_rate' => 'decimal:3',
            'deal_date' => 'date',
            'deal_date_start' => 'date',
            'deal_date_cancelled' => 'date',
            'reserve_date' => 'date',
            'reserve_date_start' => 'date',
            'finances_income' => 'decimal:2',
            'finances_income_reserved' => 'decimal:2',
            'finances_income_mortgage' => 'decimal:2',
            'finances_other_income' => 'decimal:2',
        ];
    }

    public function estateDealsStatuses()
    {
        return $this->belongsTo(EstateDealsStatuses::class, 'deal_status', 'status_id');
    }

    public function estateBuys()
    {
        return $this->belongsTo(EstateBuys::class, 'estate_buy_id', 'estate_buy_id');
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function contactsSeller()
    {
        return $this->belongsTo(Contacts::class, 'seller_contacts_id', 'id');
    }

    public function usersDealManager()
    {
        return $this->belongsTo(Users::class, 'deal_manager_id', 'id');
    }

    public function usersDealCoManager()
    {
        return $this->belongsTo(Users::class, 'deal_co_manager_id', 'id');
    }

    public function contactsMediator()
    {
        return $this->belongsTo(Contacts::class, 'contacts_mediator_id', 'id');
    }

    public function contactsBuy()
    {
        return $this->belongsTo(Contacts::class, 'contacts_buy_id', 'id');
    }

    public function estateStatuses()
    {
        return $this->belongsTo(EstateStatuses::class, 'status', 'status_id');
    }

    public function usersManager()
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    public function companyDepartments()
    {
        return $this->belongsTo(CompanyDepartments::class, 'departments_id', 'id');
    }

    public function usersRegistration()
    {
        return $this->belongsTo(Users::class, 'registration_users_id', 'id');
    }

    public function estateDealsBulk()
    {
        return $this->belongsTo(EstateDeals::class, 'bulk_deal_id', 'deal_id');
    }

    public function finances()
    {
        return $this->hasMany(Finances::class, 'deal_id', 'deal_id');
    }

    public function dealsParticipants()
    {
        return $this->hasMany(EstateDealsParticipants::class, 'deal_id', 'deal_id');
    }
}
