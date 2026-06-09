<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectsTasksChecklists extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects_tasks_checklists';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'date',
            'checked_date_to' => 'date',
            'checked_date' => 'date',
        ];
    }

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'task_id', 'projects_tasks_id');
    }
}
