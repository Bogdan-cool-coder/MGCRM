<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Crm\Models\DisconnectReason;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the default disconnect reason directory.
 * Re-running does not create duplicates (firstOrCreate by name).
 * Values are editable by admins after seeding.
 */
class DisconnectReasonSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const DEFAULT_REASONS = [
        'Сменил поставщика',
        'Закрытие/банкротство',
        'Не устроила цена',
        'Нет потребности',
        'Перешёл на конкурента',
        'Другое',
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_REASONS as $index => $name) {
            DisconnectReason::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
