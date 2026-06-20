<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Iam\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * История изменений канала привлечения на компаниях и контактах.
 * Written by AcquisitionChannelHistoryService — never directly.
 */
class AcquisitionChannelHistory extends Model
{
    protected $table = 'acquisition_channel_history';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'old_channel_id',
        'new_channel_id',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'old_channel_id' => 'integer',
            'new_channel_id' => 'integer',
            'changed_by' => 'integer',
            'changed_at' => 'datetime',
        ];
    }

    public function oldChannel(): BelongsTo
    {
        return $this->belongsTo(AcquisitionChannel::class, 'old_channel_id');
    }

    public function newChannel(): BelongsTo
    {
        return $this->belongsTo(AcquisitionChannel::class, 'new_channel_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
