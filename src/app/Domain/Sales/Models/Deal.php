<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\Sales\DealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deal — master Deal-on-Company entity. All business logic lives in
 * DealService / DealMoveService / DealProductService / DealContactService.
 * Model: fillable, casts, relations and the computed status() helper only.
 *
 * stage_id is changed ONLY through DealMoveService::move() (security boundary).
 */
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): DealFactory
    {
        return DealFactory::new();
    }

    protected $table = 'deals';

    protected $fillable = [
        'pipeline_id',
        'stage_id',
        'company_id',
        'title',
        'amount',
        'currency',
        'owner_user_id',
        'department_id',
        'contract_id',
        'lost_reason',
        'lost_reason_id',
        'tags',
        'extra_fields',
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
        'stage_changed_at',
        'closed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', // kopecks
            'tags' => 'array',
            'extra_fields' => 'array',
            'expected_close_date' => 'date',
            'expected_sign_date' => 'date',
            'expected_payment_date' => 'date',
            'stage_changed_at' => 'datetime',
            'closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Computed status from the current stage's flags (no status column).
     * Requires the `stage` relation to be loaded for the won/lost branches.
     */
    public function status(): string
    {
        $stage = $this->stage;

        if ($stage?->is_won) {
            return 'won';
        }

        if ($stage?->is_lost) {
            return 'lost';
        }

        return 'open';
    }

    // ---- Relations ----

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(LostReason::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(DealProduct::class)->orderBy('sort_order');
    }

    public function dealContacts(): HasMany
    {
        return $this->hasMany(DealContact::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            Contact::class,
            'deal_contacts',
            'deal_id',
            'contact_id',
        )->withPivot(['is_primary'])->withTimestamps();
    }

    public function stageHistory(): HasMany
    {
        return $this->hasMany(DealStageHistory::class)->orderBy('created_at');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DealAudit::class)->orderByDesc('created_at');
    }
}
