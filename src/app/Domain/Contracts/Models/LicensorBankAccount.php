<?php

declare(strict_types=1);

namespace App\Domain\Contracts\Models;

use Database\Factories\Contracts\LicensorBankAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LicensorBankAccount — multi-currency bank accounts for a licensor.
 * Primary account per (licensor, currency) is enforced via is_primary flag.
 */
class LicensorBankAccount extends Model
{
    /** @use HasFactory<LicensorBankAccountFactory> */
    use HasFactory;

    protected static function newFactory(): LicensorBankAccountFactory
    {
        return LicensorBankAccountFactory::new();
    }

    protected $table = 'licensor_bank_accounts';

    public $timestamps = false;

    protected $fillable = [
        'licensor_id',
        'currency',
        'bank',
        'bank_code_label',
        'bank_code',
        'account',
        'swift',
        'is_primary',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function licensor(): BelongsTo
    {
        return $this->belongsTo(LicensorEntity::class, 'licensor_id');
    }

    /**
     * @param  Builder<LicensorBankAccount>  $query
     */
    public function scopePrimaryFor(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency)->where('is_primary', true);
    }
}
