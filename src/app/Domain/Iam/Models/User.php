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
 * spatie/laravel-permission (single role per user, mirrored in the `role`
 * column for convenience). TOTP 2FA secret + backup codes are encrypted at rest
 * on the Laravel APP_KEY and never leave the API (hidden + encrypted casts).
 *
 * No business logic lives here (ARCHITECTURE.md §1) — only fillable/hidden,
 * casts, and relations.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'password',
        'full_name',
        'role',
        'telegram_user_id',
        'avatar_path',
        'department_id',
        'manager_id',
        'is_active',
        'locale',
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
            'role' => Role::class,
            'is_active' => 'boolean',
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
}
