<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tasks extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'tasks';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'date_modified' => 'timestamp',
            'date_added' => 'date',
            'date_finish' => 'date',
            'date_finish_time' => 'time',
            'date_finish_fact' => 'date',
            'date_finish_fact_time' => 'time',
            'date_combined' => 'date',
            'hours_plan' => 'decimal:2',
            'hours_fact' => 'decimal:2',
        ];
    }

    public function contacts(): BelongsTo
    {
        return $this->belongsTo(Contacts::class, 'contacts_id', 'id');
    }

    public function usersAssigner(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'assigner_id', 'id');
    }

    public function usersManager(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    /**
     * Lead (estate_buy) this task belongs to (FK: tasks.estate_id → estate_buys.estate_buy_id)
     */
    public function estateBuy(): BelongsTo
    {
        return $this->belongsTo(EstateBuys::class, 'estate_id', 'estate_buy_id');
    }
}
