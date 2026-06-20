<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Crm\Models\AcquisitionChannel;
use Illuminate\Database\Seeder;

/**
 * INSERT-MISSING idempotent seeder for the default acquisition channel directory.
 * Re-running does not create duplicates (firstOrCreate by name).
 * Values are editable by admins after seeding.
 */
class AcquisitionChannelSeeder extends Seeder
{
    /**
     * @var list<string>
     */
    private const DEFAULT_CHANNELS = [
        'Рекомендации клиентов',
        'Рекомендации партнёров',
        'Входящий запрос',
        'Холодный звонок',
        'Выставка',
        'Соцсети',
        'Другое',
    ];

    public function run(): void
    {
        foreach (self::DEFAULT_CHANNELS as $index => $name) {
            AcquisitionChannel::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $index + 1, 'is_active' => true],
            );
        }
    }
}
