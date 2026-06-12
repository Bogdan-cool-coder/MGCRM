<?php

declare(strict_types=1);

namespace App\Domain\Org\Models;

use App\Domain\Iam\Models\User;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Org-unit node (department tree).
 *
 * M0 scaffold: just the self-referential tree + manager pointer + members, so
 * the User.department_id FK has a target and department-scoped visibility can be
 * built in M1. Schedules / vacations / production calendar land in M1.
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'parent_id',
        'manager_id',
    ];

    /**
     * Parent department (self-referential tree).
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child departments.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Department head.
     *
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Users belonging to this department.
     *
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
