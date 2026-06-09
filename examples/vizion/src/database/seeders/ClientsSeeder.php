<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Umbrella seeder for all client companies.
 *
 * Run on demand:
 *   php artisan db:seed --class=ClientsSeeder
 *
 * Individual clients can be seeded one-by-one via --class=CapitalInvestSeeder etc.
 * Each underlying seeder is idempotent (updateOrCreate / existence checks).
 */
class ClientsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            CapitalInvestSeeder::class,
            BOMISeeder::class,
            SabaGroupSeeder::class,
            BuilderaSeeder::class,
            ApartGroupSeeder::class,
        ]);
    }
}
