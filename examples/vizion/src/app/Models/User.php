<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'active_company_id',
        'role',
        'locale',
        'home_path',
        'iframe_token',
        'company_accesses',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'iframe_token',
    ];

    /**
     * Read accessor: never expose a null/empty home_path to the client. The
     * column is nullable for the benefit of legacy rows, but the contract with
     * the frontend is "home_path is always a usable router path" — null is
     * normalised to the '/reports' default on the way out.
     *
     * Note: this guarantees the value on *direct* property access. For JSON
     * serialization the column is additionally backfilled to '/reports' at
     * insert time in booted() so the key is never omitted from toArray().
     */
    protected function homePath(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): string => ($value === null || $value === '') ? '/reports' : $value,
        );
    }

    public const DEFAULT_HOME_PATH = '/reports';

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->iframe_token)) {
                $user->iframe_token = hash('sha256', Str::random(128));
            }

            // Default active_company_id to home company_id on user creation so
            // a fresh user immediately has a valid active company scope.
            if (empty($user->active_company_id) && !empty($user->company_id)) {
                $user->active_company_id = $user->company_id;
            }

            // Ensure home_path is never persisted as null so it is always
            // present in serialized user responses (the read accessor only
            // normalises on direct access, not for an omitted JSON key).
            // Inspect the raw attribute — reading $user->home_path would hit the
            // accessor, which always returns a non-empty value and would mask a
            // null/absent column.
            $rawHomePath = $user->getAttributes()['home_path'] ?? null;
            if ($rawHomePath === null || $rawHomePath === '') {
                $user->attributes['home_path'] = self::DEFAULT_HOME_PATH;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'company_accesses' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function activeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'active_company_id');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    /**
     * Resolve the company id to scope this user's queries by.
     *
     * Returns active_company_id if it is still valid (i.e. user can access it),
     * otherwise falls back to the home company_id. This protects against the
     * case where an admin revokes a user's company access while they had that
     * company selected — without this fallback, all subsequent queries would
     * leak data the user no longer has rights to.
     *
     * For superadmin the active_company_id is honoured as-is (superadmin can
     * access any company), with company_id as the last resort.
     */
    public function resolveActiveCompanyId(): int
    {
        $active = $this->active_company_id;

        if ($active !== null && $this->canAccessCompany((int) $active)) {
            return (int) $active;
        }

        return (int) $this->company_id;
    }

    /**
     * Authorisation check: can this user act on behalf of $companyId?
     *
     * - superadmin: always true
     * - everyone else: true if $companyId is the home company OR present
     *   in the company_accesses jsonb list.
     */
    public function canAccessCompany(int $companyId): bool
    {
        if ($this->role === 'superadmin') {
            return true;
        }

        if ((int) $this->company_id === $companyId) {
            return true;
        }

        $accesses = $this->company_accesses ?? [];

        foreach ($accesses as $access) {
            if (isset($access['company_id']) && (int) $access['company_id'] === $companyId) {
                return true;
            }
        }

        return false;
    }
}
