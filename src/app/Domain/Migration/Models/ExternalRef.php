<?php

declare(strict_types=1);

namespace App\Domain\Migration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provenance / idempotency record for an imported entity (AMO -> MGCRM).
 *
 * Temporary migration bounded-context (dropped at M12). entity_id is an FK-less
 * polymorph: our PK in the target context's table, paired with entity_type. The
 * ETL upserts on (source, entity_type, external_id) so re-runs stay idempotent.
 */
class ExternalRef extends Model
{
    protected $table = 'external_refs';

    protected $fillable = [
        'source',
        'entity_type',
        'entity_id',
        'external_id',
        'external_payload',
        'imported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'external_payload' => 'array',
            'imported_at' => 'datetime',
        ];
    }
}
