<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Activity\Enums\ActivityStatus;
use App\Domain\Activity\Enums\ActivityTargetType;
use App\Domain\Activity\Enums\ActivityType;
use App\Domain\Activity\Models\Activity;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Crm\Models\Contact;
use App\Domain\Iam\Models\User;
use App\Domain\Org\Models\Department;
use Database\Factories\Sales\DealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'max_stage_id',
        'company_id',
        'company_requisite_id',
        'title',
        'amount',
        // Deal-level discount in PERCENT (0..50). Applied uniformly to the line
        // items to derive the displayed net total (see DealResource); the per-line
        // discount on deal_products is a separate, absolute kopeck reduction.
        // Clamped to [0,50] in DealService::update (a >50 value is saved as 50).
        'discount_percent',
        // When true, amount is a fixed budget and is NOT re-derived from
        // deal_products (DealService::recalcAmount returns early). amount may then
        // differ from sum(deal_products) — see the migration's cross-domain note.
        'amount_locked',
        // «Вечная лицензия» / «Коробка / on-premise» (one field). The price effect
        // on line items is wired in N4 (DealProductService::applyLicenseMode).
        'perpetual_license',
        // True on the FIRST won deal that converted the company into a unique
        // client (set by DealMoveService on the won-transition). false on
        // non-won deals and later won deals (upsell = won && !is_primary_deal).
        'is_primary_deal',
        'currency',
        'owner_user_id',
        // First-class author of the card (distinct from owner). Populated by the
        // AMO import; nullable for legacy/system-created rows.
        'created_by_id',
        'department_id',
        'contract_id',
        'lost_reason',
        'lost_reason_id',
        'tags',
        'extra_fields',
        'expected_close_date',
        'expected_sign_date',
        'expected_payment_date',
        // ACTUAL dates (the «Факт» half of the «План / Факт» pairs): contract
        // signed / payment received. Date, symmetric with the expected_* planned
        // dates above.
        'signed_at',
        'paid_at',
        // Actual paid sum (kopecks) + its currency. Distinct from amount (the
        // budget): a payment may differ from the budget and be in another currency.
        'paid_amount',
        'payment_currency',
        'kp_sent_at',
        'contract_sent_at',
        'stage_changed_at',
        'closed_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer', // kopecks
            'discount_percent' => 'integer', // percent (0..50)
            'amount_locked' => 'boolean',
            'perpetual_license' => 'boolean',
            'is_primary_deal' => 'boolean',
            'tags' => 'array',
            'extra_fields' => 'array',
            'expected_close_date' => 'date',
            'expected_sign_date' => 'date',
            'expected_payment_date' => 'date',
            // Actual fact dates — date, symmetric with the expected_* casts.
            'signed_at' => 'date',
            'paid_at' => 'date',
            'paid_amount' => 'integer', // kopecks
            'kp_sent_at' => 'datetime',
            'contract_sent_at' => 'datetime',
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

    /**
     * Whole days the deal has sat in its current stage (the rotting clock base —
     * Сделки — ТЗ §1.3). Computed from stage_changed_at; 0 when it has never
     * changed stage. Pure (no DB) — the frontend colours it against the stage's
     * warn_days/danger_days thresholds.
     */
    public function daysInStage(): int
    {
        if ($this->stage_changed_at === null) {
            return 0;
        }

        return (int) $this->stage_changed_at->copy()->startOfDay()
            ->diffInDays(now()->startOfDay());
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

    /**
     * The HIGHEST pipeline stage this deal has ever reached (by sort_order), kept
     * even after a roll-back to an earlier stage — the deal-card header
     * `max_stage` key action. Maintained by DealMoveService::move() /
     * DealService::create(); eager-load with('maxStage') for the DealResource.
     */
    public function maxStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'max_stage_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** Pinned requisite set used when this deal was created / the contract generated. */
    public function companyRequisite(): BelongsTo
    {
        return $this->belongsTo(CompanyRequisite::class, 'company_requisite_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** The user who originally created this deal (distinct from the current owner). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
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

    /**
     * The next OPEN, task-like activity on this deal by soonest due_at — the
     * "deal health" signal shared by the Kanban card and the DealPage 2.0 header
     * chip (DealPage 2.0 v2 §8 v2-B1). The cross-context link is the FK-less
     * polymorphic target (target_type='deal' + target_id), mirroring how the
     * Activity domain itself models the relationship.
     *
     * Open = not closed AND status != done AND has a due_at (a note never
     * surfaces here — it carries no deadline). Eager-load this relation
     * (with('nextTask')) to avoid N+1 in the DealResource. For board enrichment
     * across many deals, DealService batches the equivalent query instead.
     */
    public function nextTask(): HasOne
    {
        return $this->hasOne(Activity::class, 'target_id')
            ->where('target_type', ActivityTargetType::Deal->value)
            ->whereIn('kind', ActivityType::taskLikeValues())
            ->where('is_closed', false)
            ->where('status', '!=', ActivityStatus::Done->value)
            ->whereNotNull('due_at')
            ->orderBy('due_at');
    }

    /**
     * The Crm entities whose engagement (last_activity_at) this deal touches: its
     * company and every linked contact (deal_contacts). Single source for the
     * deal → {company, contacts} fan-out, reused by the Sales (deal mutations)
     * and Activity (deal-targeted activities) domains so neither duplicates the
     * deal_contacts lookup. Fed verbatim into EngagementService::touchForDeal().
     *
     * @return array{company_id: ?int, contact_ids: list<int>}
     */
    public function engagementTargets(): array
    {
        return [
            'company_id' => $this->company_id !== null ? (int) $this->company_id : null,
            'contact_ids' => DealContact::query()
                ->where('deal_id', $this->id)
                ->pluck('contact_id')
                ->map(static fn ($id): int => (int) $id)
                ->all(),
        ];
    }
}
