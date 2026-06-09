<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateAdvertisingChannels extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_advertising_channels';
    protected $primaryKey = 'id';
    public $timestamps = false;
}
