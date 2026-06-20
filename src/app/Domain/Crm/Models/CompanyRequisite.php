<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Database\Factories\CompanyRequisiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CompanyRequisite — one legal-requisite set belonging to a Company.
 *
 * Source of truth for all requisite fields. The active set (is_current=true)
 * is mirrored back onto crm_companies by CompanyRequisiteService::setCurrent()
 * so that existing list/search/dedup queries continue to work against Company
 * columns without modification.
 *
 * Invariant: exactly one row per company_id has is_current=true.
 * Postgres: enforced by partial unique index.
 * SQLite:   enforced by service transaction in setCurrent().
 *
 * All business logic lives in CompanyRequisiteService.
 */
class CompanyRequisite extends Model
{
    /** @use HasFactory<CompanyRequisiteFactory> */
    use HasFactory;

    protected static function newFactory(): CompanyRequisiteFactory
    {
        return CompanyRequisiteFactory::new();
    }

    protected $table = 'company_requisites';

    protected $fillable = [
        'company_id',
        'legal_name',
        'full_legal_form',
        'legal_form',
        'gender_ending_oe',
        'director_position',
        'director_genitive',
        'director_short',
        'acts_basis',
        'tax_id_label',
        'tax_id',
        'country_code',
        'address',
        'bank_details',
        'is_current',
        'valid_from',
        'valid_to',
        'label',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'bank_details' => 'array',
            'is_current' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    // ---- Relations ----

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
