<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateBuys extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_buys';
    protected $primaryKey = 'estate_buy_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_added' => 'date',
            'created_at' => 'datetime',
            'deal_sum' => 'decimal:2',
            'deal_price' => 'decimal:2',
            'deal_area' => 'decimal:4',
            'deal_sum_addons' => 'decimal:2',
            'deal_mediator_comission' => 'decimal:4',
            'ipoteka_rate' => 'decimal:3',
            'deal_date' => 'date',
        ];
    }

    public function contacts()
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function estateStatuses()
    {
        return $this->belongsTo(EstateStatuses::class, 'status', 'status_id');
    }

    public function estateStatusesReasons()
    {
        return $this->belongsTo(EstateStatusesReasons::class, 'status_reason_id', 'status_reason_id');
    }

    public function usersManager()
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    public function usersCallCenterManager()
    {
        return $this->belongsTo(Users::class, 'call_center_manager_id', 'id');
    }

    public function companyDepartments()
    {
        return $this->belongsTo(CompanyDepartments::class, 'departments_id', 'id');
    }

    public function estateSells()
    {
        return $this->belongsTo(EstateSells::class, 'estate_sell_id', 'estate_sell_id');
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function estateMeetingsFirst()
    {
        return $this->belongsTo(EstateMeetings::class, 'first_meetings_id', 'meetings_id');
    }

    public function estateMeetingsFirstHouse()
    {
        return $this->belongsTo(EstateMeetings::class, 'first_meetings_house_id', 'meetings_id');
    }

    public function estateMeetingsFirstOffice()
    {
        return $this->belongsTo(EstateMeetings::class, 'first_meetings_office_id', 'meetings_id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function contactsMediator()
    {
        return $this->belongsTo(Contacts::class, 'contacts_mediator_id', 'id');
    }

    public function contactsMediatorAgency()
    {
        return $this->belongsTo(Contacts::class, 'mediator_agency_id', 'id');
    }

    public function estateAdvertisingChannels()
    {
        return $this->belongsTo(EstateAdvertisingChannels::class, 'advertising_channel_id', 'id');
    }

    public function estateHousesFirstInterest()
    {
        return $this->belongsTo(EstateHouses::class, 'first_house_interest', 'house_id');
    }

    public function geoCityComplexFirstInterest()
    {
        return $this->belongsTo(GeoCityComplex::class, 'first_complex_interest', 'id');
    }

    /**
     * Lead attributes (area range, price range, etc.)
     * Custom relation added for BOMI calls report
     */
    public function estateBuysAttrs()
    {
        return $this->hasMany(EstateBuysAttr::class, 'estate_buy_id', 'estate_buy_id');
    }

    /**
     * Tags linked to this lead
     * Custom relation added for BOMI calls report
     */
    public function estateTagsRelation()
    {
        return $this->hasMany(EstateTags::class, 'estate_id', 'estate_buy_id');
    }

    /**
     * UTM data for this lead (1:1, present for ~100% of leads)
     * Used for utm_term and other utm_* columns in SABA registry report
     */
    public function estateBuysUtm()
    {
        return $this->hasOne(EstateBuysUtm::class, 'estate_buy_id', 'estate_buy_id');
    }

    /**
     * Tasks linked to this lead (FK: tasks.estate_id → estate_buys.estate_buy_id)
     * Used for relation_aggregate columns (e.g. meeting count by custom_type)
     */
    public function tasks()
    {
        return $this->hasMany(Tasks::class, 'estate_id', 'estate_buy_id');
    }

    /**
     * Meetings linked to this lead (FK: estate_meetings.estate_buy_id → estate_buys.estate_buy_id)
     * Used for relation_aggregate columns (e.g. meeting manager concat)
     */
    public function estateMeetings()
    {
        return $this->hasMany(EstateMeetings::class, 'estate_buy_id', 'estate_buy_id');
    }
}
