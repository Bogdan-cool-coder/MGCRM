<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Projects extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'projects';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $casts = [
        'date_combined' => 'datetime',
        'project_date_finish_plan' => 'date',
        'project_date_finish' => 'date',
        'project_date_start' => 'date',
        'completeness' => 'decimal:2',
    ];

    public function projectsGroup(): BelongsTo
    {
        return $this->belongsTo(Projects::class, 'group_id', 'id');
    }
}
