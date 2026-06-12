<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Contracts\Models\Template;
use Illuminate\Database\Seeder;

/**
 * TemplateSeeder — seeds 6 templates:
 *   - master_skeleton (docx, no content)
 *   - product_macrocrm / product_macrosales / product_macroerp (yaml)
 *   - country_kz / country_uz (yaml)
 *
 * Idempotent: updateOrCreate by code.
 * master_skeleton has content='' — docx uploaded via S2.3 upload endpoint.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $dataDir = database_path('seeders/data/contracts');

        $templates = [
            [
                'code' => 'master_skeleton',
                'kind' => 'docx',
                'title' => 'Master Skeleton (основной шаблон договора)',
                'content' => '',
                'category' => null,
            ],
            [
                'code' => 'product_macrocrm',
                'kind' => 'yaml',
                'title' => 'MacroCRM — продуктовый оверлей',
                'content' => file_get_contents($dataDir.'/products/product_macrocrm.yaml') ?: '',
                'category' => null,
            ],
            [
                'code' => 'product_macrosales',
                'kind' => 'yaml',
                'title' => 'MacroSales — продуктовый оверлей',
                'content' => file_get_contents($dataDir.'/products/product_macrosales.yaml') ?: '',
                'category' => null,
            ],
            [
                'code' => 'product_macroerp',
                'kind' => 'yaml',
                'title' => 'MacroERP — продуктовый оверлей',
                'content' => file_get_contents($dataDir.'/products/product_macroerp.yaml') ?: '',
                'category' => null,
            ],
            [
                'code' => 'country_kz',
                'kind' => 'yaml',
                'title' => 'Казахстан — страновой оверлей',
                'content' => file_get_contents($dataDir.'/countries/country_kz.yaml') ?: '',
                'category' => null,
            ],
            [
                'code' => 'country_uz',
                'kind' => 'yaml',
                'title' => 'Узбекистан — страновой оверлей',
                'content' => file_get_contents($dataDir.'/countries/country_uz.yaml') ?: '',
                'category' => null,
            ],
        ];

        foreach ($templates as $data) {
            Template::updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'version' => 1,
                    'current_version_id' => null,
                    'product_codes' => [],
                    'country_codes' => [],
                    'client_category_codes' => [],
                    'department_ids' => [],
                ]),
            );
        }
    }
}
