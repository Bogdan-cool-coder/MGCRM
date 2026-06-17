<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\ChannelType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ContactChannel — a communication channel entry for a contact.
 * Business logic lives in ContactChannelService. Model: fillable, casts, relations only.
 */
class ContactChannel extends Model
{
    protected $table = 'contact_channels';

    protected $fillable = [
        'contact_id',
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

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
