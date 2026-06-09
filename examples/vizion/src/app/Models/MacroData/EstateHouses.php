<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateHouses extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_houses';
    protected $primaryKey = 'house_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'group_sellStart' => 'date',
        ];
    }

    public function geoCityComplex()
    {
        return $this->belongsTo(GeoCityComplex::class, 'geo_city_complex_id', 'geo_complex_id');
    }

    public function contactsSeller()
    {
        return $this->belongsTo(Contacts::class, 'seller_id', 'id');
    }

    public function estateSells()
    {
        return $this->hasMany(EstateSells::class, 'house_id', 'house_id');
    }
}
