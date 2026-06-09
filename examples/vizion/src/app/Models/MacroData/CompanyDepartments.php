<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class CompanyDepartments extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'company_departments';
    protected $primaryKey = 'departments_id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function usersBoss()
    {
        return $this->belongsTo(Users::class, 'dep_boss_id', 'id');
    }
}
