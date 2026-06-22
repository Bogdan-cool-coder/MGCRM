<?php

declare(strict_types=1);

namespace App\Domain\Migration\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bulk auto-generated AMO -> MGCRM translation entry (custom fields, options,
 * etc.). Temporary migration bounded-context (dropped at M12).
 *
 * amo_id is the AMO field/option id; amo_parent_id scopes options under their
 * parent field (null for top-level). target_code / target_id / target_meta hold
 * the resolved MGCRM side.
 */
class MigrationMap extends Model
{
    protected $table = 'migration_maps';

    protected $fillable = [
        'map_type',
        'amo_id',
        'amo_parent_id',
        'target_code',
        'target_id',
        'target_meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_id' => 'integer',
            'target_meta' => 'array',
        ];
    }
}
