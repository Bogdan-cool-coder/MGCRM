<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use Database\Factories\Contracts\LicensorEntityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LicensorEntity — our legal entity (licensor) per country.
 * Data is substituted into contracts automatically based on country_code.
 */
class LicensorEntity extends Model
{
    /** @use HasFactory<LicensorEntityFactory> */
    use HasFactory;

    protected static function newFactory(): LicensorEntityFactory
    {
        return LicensorEntityFactory::new();
    }

    protected $table = 'licensor_entities';

    protected $fillable = [
        'country_code',
        'is_default',
        'legal_form',
        'full_legal_form',
        'gender_ending_oe',
        'name',
        'director_position',
        'director_short',
        'director_genitive',
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
        'training_login',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(LicensorBankAccount::class, 'licensor_id');
    }

    /** @param Builder<LicensorEntity> $query */
    public function scopeForCountry(Builder $query, string $code): Builder
    {
        return $query->where('country_code', $code);
    }
}
