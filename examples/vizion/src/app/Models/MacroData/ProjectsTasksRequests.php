<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectsTasksRequests extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects_tasks_requests';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'date_start' => 'date',
            'date_finish' => 'date',
            'date_start_fact' => 'date',
            'date_finish_fact' => 'date',
            'request_date_start' => 'date',
            'request_date_finish' => 'date',
            'date_approved' => 'date',
            'request_date_created' => 'date',
        ];
    }

    public function projects(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'projects_id', 'id');
    }

    public function projectsTasks(): BelongsTo
    {
        return $this->belongsTo(ProjectsTasks::class, 'projects_tasks_id', 'projects_tasks_id');
    }
}
