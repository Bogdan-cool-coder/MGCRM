<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use Database\Factories\Onboarding\CourseModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseModule extends Model
{
    /** @use HasFactory<CourseModuleFactory> */
    use HasFactory;

    protected static function newFactory(): CourseModuleFactory
    {
        return CourseModuleFactory::new();
    }

    protected $fillable = [
        'course_id',
        'title',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'course_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order');
    }
}
