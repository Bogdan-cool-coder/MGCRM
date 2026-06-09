<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class CapitalInvestSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'Capital Invest Construction'],
            [
                'crm_url' => 'https://macroserver.kz',
                'currency_code' => 'KZT',
                'timezone' => 'Asia/Almaty',
                'macrodata_host' => 'macroserver.kz',
                'macrodata_port' => 3306,
                'macrodata_database' => 'macro_bi_cmp_600',
                'macrodata_username' => 'macro_bi_cmp_600',
                'macrodata_password' => 'wd1yAkqN$*9fheM9',
            ]
        );

        if (User::where('email', 'admin@capitalinvest.kz')->exists()) {
            return;
        }

        User::create([
            'name' => 'CIC Admin',
            'email' => 'admin@capitalinvest.kz',
            'password' => 'CIC2026admin',
            'role' => 'admin',
            'company_id' => $company->id,
            'iframe_token' => 'seeder-cic-admin-fixed-token',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
    }
}
