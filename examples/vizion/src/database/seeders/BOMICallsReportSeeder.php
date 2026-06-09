<?php

namespace Database\Seeders;

use App\Models\Report;
use Illuminate\Database\Seeder;

class BOMICallsReportSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            [
                'title' => ['ru' => 'Ежедневный отчёт по звонкам', 'en' => 'Daily Report on Calls to Sales Department'],
                'description' => ['ru' => 'Ежедневный отчёт по звонкам в отдел продаж', 'en' => 'Daily report on calls to the Sales Department'],
                'is_system' => true,
                'is_published' => true,
                'config' => [
                    'primary_model' => 'Calls',
                    'columns' => [
                        ['field' => 'row_number', 'header' => ['ru' => '№', 'en' => '#'], 'type' => 'number', 'renderer' => 'row_number', 'filterable' => false],
                        ['field' => 'call_date', 'header' => ['ru' => 'Дата/время звонка', 'en' => 'Call Date'], 'type' => 'datetime', 'sortable' => true],
                        ['field' => 'contacts.companyLink.contacts1.contacts_buy_name', 'header' => ['ru' => 'Компания', 'en' => 'Company Name'], 'type' => 'text'],
                        ['field' => 'estateBuys.contacts_buy_geo_country_name', 'header' => ['ru' => 'Страна клиента', 'en' => 'Country'], 'type' => 'text'],
                        ['field' => 'contacts.companyLink.contacts1.roles_set', 'header' => ['ru' => 'Роль компании', 'en' => 'Company Role'], 'type' => 'text'],
                        ['field' => 'contacts.contacts_buy_name', 'header' => ['ru' => 'Имя контакта', 'en' => 'Contact Name'], 'type' => 'text'],
                        ['field' => 'phone', 'header' => ['ru' => 'Телефон', 'en' => 'Phone'], 'type' => 'text'],
                        ['field' => 'usersManager.users_name', 'header' => ['ru' => 'Менеджер', 'en' => 'Manager'], 'type' => 'text'],
                        ['field' => 'estateBuys.estateTagsRelation', 'header' => ['ru' => 'Теги', 'en' => 'Tags'], 'type' => 'text', 'renderer' => 'tags_join', 'extra_relations' => ['estateBuys.estateTagsRelation.tags']],
                        ['field' => 'estateBuys.estateBuysAttrs', 'header' => ['ru' => 'Площадь (лид)', 'en' => 'Lead Area'], 'type' => 'text', 'renderer' => 'area_range'],
                        ['field' => 'estateBuys.estateSells.estate_floor', 'header' => ['ru' => 'Этаж (лид)', 'en' => 'Lead Floor'], 'type' => 'number'],
                        ['field' => 'estateBuys.estateAdvertisingChannels.name', 'header' => ['ru' => 'Рекламный канал', 'en' => 'Ad Channel'], 'type' => 'text'],
                    ],
                    'sort' => [
                        'default' => ['field' => 'call_date', 'direction' => 'desc']
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200]
                    ],
                ],
            ],
        ];

        foreach ($reports as $reportData) {
            $exists = Report::where('title->ru', $reportData['title']['ru'] ?? null)
                ->orWhere('title->en', $reportData['title']['en'] ?? null)
                ->where('is_system', true)
                ->exists();

            if (!$exists) {
                Report::create($reportData);
            }
        }

        $this->command->info('Created ' . count($reports) . ' BOMI system reports.');
    }
}
