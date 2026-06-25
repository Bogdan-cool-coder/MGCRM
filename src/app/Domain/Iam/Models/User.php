<?php

declare(strict_types=1);

namespace App\Domain\Iam\Models;

use App\Domain\Iam\Enums\Role;
use App\Domain\Org\Models\Department;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * MGCRM application user (Iam context).
 *
 * Authentication: Sanctum personal access tokens (Bearer). Roles/permissions:
 * spatie/laravel-permission (single role per user) on the `sanctum` guard — the
 * single authoritative store since IAM-1. The `role` attribute is NOT a column:
 * it is a non-persisted accessor backed by the user's single spatie role, with a
 * buffered mutator so `User::create(['role' => ...])` / `$user->role = ...`
 * (factory / seeders / UserService) keep working and sync the spatie grant on
 * save. TOTP 2FA secret + backup codes are encrypted at rest on the Laravel
 * APP_KEY and never leave the API (hidden + encrypted casts).
 *
 * No business logic lives here (ARCHITECTURE.md §1) — only fillable/hidden,
 * casts, relations, and the spatie-backed `role` virtual attribute.
 *
 * @property-read Role|null $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Buffered role intended for the next save.
     *
     * Set by the `role` mutator (mass-assignment or property write); consumed by
     * the `saved` hook which applies it via syncRoles(). Holds `false` when no
     * write is pending (distinct from a deliberate null), so the hook can tell a
     * no-op save from an intended assignment.
     */
    private Role|null|false $pendingRole = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'full_name',
        'phone',
        'job_title',
        'role',
        'telegram_user_id',
        'avatar_path',
        'department_id',
        'manager_id',
        'is_active',
        // Service / system account (e.g. the AMO fallback import user). Hidden
        // from owner/assignee dropdowns.
        'is_service',
        'locale',
        'salary_currency',
        'nav_quick_actions',
        'totp_enabled',
        'totp_secret',
        'totp_enabled_at',
        'backup_codes',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
        'backup_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_service' => 'boolean',
            'nav_quick_actions' => 'array',
            'totp_enabled' => 'boolean',
            'totp_enabled_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'backup_codes' => 'encrypted:array',
        ];
    }

    /**
     * Direct line manager (self-referential).
     *
     * @return BelongsTo<self, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /**
     * Department the user belongs to (Org context).
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Read the user's role from the authoritative spatie assignment.
     *
     * `role` is a virtual attribute (no column) — it resolves to the user's
     * single spatie role on the active guard. Returns null when the user has no
     * role assigned. Every call site (`$user->role`, Policies, Resources,
     * VisibilityResolver) keeps the same `Role|null` enum contract it had against
     * the old column.
     */
    public function getRoleAttribute(): ?Role
    {
        $name = $this->roles->first()?->name;

        return $name !== null ? Role::tryFrom($name) : null;
    }

    /**
     * Buffer an intended role for the next save.
     *
     * Accepts a Role enum or its string value (mass-assignment from the factory /
     * seeders / UserService passes either). The value is NOT written to the model
     * attributes (there is no column) — it is held in {@see $pendingRole} and
     * applied by the `saved` hook via syncRoles(). This is what keeps
     * `User::create(['role' => ...])` and `$user->role = ...` working unchanged.
     */
    public function setRoleAttribute(Role|string|null $value): void
    {
        $this->pendingRole = match (true) {
            $value instanceof Role => $value,
            is_string($value) => Role::tryFrom($value),
            default => null,
        };
    }

    /**
     * Apply a buffered role assignment to the authoritative spatie store on save.
     *
     * Authorization decisions resolve through spatie permissions on the `sanctum`
     * guard ($user->can(...) / can:/permission: middleware / Policy
     * hasPermissionTo). Since IAM-1 dropped the mirror column, the `role`
     * assignment surface is the virtual attribute buffered by the mutator: this
     * `saved` hook consumes the buffer and syncs the single spatie role so the
     * factory / seeders / UserService (which write `role`) keep working under
     * permission-based authz.
     */
    protected static function booted(): void
    {
        static::saved(static function (self $user): void {
            $role = $user->pendingRole;

            // `false` = no role write was buffered for this save → leave the
            // existing spatie grant untouched.
            if ($role === false) {
                return;
            }

            $user->pendingRole = false;

            if (! $role instanceof Role) {
                return;
            }

            // Avoid a redundant write (and an extra pivot query) when the spatie
            // role already matches — only sync on a real change.
            if ($user->getRoleNames()->all() === [$role->value]) {
                return;
            }

            $user->syncRoles([$role->value]);
        });
    }
}
