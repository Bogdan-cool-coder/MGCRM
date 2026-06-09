<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectsTasks extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects_tasks';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_combined' => 'datetime',
            'date_start' => 'date',
            'date_finish' => 'date',
            'date_start_fact' => 'date',
            'date_finish_fact' => 'date',
            'date_inspected' => 'date',
            'task_quality_accepted_date' => 'date',
            'task_finished_action_date' => 'date',
        ];
    }

    public function projectsTasksParent(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'projects_tasks_id', 'projects_tasks_id');
    }

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }

    public function usersInspected(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_inspected', 'id');
    }
}
