<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectsTasksEstimate extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects_tasks_estimate';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'work_summa_plan' => 'decimal:9',
            'inventory_summa_plan' => 'decimal:9',
            'service_summa_plan' => 'decimal:9',
            'equipment_summa_plan' => 'decimal:9',
            'machine_summa_plan' => 'decimal:9',
            'summa_overhead' => 'decimal:9',
            'summa_profit' => 'decimal:9',
            'summa_temporary' => 'decimal:9',
            'summa_winter' => 'decimal:9',
            'summa_other' => 'decimal:9',
            'summa_nds' => 'decimal:9',
            'summa_profit_overhead_from_machine_salary' => 'decimal:9',
        ];
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'projects_tasks_id', 'projects_tasks_id');
    }
}
