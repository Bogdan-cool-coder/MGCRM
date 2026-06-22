<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use App\Domain\Contracts\Enums\ContractStatus;
use App\Domain\Contracts\Enums\DocumentKind;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\CompanyRequisite;
use App\Domain\Iam\Models\User;
use App\Domain\Sales\Models\Deal;
use Database\Factories\Contracts\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document — universal legal-document aggregate.
 *
 * kind = 'contract' for S2 (sublicensing agreement).
 * Status machine transitions are handled exclusively by DocumentService::transition().
 *
 * Context JSONB structure:
 *   {sublicensee: {}, license: {}, contract: {}, payments: [], acts: [], custom: {}}
 *
 * Money fields (subtotal, discount_amount, total, unit_price) are stored in kopecks
 * (unsigned bigint) per ARCHITECTURE.md §3.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    protected $table = 'documents';

    protected $fillable = [
        'kind',
        'number',
        'title',
        'product_code',
        'country_code',
        'city',
        'city_code',
        'source_deal_id',
        'source_company_id',
        'company_requisite_id',
        'author_user_id',
        'status',
        'context',
        'template_version',
        'docx_path',
        'pdf_path',
        'drive_folder_url',
        'drive_docx_url',
        'drive_pdf_url',
        'telegram_message_id',
        'archived_at',
        'signed_at',
        'currency',
        'subtotal',
        'discount_pct',
        'discount_amount',
        'total',
        'total_rub',
        'fx_rate',
        'fx_rate_date',
        'extra_fields',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => ContractStatus::class,
            'context' => 'array',
            'extra_fields' => 'array',
            'subtotal' => 'integer',
            'discount_pct' => 'decimal:2',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'total_rub' => 'integer',
            'fx_rate' => 'decimal:6',
            'fx_rate_date' => 'date',
            'archived_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    // ---- Relations ----

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function sourceDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'source_deal_id');
    }

    public function sourceCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'source_company_id');
    }

    /**
     * Pinned requisite set at document creation time.
     * Immutable after signing — the context JSONB already carries the snapshot text.
     */
    public function companyRequisite(): BelongsTo
    {
        return $this->belongsTo(CompanyRequisite::class, 'company_requisite_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class, 'template_version');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('sort_order');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class)->orderBy('version_number');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class)->orderByDesc('created_at');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(DocumentRemark::class)->orderBy('created_at');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('created_at');
    }

    // ---- Scopes ----

    /** @param Builder<Document> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @param Builder<Document> $query */
    public function scopeForStatus(Builder $query, ContractStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
