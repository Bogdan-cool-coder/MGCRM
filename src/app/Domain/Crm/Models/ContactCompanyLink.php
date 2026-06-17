<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M2M link between Contact and Company.
 * Stores position (free text + optional FK), employment status and primary flag.
 * No business logic — fillable/casts/relations only.
 */
class ContactCompanyLink extends Model
{
    protected $table = 'crm_contact_company_links';

    protected $fillable = [
        'contact_id',
        'company_id',
        'position',
        'position_id',
        'employment_status',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'is_primary' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(ContactPosition::class, 'position_id');
    }
}
