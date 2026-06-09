<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

class SabaGroupSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::updateOrCreate(
            ['name' => 'SABA Group'],
            [
                'crm_url' => 'https://macroserver.kz',
                'currency_code' => 'KZT',
                'timezone' => 'Asia/Almaty',
                'macrodata_host' => 'macroserver.kz',
                'macrodata_port' => 3306,
                'macrodata_database' => 'macro_bi_cmp_664',
                'macrodata_username' => 'macro_bi_cmp_664',
                'macrodata_password' => '*]akgykjm*p)yQ&p',
            ]
        );

        $user = User::where('email', 'admin@sabagroup.kz')->first();

        if (!$user) {
            $user = User::create([
                'name' => 'SABA admin',
                'email' => 'admin@sabagroup.kz',
                'password' => 'SABA2026admin',
                'role' => 'admin',
                'company_id' => $company->id,
                'iframe_token' => 'seeder-saba-admin-fixed-token',
                'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
            ]);
        }

        $config = [
            'primary_model' => 'EstateBuys',
            'columns' => [
                [
                    'field'         => 'estate_buy_id',
                    'type'          => 'link',
                    'header'        => ['ru' => 'Заявка', 'en' => 'Application'],
                    'sortable'      => true,
                    'filterable'    => true,
                    'label_field'   => 'estate_buy_id',
                    'link_template' => '{crm_url}/account/estate/view/{estate_buy_id}/',
                    'filter_type'   => 'async_select',
                    'filter_field'  => 'estate_buy_id',
                ],
                [
                    'field'       => 'contacts.contacts_buy_name',
                    'header'      => ['ru' => 'Контакт', 'en' => 'Contact'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'      => 'contacts.contacts_buy_phones',
                    'header'     => ['ru' => 'Телефон', 'en' => 'Phone'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'       => 'usersManager.users_name',
                    'header'      => ['ru' => 'Менеджер', 'en' => 'Manager'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'      => 'status_name',
                    'header'     => ['ru' => 'Статус', 'en' => 'Status'],
                    'type'       => 'text',
                    'sortable'   => true,
                    'filterable' => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'      => 'custom_status_name',
                    'header'     => ['ru' => 'Кастомный статус', 'en' => 'Custom status'],
                    'type'       => 'text',
                    'sortable'   => true,
                    'filterable' => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'    => 'estateHousesFirstInterest.public_house_name',
                    'header'   => ['ru' => 'Дом', 'en' => 'House'],
                    'type'     => 'text',
                    'sortable' => true,
                    'filterable' => true,
                ],
                [
                    'field'    => 'estateHousesFirstInterest.complex_name',
                    'header'   => ['ru' => 'ЖК', 'en' => 'Complex'],
                    'type'     => 'text',
                    'sortable' => true,
                    'filterable' => true,
                ],
                [
                    'field'    => 'created_at',
                    'header'   => ['ru' => 'Дата создания', 'en' => 'Created at'],
                    'type'     => 'datetime',
                    'sortable' => true,
                    'filterable' => true,
                ],
                [
                    'field'       => 'tags',
                    'header'      => ['ru' => 'Теги', 'en' => 'Tags'],
                    'type'        => 'concat_relation',
                    'relation'    => 'estateTagsRelation.tags',
                    'value_field' => 'tags_name',
                    'separator'   => ', ',
                    'sortable'    => false,
                    'filterable'  => false,
                ],
                [
                    'field'      => 'utm_source',
                    'header'     => ['ru' => 'UTM Source', 'en' => 'UTM Source'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_medium',
                    'header'     => ['ru' => 'UTM Medium', 'en' => 'UTM Medium'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_campaign',
                    'header'     => ['ru' => 'UTM Campaign', 'en' => 'UTM Campaign'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'estateBuysUtm.utm_term',
                    'header'     => ['ru' => 'UTM Term', 'en' => 'UTM Term'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_content',
                    'header'     => ['ru' => 'UTM Content', 'en' => 'UTM Content'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
            ],
            'sort' => [
                'default' => ['field' => 'created_at', 'direction' => 'desc'],
            ],
            'pagination' => [
                'default' => 50,
                'options' => [25, 50, 100, 200],
            ],
            'extra_filters' => [
                [
                    'key'               => 'tags_any',
                    'label'             => ['ru' => 'Теги', 'en' => 'Tags'],
                    'operation'         => 'has_any_pivot',
                    'relation'          => 'estateTagsRelation',
                    'foreign_key_field' => 'tags_id',
                    'options_source'    => [
                        'model'       => 'Tags',
                        'value_field' => 'id',
                        'label_field' => 'tags_name',
                    ],
                ],
            ],
        ];

        $attributes = [
            'config'       => $config,
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'title' => [
                'ru' => 'SABA - Реестр заявок',
                'en' => 'SABA - Applications Registry',
            ],
            'description' => [
                'ru' => 'Реестр заявок (лидов) с контактами, менеджерами и UTM-метками',
                'en' => 'Applications (leads) registry with contacts, managers and UTM tags',
            ],
        ];

        $existing = Report::where('title->ru', 'SABA - Реестр заявок')
            ->where('company_id', $company->id)
            ->where('is_system', false)
            ->first();

        if ($existing) {
            $existing->update($attributes);
        } else {
            Report::create($attributes);
        }

        // --- Реестр встреч ---

        $meetingsConfig = [
            'primary_model' => 'EstateBuys',
            'columns' => [
                [
                    'field'         => 'estate_buy_id',
                    'type'          => 'link',
                    'header'        => ['ru' => 'Имя контакта', 'en' => 'Contact name'],
                    'label_lines'   => [
                        ['field' => 'contacts.contacts_buy_name'],
                    ],
                    'link_template' => '{crm_url}/account/estate/view/{estate_buy_id}/',
                    'sortable'      => true,
                    'filterable'    => true,
                    'filter_type'   => 'async_select',
                    'filter_field'  => 'contacts.contacts_buy_name',
                ],
                [
                    'field'      => 'contacts.contacts_buy_phones',
                    'header'     => ['ru' => 'Номер контакта', 'en' => 'Contact phone'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'scheduled_meetings',
                    'header'     => ['ru' => 'Встреча назначена', 'en' => 'Meetings scheduled'],
                    'type'       => 'relation_aggregate',
                    'aggregate'  => [
                        'function' => 'count',
                        'relation' => 'tasks',
                        'where'    => [
                            ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                        ],
                    ],
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'number_range',
                ],
                [
                    'field'      => 'done_meetings',
                    'header'     => ['ru' => 'Встреча проведена', 'en' => 'Meetings done'],
                    'type'       => 'relation_aggregate',
                    'aggregate'  => [
                        'function' => 'count',
                        'relation' => 'tasks',
                        'where'    => [
                            ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                            ['column' => 'status', 'operator' => '=', 'value' => 100],
                        ],
                    ],
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'number_range',
                ],
                [
                    'field'       => 'usersManager.users_name',
                    'header'      => ['ru' => 'Менеджер заявки', 'en' => 'Application manager'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                ],
                [
                    'field'      => 'utm_source',
                    'header'     => ['ru' => 'UTM Source', 'en' => 'UTM Source'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_medium',
                    'header'     => ['ru' => 'UTM Medium', 'en' => 'UTM Medium'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_campaign',
                    'header'     => ['ru' => 'UTM Campaign', 'en' => 'UTM Campaign'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'estateBuysUtm.utm_term',
                    'header'     => ['ru' => 'UTM Term', 'en' => 'UTM Term'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
                [
                    'field'      => 'utm_content',
                    'header'     => ['ru' => 'UTM Content', 'en' => 'UTM Content'],
                    'type'       => 'text',
                    'sortable'   => false,
                    'filterable' => true,
                ],
            ],
            'where' => [
                [
                    'type'       => 'whereHas',
                    'relation'   => 'tasks',
                    'conditions' => [
                        ['column' => 'custom_type', 'operator' => 'in', 'value' => ['meeting', 'meeting_house']],
                    ],
                ],
            ],
            'sort' => [
                'default' => ['field' => 'estate_buy_id', 'direction' => 'desc'],
            ],
            'pagination' => [
                'default' => 50,
                'options' => [25, 50, 100, 200],
            ],
        ];

        $meetingsAttributes = [
            'config'       => $meetingsConfig,
            'is_system'    => false,
            'is_published' => true,
            'user_id'      => $user->id,
            'company_id'   => $company->id,
            'title' => [
                'ru' => 'SABA - Реестр встреч',
                'en' => 'SABA - Meetings Registry',
            ],
            'description' => [
                'ru' => 'Реестр заявок с количеством назначенных/проведённых встреч и менеджерами встреч',
                'en' => 'Applications registry with scheduled/conducted meeting counts and meeting managers',
            ],
        ];

        $existingMeetings = Report::where('title->ru', 'SABA - Реестр встреч')
            ->where('company_id', $company->id)
            ->where('is_system', false)
            ->first();

        if ($existingMeetings) {
            $existingMeetings->update($meetingsAttributes);
        } else {
            Report::create($meetingsAttributes);
        }
    }
}
