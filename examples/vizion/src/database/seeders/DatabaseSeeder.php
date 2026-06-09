<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Default seed flow.
     *
     * System data (base users + Vizion company + system reports) plus the
     * Buildera system company and its analyst user (treated as a sibling
     * system company, protected from deletion via is_system=true).
     *
     * Other client data is opt-in:
     *   php artisan db:seed --class=CapitalInvestSeeder
     *   php artisan db:seed --class=BOMISeeder
     *   php artisan db:seed --class=SabaGroupSeeder
     *   php artisan db:seed --class=ClientsSeeder   (umbrella for all three)
     */
    public function run(): void
    {
        $this->call([
            SystemSeeder::class,
            ReportSeeder::class,
            WidgetSeeder::class,
            DashboardSeeder::class,
            DocumentTemplateSeeder::class,
            BuilderaSeeder::class,
        ]);
    }
}
