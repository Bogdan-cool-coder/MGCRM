<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'Vizion'],
            [
                'is_system' => true,
                'crm_url' => 'https://macroserver.kz',
                // System tenant: no canonical currency/timezone of its own.
                'currency_code' => null,
                'timezone' => null,
                // MacroData config (demo: Capital Invest)
                'macrodata_host' => 'macroserver.kz',
                'macrodata_port' => 3306,
                'macrodata_database' => 'macro_bi_cmp_600',
                'macrodata_username' => 'macro_bi_cmp_600',
                'macrodata_password' => 'wd1yAkqN$*9fheM9',
            ]
        );

        if (User::where('email', 'webkuznets@yandex.ru')->exists()) {
            return;
        }

        User::create([
            'name' => 'Skorpyone',
            'email' => 'webkuznets@yandex.ru',
            'password' => 'Z3576824',
            'role' => 'superadmin',
            'company_id' => $company->id,
            'iframe_token' => 'seeder-skorpyone-fixed-token',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);

        User::create([
            'name' => 'TTeqwwd',
            'email' => 'e.vetrov@macroglobaltech.com',
            'password' => 'Z1X2C3V4B5N6',
            'role' => 'superadmin',
            'company_id' => $company->id,
            'iframe_token' => 'seeder-tteqwwd-fixed-token',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'superadmin']],
        ]);
    }
}
