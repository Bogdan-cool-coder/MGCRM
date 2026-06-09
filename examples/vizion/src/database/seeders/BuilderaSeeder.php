<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class BuilderaSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'Buildera'],
            [
                'is_system' => true,
                'crm_url' => 'https://macrosales.ae/',
                'currency_code' => 'AED',
                'timezone' => 'Asia/Dubai',
                'macrodata_host' => 'macroserver.uz',
                'macrodata_port' => 3306,
                'macrodata_database' => 'macro_bi_cmp_614',
                'macrodata_username' => 'macro_bi_cmp_614',
                'macrodata_password' => '6}rX)}KHpY$&[R2]',
            ]
        );

        User::updateOrCreate(
            ['email' => 'analyst@buildera.ae'],
            [
                'name' => 'Analyst',
                'password' => 'mf6JZv2JP32PgAkKTC',
                'role' => 'analyst',
                'company_id' => $company->id,
                'active_company_id' => $company->id,
                'iframe_token' => '982809763b6bf8217b8cd511dd23e019679d1aaa81f90f28',
                'company_accesses' => [['company_id' => $company->id, 'role' => 'analyst']],
            ]
        );
    }
}
