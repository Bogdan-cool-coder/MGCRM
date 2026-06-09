<?php

namespace App\Models\MacroData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NomsCategory extends Model
{
    protected $connection = 'macrodata';
    protected $table = 'noms_category';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'updated_at' => 'timestamp',
        ];
    }

    public function nomsCategoryParent(): BelongsTo
    {
        return $this->belongsTo(NomsCategory::class, 'category_parent_id', 'id');
    }
}
