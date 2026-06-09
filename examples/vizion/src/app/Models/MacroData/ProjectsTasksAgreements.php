<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectsTasksAgreements extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects_tasks_agreements';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'work_summa_agreement' => 'decimal:9',
            'inventory_default_summa_agreement' => 'decimal:9',
            'inventory_tolling_summa_agreement' => 'decimal:9',
            'inventory_contractor_summa_agreement' => 'decimal:9',
            'inventory_realization_summa_agreement' => 'decimal:9',
            'service_summa_agreement' => 'decimal:9',
            'equipment_summa_agreement' => 'decimal:9',
            'machine_summa_agreement' => 'decimal:9',
        ];
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'projects_tasks_id', 'projects_tasks_id');
    }
}
