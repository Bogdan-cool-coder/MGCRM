<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\CategoryCode;
use App\Domain\Crm\Enums\ClientStatus;
use App\Domain\Crm\Enums\CompanySpecialization;
use App\Domain\Crm\Enums\HoldingRole;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'specialization',
        'acquisition_channel_id',
        'company_type_id',
        'holding_id',
        'holding_role',
        'responsible_user_id',
        'owner_user_id',
        // First-class author of the card. Populated by the AMO import; nullable
        // for legacy/system-created rows.
        'created_by_id',
        'department_id',
        'tags',
        'extra_fields',
        'category_code',
        'turnover_rub',
        'category_recalc_at',
        'last_activity_at',
        // Client lifecycle (N5)
        'client_status',
        'unique_client_since',
        'disconnected_at',
        'disconnect_reason_id',
        'disconnect_doc_id',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'extra_fields' => 'array',
            'holding_role' => HoldingRole::class,
            'category_code' => CategoryCode::class,
            'specialization' => CompanySpecialization::class,
            'client_status' => ClientStatus::class,
            'acquisition_channel_id' => 'integer',
            'disconnect_reason_id' => 'integer',
            'disconnect_doc_id' => 'integer',
            'unique_client_since' => 'date',
            'category_recalc_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'disconnected_at' => 'datetime',
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

    /** The user who originally created this company (distinct from owner/responsible). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
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

    public function acquisitionChannel(): BelongsTo
    {
        return $this->belongsTo(AcquisitionChannel::class, 'acquisition_channel_id');
    }

    /** Communication channels (phone, email, website, tg, wa, etc.) for this company. */
    public function channels(): HasMany
    {
        return $this->hasMany(CompanyChannel::class)->orderBy('channel_type');
    }

    // ---- Client lifecycle (N5) ----

    public function disconnectReason(): BelongsTo
    {
        return $this->belongsTo(DisconnectReason::class, 'disconnect_reason_id');
    }

    public function clientStatusLog(): HasMany
    {
        return $this->hasMany(CompanyClientStatusLog::class);
    }

    // ---- Requisites ----

    /** All requisite sets for this company. */
    public function requisites(): HasMany
    {
        return $this->hasMany(CompanyRequisite::class);
    }

    /** The current (active) requisite set. */
    public function currentRequisite(): HasOne
    {
        return $this->hasOne(CompanyRequisite::class)->where('is_current', true);
    }
}
