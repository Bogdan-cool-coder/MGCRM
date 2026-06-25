<?php

declare(strict_types=1);

namespace App\Domain\Iam\Models;

use App\Domain\Iam\Enums\VisibilityScope;
use Illuminate\Database\Eloquent\Model;

/**
 * VisibilitySetting — one row of the role × scope matrix that drives
 * VisibilityResolver. The authoritative store for "what records may this role
 * see" (replaces the hardcoded VisibilityScope::forRole map). All read/write
 * logic lives in VisibilityConfigService (cached); the model carries only
 * fillable + casts.
 */
class VisibilitySetting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'scope',
    ];

    protected function casts(): array
    {
        return [
            'scope' => VisibilityScope::class,
        ];
    }
}
