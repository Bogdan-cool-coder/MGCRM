<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class EstateSalesPlansMetrics extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_sales_plans_metrics';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
            'plan_date' => 'date',
            'finances_income' => 'decimal:2',
            'price_m2' => 'decimal:2',
            'quantity' => 'decimal:2',
            'sum' => 'decimal:2',
            'area' => 'decimal:2',
            'deal_price' => 'decimal:2',
        ];
    }

    public function estateSalesPlans()
    {
        return $this->belongsTo(EstateSalesPlans::class, 'plan_id', 'sales_plan_id');
    }

    public function estateHousesComplex()
    {
        return $this->belongsTo(EstateHouses::class, 'complex_id', 'complex_id');
    }

    public function estateHouses()
    {
        return $this->belongsTo(EstateHouses::class, 'house_id', 'house_id');
    }

    public function usersManager()
    {
        return $this->belongsTo(Users::class, 'manager_id', 'id');
    }

    public function companyDepartments()
    {
        return $this->belongsTo(CompanyDepartments::class, 'departments_id', 'departments_id');
    }
}
