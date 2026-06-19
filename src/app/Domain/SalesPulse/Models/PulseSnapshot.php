<?php

declare(strict_types=1);

namespace App\Domain\SalesPulse\Models;

use App\Domain\Iam\Models\User;
use App\Domain\SalesPulse\Enums\SnapKind;
use App\Domain\SalesPulse\Enums\SnapSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PulseSnapshot — the serialized morning PLAN or evening FACT of one manager's
 * day (port of the AMO bot's `snapshots` table). The `data` jsonb holds the
 * PulseTaskRow[] + leads_by_id payload (spec §2). All collection / serialization
 * logic lives in the SalesPulse services (Slice 1) — the model is fillable,
 * casts, and the manager relation only.
 */
class PulseSnapshot extends Model
{
    protected $table = 'pulse_snapshots';

    protected $fillable = [
        'manager_id',
        'on_date',
        'kind',
        'source',
        'captured_at',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SnapKind::class,
            'source' => SnapSource::class,
            'on_date' => 'date',
            'captured_at' => 'datetime',
            'data' => 'array',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
