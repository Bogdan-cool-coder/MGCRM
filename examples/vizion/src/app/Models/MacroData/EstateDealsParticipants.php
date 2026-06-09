<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDealsParticipants extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals_participants';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'deal_date_combined' => 'date',
        ];
    }

    public function contacts()
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function contactsResponsible()
    {
        return $this->belongsTo(Contacts::class, 'responsible_contacts_id', 'id');
    }
}
