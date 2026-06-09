<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDealsDocs extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals_docs';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'document_date' => 'date',
            'date_registration' => 'date',
            'prev_area' => 'decimal:2',
            'prev_summa' => 'decimal:2',
            'document_summa' => 'decimal:2',
            'document_area' => 'decimal:2',
        ];
    }

    public function estateDeals()
    {
        return $this->belongsTo(EstateDeals::class, 'deal_id', 'deal_id');
    }

    public function users()
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }
}
