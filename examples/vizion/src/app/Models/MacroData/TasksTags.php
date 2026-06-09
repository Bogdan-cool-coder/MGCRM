<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TasksTags extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'tasks_tags';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function tasks(): BelongsTo
    {
        return $this->belongsTo(Tasks::class, 'tasks_id', 'id');
    }

    public function tags(): BelongsTo
    {
        return $this->belongsTo(Tags::class, 'tags_id', 'id');
    }
}
