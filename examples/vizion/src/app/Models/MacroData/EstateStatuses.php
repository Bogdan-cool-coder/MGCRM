<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateStatuses extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_statuses';
    protected $primaryKey = 'status_id';
    public $timestamps = false;
}
