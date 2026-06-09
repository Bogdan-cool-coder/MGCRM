<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;

class CallsSubjects extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'calls_subjects';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function callsSubjectsFolder()
    {
        return $this->belongsTo(self::class, 'folder_id', 'id');
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }
}
