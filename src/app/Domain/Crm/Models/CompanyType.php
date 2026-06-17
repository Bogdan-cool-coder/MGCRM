<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use Database\Factories\CompanyTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Справочник типов компаний (строительная / агентство / подрядчик / партнёр).
 * Editable admin directory. No business logic — fillable/casts/relations only.
 */
class CompanyType extends Model
{
    /** @use HasFactory<CompanyTypeFactory> */
    use HasFactory, SoftDeletes;

    protected static function newFactory(): CompanyTypeFactory
    {
        return CompanyTypeFactory::new();
    }

    protected $table = 'crm_company_types';

    protected $fillable = [
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function companies(): HasMany
    {
        // Company is in the same Domain\Crm\Models namespace
        return $this->hasMany(Company::class, 'company_type_id');
    }
}
