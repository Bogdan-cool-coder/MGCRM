<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateDealsStatuses extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_deals_statuses';
    protected $primaryKey = 'status_id';
    public $timestamps = false;
}
