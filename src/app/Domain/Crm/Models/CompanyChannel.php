<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\ChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CompanyChannel — a communication channel entry for a company.
 * Business logic lives in CompanyChannelService. Model: fillable, casts, relations only.
 */
class CompanyChannel extends Model
{
    protected $table = 'company_channels';

    protected $fillable = [
        'company_id',
        'channel_type',
        'value',
        'label',
        'is_primary_for_channel',
    ];

    protected function casts(): array
    {
        return [
            'channel_type' => ChannelType::class,
            'is_primary_for_channel' => 'bool',
        ];
    }

    // ---- Relations ----

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
