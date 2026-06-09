<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstateTags extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'estate_tags';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function tags(): BelongsTo
    {
        return $this->belongsTo(Tags::class, 'tags_id', 'id');
    }
}
