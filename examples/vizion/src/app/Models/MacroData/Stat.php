<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Stat extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'stat';
    protected $primaryKey = 'param_name';
    public $timestamps = false;
    public $incrementing = false;
}
