<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class Users extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'users';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function companyDepartments()
    {
        return $this->belongsTo(CompanyDepartments::class, 'departments_id', 'departments_id');
    }
}
