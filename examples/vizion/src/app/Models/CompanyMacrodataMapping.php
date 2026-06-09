<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One semantic_key => value mapping for a single company's MacroData.
 *
 * Read by macrodata-engineer's ConfigResolver when expanding
 * `{"$company_var": "<semantic_key>"}` placeholders inside report configs.
 * Written by admin UI (manual edits) and the future
 * CompanySchemaProbeService (auto-probe), which stamps `auto_probed_at`.
 *
 * `value` is intentionally typed as `array` cast — for non-array semantic
 * payloads (single int, string) Eloquent's array cast still round-trips
 * cleanly because we store them via JSON encoding. The consumer
 * (ConfigResolver) interprets shape per semantic_key.
 */
class CompanyMacrodataMapping extends Model
{
    protected $fillable = [
        'company_id',
        'semantic_key',
        'value',
        'notes',
        'auto_probed_at',
    ];

    protected function casts(): array
    {
        return [
            'value'          => 'array',
            'auto_probed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
