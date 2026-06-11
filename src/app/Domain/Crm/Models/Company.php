<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Company (CRM client entity).
 * All business logic lives in CompanyService. Model: fillable, casts, relations only.
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, SoftDeletes;

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    protected $table = 'crm_companies';

    protected $fillable = [
        'name',
        'legal_name',
        'short_name',
        'full_legal_form',
        'legal_form',
        'gender_ending_oe',
        'director_position',
        'director_genitive',
        'director_short',
        'acts_basis',
        'tax_id_label',
        'tax_id',
        'address',
        'bank',
        'bank_code_label',
        'bank_code',
        'account',
        'phone',
        'email',
        'website',
        'notes',
        'country_code',
        'city',
        'source',
        'industry',
        'company_type_id',
        'holding_id',
        'holding_role',
        'responsible_user_id',
        'owner_user_id',
        'department_id',
        'tags',
        'extra_fields',
        'category_code',
        'turnover_rub',
        'category_recalc_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'extra_fields' => 'array',
            'holding_role' => HoldingRole::class,
            'category_code' => CategoryCode::class,
            'category_recalc_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function companyType(): BelongsTo
    {
        return $this->belongsTo(CompanyType::class);
    }

    /** Self-referential: this company's holding parent. */
    public function holding(): BelongsTo
    {
        return $this->belongsTo(self::class, 'holding_id');
    }

    /** Subsidiaries in this holding. */
    public function subsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'holding_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * M2M: contacts linked to this company.
     * Pivot extras: position, position_id, employment_status, is_primary.
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'crm_contact_company_links',
            'company_id',
            'contact_id',
        )->withPivot(['position', 'position_id', 'employment_status', 'is_primary'])
            ->withTimestamps();
    }

    /** Raw link models for richer pivot access. */
    public function contactLinks(): HasMany
    {
        return $this->hasMany(ContactCompanyLink::class);
    }
}
