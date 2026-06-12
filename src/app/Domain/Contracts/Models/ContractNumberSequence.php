<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use Database\Factories\Contracts\ContractNumberSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ContractNumberSequence — atomic counter for contract numbers per city/country.
 *
 * UNIQUE (city_code, country_code). Mutated exclusively by
 * ContractNumberingService::nextNumber() inside a DB::transaction with
 * lockForUpdate() to prevent concurrent duplicate numbering.
 *
 * city_code is always uppercase, 3 letters (e.g. ТШК, АЛМ, АСТ).
 * country_code is always uppercase, 2 letters (KZ, UZ).
 */
class ContractNumberSequence extends Model
{
    /** @use HasFactory<ContractNumberSequenceFactory> */
    use HasFactory;

    protected static function newFactory(): ContractNumberSequenceFactory
    {
        return ContractNumberSequenceFactory::new();
    }

    protected $table = 'contract_number_sequences';

    protected $fillable = [
        'city_code',
        'country_code',
        'start_number',
        'current_number',
    ];

    protected function casts(): array
    {
        return [
            'start_number' => 'integer',
            'current_number' => 'integer',
        ];
    }
}
