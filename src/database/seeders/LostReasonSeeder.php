<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sales\Models\LostReason;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the default deal-loss reasons.
 * Re-running does not create duplicates (firstOrCreate by name).
 */
class LostReasonSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const DEFAULT_LOST_REASONS = [
        'Дорого',
        'Используют другую систему',
        'Закрываются',
        'Не вышли на ЛПР',
        'Нет бюджета',
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_LOST_REASONS as $index => $name) {
            LostReason::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
