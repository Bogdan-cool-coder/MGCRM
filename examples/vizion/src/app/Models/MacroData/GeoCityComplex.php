<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class GeoCityComplex extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'geo_city_complex';
    protected $primaryKey = 'geo_complex_id';
    public $timestamps = false;

    public function estateHouses()
    {
        return $this->hasMany(EstateHouses::class, 'geo_city_complex_id', 'geo_complex_id');
    }
}
