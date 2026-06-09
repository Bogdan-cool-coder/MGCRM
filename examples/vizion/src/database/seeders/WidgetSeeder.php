<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Widget;
use Illuminate\Database\Seeder;

class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::where('name', 'Vizion')->where('is_system', true)->firstOrFail();

        $widgets = [
            // ─── SALES ───────────────────────────────────────────────────────────

            // 1. Сделки по менеджерам (количество проведённых)
            [
                'name'         => ['ru' => 'Сделки по менеджерам', 'en' => 'Deals by Manager'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateDeals',
                    'where'         => [
                        ['type' => 'where', 'field' => 'deal_status', 'operator' => '=', 'value' => 150],
                        ['type' => 'whereNotNull', 'field' => 'deal_date'],
                    ],
                    'group_by'   => ['fields' => ['usersManager.users_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'bar',
                        'label_field' => 'usersManager.users_name',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Сделок', 'en' => 'Deals'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'deal_date',
                ],
            ],

            // 2. Выручка по менеджерам (сумма проведённых сделок)
            [
                'name'         => ['ru' => 'Выручка по менеджерам', 'en' => 'Revenue by Manager'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateDeals',
                    'where'         => [
                        ['type' => 'where', 'field' => 'deal_status', 'operator' => '=', 'value' => 150],
                        ['type' => 'whereNotNull', 'field' => 'deal_date'],
                    ],
                    'group_by'   => ['fields' => ['usersManager.users_name']],
                    'aggregates' => [
                        ['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'bar',
                        'label_field' => 'usersManager.users_name',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Выручка', 'en' => 'Revenue'],
                    ],
                    'order_by'     => [['field' => 'value', 'dir' => 'desc']],
                    'period_field' => 'deal_date',
                ],
            ],

            // 3. Сделки по статусам — human-readable via relation dot-path
            //    Исключаем только "Не определён" (5) как технический мусор.
            //    Все остальные 5 статусов показываются с названиями.
            [
                'name'         => ['ru' => 'Сделки по статусам', 'en' => 'Deals by Status'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateDeals',
                    'where'         => [
                        ['type' => 'whereNotIn', 'field' => 'deal_status', 'value' => [5]],
                    ],
                    'group_by'   => ['fields' => ['estateDealsStatuses.status_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'doughnut',
                        'label_field' => 'estateDealsStatuses.status_name',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Сделок', 'en' => 'Deals'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'deal_date',
                ],
            ],

            // 4. Продажи по ЖК (сумма проданных объектов, all-time snapshot)
            //    EstateSells не имеет date-поля — period_field не применяется.
            [
                'name'         => ['ru' => 'Продажи по ЖК', 'en' => 'Sales by Complex'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateSells',
                    'where'         => [
                        ['type' => 'where', 'field' => 'estate_sell_status', 'operator' => '=', 'value' => 100],
                    ],
                    'group_by'   => ['fields' => ['estateHouses.complex_name']],
                    'aggregates' => [
                        ['field' => 'estate_price', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'bar',
                        'label_field' => 'estateHouses.complex_name',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Сумма продаж', 'en' => 'Sales Amount'],
                    ],
                    'order_by' => [['field' => 'value', 'dir' => 'desc']],
                ],
            ],

            // 5. Структура фонда — human-readable via денормализованное поле estate_sell_status_name
            //    (прямое bare-поле, не dot-path). Значения: Подбор, Сделка проведена, Бронь, ...
            [
                'name'         => ['ru' => 'Структура фонда', 'en' => 'Fund Structure'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateSells',
                    'where'         => [],
                    'group_by'   => ['fields' => ['estate_sell_status_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'doughnut',
                        'label_field' => 'estate_sell_status_name',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Объектов', 'en' => 'Units'],
                    ],
                    'order_by' => [['field' => 'cnt', 'dir' => 'desc']],
                ],
            ],

            // 6. Выручка по отделам (проведённые сделки, с привязкой к отделу)
            [
                'name'         => ['ru' => 'Выручка по отделам', 'en' => 'Revenue by Department'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateDeals',
                    'where'         => [
                        ['type' => 'where',        'field' => 'deal_status',     'operator' => '=', 'value' => 150],
                        ['type' => 'whereNotNull', 'field' => 'departments_id'],
                    ],
                    'group_by'   => ['fields' => ['companyDepartments.department_name']],
                    'aggregates' => [
                        ['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'bar',
                        'label_field' => 'companyDepartments.department_name',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Выручка', 'en' => 'Revenue'],
                    ],
                    'order_by'     => [['field' => 'value', 'dir' => 'desc']],
                    'period_field' => 'deal_date',
                ],
            ],

            // ─── TEMPORAL DYNAMICS ───────────────────────────────────────────────

            // 7. Динамика продаж по месяцам (line, последние 12 мес)
            [
                'name'         => ['ru' => 'Динамика продаж', 'en' => 'Sales Trend'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateDeals',
                    'where'         => [
                        ['type' => 'where', 'field' => 'deal_status', 'operator' => '=', 'value' => 150],
                        ['type' => 'whereNotNull', 'field' => 'deal_date'],
                    ],
                    'group_by'   => ['fields' => ['deal_date|month']],
                    'aggregates' => [
                        ['field' => 'deal_sum', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'line',
                        'label_field' => 'deal_date|month',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Выручка', 'en' => 'Revenue'],
                    ],
                    'order_by'     => [['field' => 'deal_date|month', 'dir' => 'asc']],
                    'period_field' => 'deal_date',
                ],
            ],

            // 8. Динамика лидов по месяцам (line, последние 12 мес)
            [
                'name'         => ['ru' => 'Динамика лидов', 'en' => 'Leads Trend'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateBuys',
                    'where'         => [
                        ['type' => 'whereNotIn',  'field' => 'status', 'value' => [0]],
                        ['type' => 'whereNotNull', 'field' => 'date_added'],
                    ],
                    'group_by'   => ['fields' => ['date_added|month']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'line',
                        'label_field' => 'date_added|month',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Лидов', 'en' => 'Leads'],
                    ],
                    'order_by'     => [['field' => 'date_added|month', 'dir' => 'asc']],
                    'period_field' => 'date_added',
                ],
            ],

            // 9. Динамика поступлений по месяцам (line, Finances проведённые, последние 12 мес)
            [
                'name'         => ['ru' => 'Динамика поступлений', 'en' => 'Receipts Trend'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'Finances',
                    'where'         => [
                        ['type' => 'where',        'field' => 'status',  'operator' => '=', 'value' => 1],
                        ['type' => 'whereNotNull', 'field' => 'deal_id'],
                        ['type' => 'whereIn',      'field' => 'types_id', 'value' => [3786, 3788]],
                    ],
                    'group_by'   => ['fields' => ['date_added|month']],
                    'aggregates' => [
                        ['field' => 'summa', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'line',
                        'label_field' => 'date_added|month',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Поступления', 'en' => 'Receipts'],
                    ],
                    'order_by'     => [['field' => 'date_added|month', 'dir' => 'asc']],
                    'period_field' => 'date_added',
                ],
            ],

            // ─── MARKETING ───────────────────────────────────────────────────────

            // 10. Лиды по рекламным каналам (top-10 + Другие)
            [
                'name'         => ['ru' => 'Лиды по каналам', 'en' => 'Leads by Channel'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateBuys',
                    'where'         => [
                        ['type' => 'whereNotNull', 'field' => 'advertising_channel_id'],
                    ],
                    'group_by'   => ['fields' => ['estateAdvertisingChannels.name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'         => 'bar',
                        'label_field'  => 'estateAdvertisingChannels.name',
                        'value_field'  => 'cnt',
                        'label'        => ['ru' => 'Лидов', 'en' => 'Leads'],
                        'limit'        => 10,
                        'others_label' => ['ru' => 'Другие', 'en' => 'Others'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'date_added',
                ],
            ],

            // 11. Лиды по менеджерам (exclude_empty по умолчанию убирает пустые)
            [
                'name'         => ['ru' => 'Лиды по менеджерам', 'en' => 'Leads by Manager'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateBuys',
                    'where'         => [
                        ['type' => 'whereNotIn',  'field' => 'status', 'value' => [0]],
                        ['type' => 'whereNotNull', 'field' => 'manager_id'],
                    ],
                    'group_by'   => ['fields' => ['usersManager.users_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'         => 'bar',
                        'label_field'  => 'usersManager.users_name',
                        'value_field'  => 'cnt',
                        'label'        => ['ru' => 'Лидов', 'en' => 'Leads'],
                        'limit'        => 10,
                        'others_label' => ['ru' => 'Другие', 'en' => 'Others'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'date_added',
                ],
            ],

            // 12. Лиды по статусам (воронка лидов с читаемыми названиями)
            //     Bare-поле status_name (денормализовано в estate_buys).
            //     Исключаем status=0 (Удалено/технический).
            [
                'name'         => ['ru' => 'Лиды по статусам', 'en' => 'Leads by Status'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateBuys',
                    'where'         => [
                        ['type' => 'whereNotIn', 'field' => 'status', 'value' => [0]],
                    ],
                    'group_by'   => ['fields' => ['status_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'doughnut',
                        'label_field' => 'status_name',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Лидов', 'en' => 'Leads'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'date_added',
                ],
            ],

            // ─── FINANCE ─────────────────────────────────────────────────────────

            // 13. Поступления по типам (проведённые платежи, pie-разбивка по types_name)
            //     Исключаем 3787 (Возврат — обязательство застройщика, не поступление).
            //     types_name — прямое поле, probe подтвердил: 2 сегмента + Бронь.
            [
                'name'         => ['ru' => 'Поступления по типам', 'en' => 'Receipts by Type'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'Finances',
                    'where'         => [
                        ['type' => 'where',        'field' => 'status',  'operator' => '=', 'value' => 1],
                        ['type' => 'whereNotNull', 'field' => 'deal_id'],
                        ['type' => 'whereIn',      'field' => 'types_id', 'value' => [3786, 3788]],
                    ],
                    'group_by'   => ['fields' => ['types_name']],
                    'aggregates' => [
                        ['field' => 'summa', 'fn' => 'sum', 'as' => 'value'],
                    ],
                    'chart' => [
                        'type'        => 'pie',
                        'label_field' => 'types_name',
                        'value_field' => 'value',
                        'label'       => ['ru' => 'Сумма', 'en' => 'Amount'],
                    ],
                    'order_by'     => [['field' => 'value', 'dir' => 'desc']],
                    'period_field' => 'date_added',
                ],
            ],

            // 14. Лиды по ЖК (откуда приходят лиды)
            [
                'name'         => ['ru' => 'Лиды по ЖК', 'en' => 'Leads by Complex'],
                'is_system'    => true,
                'is_published' => true,
                'company_id'   => $company->id,
                'user_id'      => null,
                'config'       => [
                    'primary_model' => 'EstateBuys',
                    'where'         => [
                        ['type' => 'whereNotIn',  'field' => 'status', 'value' => [0]],
                        ['type' => 'whereNotNull', 'field' => 'house_id'],
                    ],
                    'group_by'   => ['fields' => ['estateHouses.complex_name']],
                    'aggregates' => [
                        ['fn' => 'count', 'as' => 'cnt'],
                    ],
                    'chart' => [
                        'type'        => 'bar',
                        'label_field' => 'estateHouses.complex_name',
                        'value_field' => 'cnt',
                        'label'       => ['ru' => 'Лидов', 'en' => 'Leads'],
                    ],
                    'order_by'     => [['field' => 'cnt', 'dir' => 'desc']],
                    'period_field' => 'date_added',
                ],
            ],
        ];

        // Remove legacy system widgets that were replaced or renamed.
        // Must detach from all dashboards first to avoid FK violation.
        $legacyNames = [
            'Продажи по корпусам',
            'Поступления платежей',   // replaced by Поступления по типам
            'Дебиторская задолженность', // replaced by Лиды по статусам
        ];
        foreach ($legacyNames as $legacyName) {
            $legacy = Widget::where('is_system', true)
                ->where('name->ru', $legacyName)
                ->first();
            if ($legacy) {
                $legacy->dashboards()->detach();
                $legacy->delete();
            }
        }

        $createdCount = 0;
        $updatedCount = 0;

        foreach ($widgets as $widgetData) {
            $nameRu = $widgetData['name']['ru'];

            $widget = Widget::where('is_system', true)
                ->where('name->ru', $nameRu)
                ->first();

            if (!$widget) {
                Widget::create($widgetData);
                $createdCount++;
            } else {
                $widget->update($widgetData);
                $updatedCount++;
            }
        }

        $this->command->info("Widgets: {$createdCount} created, {$updatedCount} updated.");
    }
}
