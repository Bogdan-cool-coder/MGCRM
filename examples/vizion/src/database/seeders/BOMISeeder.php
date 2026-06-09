<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class BOMISeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'BOMI'],
            [
                'crm_url' => 'https://macroserver.uz',
                // BOMI uses macroserver.uz — Uzbekistan tenant.
                'currency_code' => 'UZS',
                'timezone' => 'Asia/Tashkent',
                'macrodata_host' => 'macroserver.uz',
                'macrodata_port' => 3306,
                'macrodata_database' => 'macro_bi_cmp_634',
                'macrodata_username' => 'macro_bi_cmp_634',
                'macrodata_password' => 'k{oq63[px$rj2{W{',
            ]
        );

        if (User::where('email', 'admin@bomi.uz')->exists()) {
            return;
        }

        User::create([
            'name' => 'BOMI Admin',
            'email' => 'admin@bomi.uz',
            'password' => 'BOMI2026admin',
            'role' => 'admin',
            'company_id' => $company->id,
            'iframe_token' => 'seeder-bomi-admin-fixed-token',
            'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
        ]);
    }
}
