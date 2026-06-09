<?php

namespace Database\Seeders;

use App\Models\Report;
use Illuminate\Database\Seeder;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        $reports = [
            [
                'title' => ['ru' => 'Реестр договоров', 'en' => 'Contracts Registry'],
                'description' => ['ru' => 'Реестр договоров с информацией об оплатах', 'en' => 'Contracts registry with payment information'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 10,
                'config' => [
                    'primary_model' => 'EstateDeals',
                    'columns' => [
                        ['field' => 'deal_date', 'header' => ['ru' => 'Дата договора', 'en' => 'Contract Date'], 'type' => 'date', 'sortable' => true, 'description' => ['ru' => 'Дата подписания договора купли-продажи', 'en' => 'Date the sale and purchase agreement was signed'], 'filter_default' => ['from' => '{start_of_prev_month}', 'to' => '{end_of_month}']],
                        [
                            'field'          => 'estateSells.estate_sell_id',
                            'type'           => 'link',
                            'header'         => ['ru' => 'Номер договора', 'en' => 'Contract No.'],
                            'sortable'       => false,
                            'filterable'     => true,
                            'label_field'    => 'agreement_number',
                            'label_fallback' => ['ru' => 'Не указан', 'en' => 'Not specified'],
                            'unit'           => ['ru' => 'шт.', 'en' => 'pcs'],
                            'link_template'  => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'filter_type'    => 'async_select',
                            'filter_field'   => 'agreement_number',
                            'footer'         => ['agg' => 'count'],
                        ],
                        [
                            'field'        => 'estateSells.estateHouses.name',
                            'header'       => ['ru' => 'Дом', 'en' => 'House'],
                            'type'         => 'text',
                            'sortable'     => true,
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.geo_flatnum',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => true,
                            'filterable'    => true,
                            'filter_type'   => 'async_select',
                            'filter_field'  => 'estateSells.geo_flatnum',
                        ],
                        ['field' => 'estateSells.estate_floor', 'type' => 'number', 'header' => ['ru' => 'Этаж', 'en' => 'Floor'], 'sortable' => true, 'align' => 'center'],
                        [
                            'field'    => 'deal_area',
                            'header'   => ['ru' => 'Площадь', 'en' => 'Area'],
                            'type'     => 'number',
                            'sortable' => true,
                            'format'   => '0.00',
                            'unit'     => ['ru' => 'м²', 'en' => 'm²'],
                            'footer'   => ['agg' => 'sum'],
                        ],
                        [
                            'field'        => 'contactsBuy.contacts_buy_name',
                            'header'       => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                            'type'         => 'text',
                            'truncate'     => 'first_word',
                            'sortable'     => true,
                            'filter_type'  => 'async_select',
                        ],
                        ['field' => 'deal_sum', 'header' => ['ru' => 'Стоимость', 'en' => 'Price'], 'type' => 'currency', 'currency_in_header' => true, 'sortable' => true, 'description' => ['ru' => 'Полная сумма договора', 'en' => 'Total contract amount']],
                        ['field' => 'finances_income', 'header' => ['ru' => 'Оплачено', 'en' => 'Paid'], 'type' => 'currency', 'currency_in_header' => true, 'sortable' => true, 'description' => ['ru' => 'Сумма фактически проведённых платежей по договору (статус «Проведено»)', 'en' => 'Total payments completed against the contract (status "Paid")']],
                        ['field' => 'to_pay', 'header' => ['ru' => 'К оплате', 'en' => 'To Pay'], 'type' => 'currency', 'currency_in_header' => true, 'sortable' => true, 'expression' => 'deal_sum - finances_income', 'description' => ['ru' => 'Остаток долга: сумма договора минус оплаченные поступления', 'en' => 'Remaining balance: contract amount minus payments received']],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'ID объекта', 'en' => 'Unit ID'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.estate_sell_id',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => false,
                            'filterable'    => false,
                            'is_crm_id'     => true,
                        ],
                    ],
                    'sort' => [
                        'default' => ['field' => 'deal_date', 'direction' => 'desc']
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200]
                    ],
                    'where' => [
                        ['type' => 'whereNotNull', 'field' => 'deal_date'],
                    ],
                    'totals' => ['deal_sum', 'finances_income', 'to_pay'],
                    'primary_filter' => 'deal_date',
                ],
            ],
            [
                'title' => ['ru' => 'Ежедневник поступлений', 'en' => 'Receipts diary'],
                'description' => ['ru' => 'Проведённые платежи по сделкам', 'en' => 'Completed payments on deals'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 20,
                'config' => [
                    'primary_model' => 'Finances',
                    'columns' => [
                        [
                            'field'          => 'date_to',
                            'header'         => ['ru' => 'Дата оплаты', 'en' => 'Payment date'],
                            'type'           => 'date',
                            'sortable'       => true,
                            'description'    => ['ru' => 'Дата фактического проведения платежа (поле date_to совпадает с date_added для проведённых платежей)', 'en' => 'Date the payment was actually processed (date_to equals date_added for completed payments)'],
                            'filter_default' => ['from' => null, 'to' => '{today}'],
                        ],
                        [
                            'field'        => 'estateSells.estateHouses.name',
                            'header'       => ['ru' => 'Дом', 'en' => 'House'],
                            'type'         => 'text',
                            'sortable'     => true,
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.geo_flatnum',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => true,
                            'filterable'    => false,
                        ],
                        [
                            'field'        => 'estateDeals.contactsBuy.contacts_buy_name',
                            'header'       => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                            'type'         => 'text',
                            'truncate'     => 'first_word',
                            'sortable'     => true,
                            'filter_type'  => 'async_select',
                        ],
                        [
                            'field'        => 'summa',
                            'header'       => ['ru' => 'Оплачено', 'en' => 'Paid'],
                            'type'         => 'currency',
                            'sortable'     => true,
                            'description'  => ['ru' => 'Сумма одного проведённого платежа по данной строке', 'en' => 'Amount of this single completed payment entry'],
                        ],
                        [
                            'field'              => 'cumulative_receipts',
                            'header'             => ['ru' => 'Накопленные поступления', 'en' => 'Cumulative receipts'],
                            'type'               => 'window_aggregate',
                            'value_type'         => 'currency',
                            'ignore_date_filters' => true,
                            'description'        => ['ru' => 'Суммарные фактические поступления по всем проведённым платежам данного объекта и сделки (SUM по партиции объект + сделка)', 'en' => 'Total actual receipts across all completed payments for this unit and deal (SUM partitioned by unit + deal)'],
                            'aggregate'          => [
                                'fn'        => 'sum',
                                'field'     => 'summa',
                                'partition' => ['estate_sell_id', 'deal_id'],
                            ],
                            'sortable'           => false,
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'ID объекта', 'en' => 'Unit ID'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.estate_sell_id',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => false,
                            'filterable'    => false,
                            'is_crm_id'     => true,
                        ],
                    ],
                    'where' => [
                        ['type' => 'where', 'field' => 'status', 'value' => 1],
                        ['type' => 'whereNotNull', 'field' => 'deal_id'],
                        ['type' => 'whereIn', 'field' => 'types_id', 'value' => [
                            ['$company_var' => 'finance_type_sale_ids'],
                            ['$company_var' => 'finance_type_booking_ids'],
                        ]],
                    ],
                    'totals' => ['summa'],
                    'sort' => [
                        'default' => ['field' => 'date_to', 'direction' => 'desc'],
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200],
                    ],
                ],
            ],
            [
                'title' => ['ru' => 'Непроданные', 'en' => 'Unsold properties'],
                'description' => ['ru' => 'Объекты на подборе и брони', 'en' => 'Properties in search or reserved'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 30,
                'config' => [
                    'primary_model' => 'EstateSells',
                    'columns' => [
                        [
                            'field'       => 'estateHouses.name',
                            'header'      => ['ru' => 'Проект', 'en' => 'Project'],
                            'type'        => 'text',
                            'sortable'    => true,
                            'filter_type' => 'async_select',
                        ],
                        [
                            'field'       => 'estate_sell_category',
                            'header'      => ['ru' => 'Тип объекта', 'en' => 'Property type'],
                            'type'        => 'text',
                            'sortable'    => true,
                            'description' => ['ru' => 'Категория объекта недвижимости: квартира, парковка, коммерческое помещение или кладовая', 'en' => 'Property category: flat, garage, commercial, or storage unit'],
                            'options'     => [
                                'flat'    => ['ru' => 'Квартира',  'en' => 'Flat'],
                                'garage'  => ['ru' => 'Парковка',  'en' => 'Garage'],
                                'comm'    => ['ru' => 'Коммерция', 'en' => 'Commercial'],
                                'storage' => ['ru' => 'Кладовая',  'en' => 'Storage'],
                            ],
                        ],
                        [
                            'field'       => 'geo_flatnum',
                            'header'      => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
                            'type'        => 'text',
                            'sortable'    => true,
                            'filter_type' => 'async_select',
                        ],
                        [
                            'field'       => 'estate_price',
                            'header'      => ['ru' => 'Стоимость публичная', 'en' => 'Public price'],
                            'type'        => 'currency',
                            'sortable'    => true,
                            'description' => ['ru' => 'Актуальная публичная стоимость объекта, выставленная для покупателей (прайс-лист)', 'en' => 'Current public listing price of the property (price list)'],
                        ],
                        [
                            'field'       => 'estate_sell_status',
                            'header'      => ['ru' => 'Статус', 'en' => 'Status'],
                            'type'        => 'text',
                            'sortable'    => true,
                            'description' => ['ru' => 'Статус объекта: «Подбор» — свободен для продажи; «Бронь» — зарезервирован покупателем; «Маркетинговая бронь» — зарезервирован в маркетинговых целях', 'en' => 'Property status: "In search" — available for sale; "Reserved" — booked by a buyer; "Marketing reserve" — held for marketing purposes'],
                            'options'     => [
                                '20' => ['ru' => 'Подбор',             'en' => 'In search'],
                                '30' => ['ru' => 'Бронь',              'en' => 'Reserved'],
                                '32' => ['ru' => 'Маркетинговая бронь', 'en' => 'Marketing reserve'],
                            ],
                        ],
                        [
                            'field'         => 'estate_sell_id',
                            'header'        => ['ru' => 'ID объекта', 'en' => 'Unit ID'],
                            'type'          => 'link',
                            'label_field'   => 'estate_sell_id',
                            'link_template' => '{crm_url}/account/estate/view/{estate_sell_id}/',
                            'sortable'      => false,
                            'filterable'    => false,
                            'is_crm_id'     => true,
                        ],
                    ],
                    'sort' => [
                        'default' => ['field' => 'estate_price', 'direction' => 'desc'],
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200],
                    ],
                    'where' => [
                        ['type' => 'whereIn', 'field' => 'estate_sell_status', 'value' => [20, 30, 32]],
                    ],
                    'totals' => ['estate_price'],
                ],
            ],
            [
                'title' => ['ru' => 'Дебиторская задолженность', 'en' => 'Receivables'],
                'description' => ['ru' => 'Неоплаченные плановые платежи по сделкам', 'en' => 'Unpaid scheduled payments on deals'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 40,
                'config' => [
                    'primary_model' => 'Finances',
                    'columns' => [
                        [
                            'field'          => 'date_to',
                            'header'         => ['ru' => 'Дата платежа', 'en' => 'Payment date'],
                            'type'           => 'date',
                            'sortable'       => true,
                            'description'    => ['ru' => 'Плановая дата, до которой должен быть внесён платёж по графику. Просроченные строки отмечены красным бейджем с количеством дней просрочки.', 'en' => 'Scheduled due date for the payment. Overdue rows are highlighted with a red badge showing days past due.'],
                            'badge'          => [
                                'condition' => [
                                    'type'          => 'overdue',
                                    'date_field'    => 'date_to',
                                    'unpaid_status' => [3],
                                    'status_field'  => 'status',
                                ],
                                'severity' => 'danger',
                                'label'    => ['ru' => '{days}д', 'en' => '{days}d'],
                            ],
                            'filter_default' => ['from' => '2023-12-31', 'to' => '{end_of_month}'],
                        ],
                        [
                            'field'        => 'estateSells.estateHouses.name',
                            'header'       => ['ru' => 'Дом', 'en' => 'House'],
                            'type'         => 'text',
                            'sortable'     => true,
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.geo_flatnum',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => true,
                            'filterable'    => false,
                            'footer'        => ['agg' => 'count'],
                        ],
                        [
                            'field'        => 'contactsOut.contacts_buy_name',
                            'header'       => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                            'type'         => 'text',
                            'truncate'     => 'first_word',
                            'sortable'     => true,
                            'filter_type'  => 'async_select',
                        ],
                        [
                            'field'        => 'summa',
                            'header'       => ['ru' => 'К оплате', 'en' => 'Amount due'],
                            'type'         => 'currency',
                            'sortable'     => true,
                            'description'  => ['ru' => 'Сумма одного неоплаченного планового платежа по этой строке', 'en' => 'Amount of this single unpaid scheduled payment'],
                        ],
                        [
                            'field'              => 'cumulative_debt',
                            'header'             => ['ru' => 'Накопленная задолженность', 'en' => 'Cumulative debt'],
                            'type'               => 'window_aggregate',
                            'value_type'         => 'currency',
                            'ignore_date_filters' => true,
                            'description'        => ['ru' => 'Суммарный накопленный долг по всем неоплаченным платежам данного объекта и сделки (SUM по партиции объект + сделка)', 'en' => 'Total accumulated debt across all unpaid payments for this unit and deal (SUM partitioned by unit + deal)'],
                            'aggregate'          => [
                                'fn'        => 'sum',
                                'field'     => 'summa',
                                'partition' => ['estate_sell_id', 'deal_id'],
                            ],
                            'sortable'           => false,
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'ID объекта', 'en' => 'Unit ID'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.estate_sell_id',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => false,
                            'filterable'    => false,
                            'is_crm_id'     => true,
                        ],
                    ],
                    'where' => [
                        ['type' => 'where', 'field' => 'status', 'value' => 3],
                        ['type' => 'whereNotNull', 'field' => 'deal_id'],
                        ['type' => 'whereIn', 'field' => 'types_id', 'value' => [
                            ['$company_var' => 'finance_type_sale_ids'],
                            ['$company_var' => 'finance_type_booking_ids'],
                        ]],
                    ],
                    'totals' => ['summa'],
                    'sort' => [
                        'default' => ['field' => 'date_to', 'direction' => 'desc'],
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200],
                    ],
                ],
            ],
            [
                'title' => ['ru' => 'Свод по проектам', 'en' => 'Project summary'],
                'description' => ['ru' => 'Сводные финансовые показатели по домам', 'en' => 'Financial summary per house'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 50,
                'config' => [
                    'primary_model' => 'EstateHouses',
                    'columns' => [
                        [
                            'field'        => 'name',
                            'header'       => ['ru' => 'Проект', 'en' => 'Project'],
                            'type'         => 'text',
                            'sortable'     => true,
                        ],
                        [
                            'field'        => 'total_area',
                            'header'       => ['ru' => 'Общая площадь, м²', 'en' => 'Total area, m²'],
                            'type'         => 'relation_aggregate',
                            'value_type'   => 'number',
                            'sortable'     => true,
                            'filterable'   => true,
                            'filter_type'  => 'number_range',
                            'description'  => ['ru' => 'Суммарная площадь всех объектов проекта (сумма estate_area по всем объектам дома)', 'en' => 'Total area of all property units in the project (sum of estate_area across all units in this house)'],
                            'aggregate'    => [
                                'function'    => 'sum',
                                'relation'    => 'estateSells',
                                'value_field' => 'estate_area',
                            ],
                        ],
                        [
                            'field'              => 'total_value',
                            'header'             => ['ru' => 'Стоимость проекта', 'en' => 'Project value'],
                            'type'               => 'currency',
                            'currency_in_header' => true,
                            'expression'         => '(unsold_total ? unsold_total : 0) + (sold_total ? sold_total : 0)',
                            'description'        => ['ru' => 'Общая стоимость проекта: сумма публичных цен непроданных объектов плюс договорные суммы проданных', 'en' => 'Total project value: sum of public prices for unsold units plus contract amounts for sold units'],
                        ],
                        [
                            'field'              => 'unsold_total',
                            'header'             => ['ru' => 'Стоимость непроданных', 'en' => 'Unsold value'],
                            'type'               => 'relation_aggregate',
                            'value_type'         => 'currency',
                            'currency_in_header' => true,
                            'sortable'           => true,
                            'filterable'         => true,
                            'filter_type'        => 'number_range',
                            'description'        => ['ru' => 'Суммарная публичная стоимость объектов в статусах «Подбор», «Бронь» и «Маркетинговая бронь» — то есть ещё не проданных', 'en' => 'Total public price of units with status "In search", "Reserved", or "Marketing reserve" — i.e. not yet sold'],
                            'aggregate'    => [
                                'function'    => 'sum',
                                'relation'    => 'estateSells',
                                'value_field' => 'estate_price',
                                'where'       => [
                                    ['column' => 'estate_sell_status', 'operator' => 'in', 'value' => [20, 30, 32]],
                                ],
                            ],
                        ],
                        [
                            'field'              => 'sold_total',
                            'header'             => ['ru' => 'Стоимость проданных', 'en' => 'Sold value'],
                            'type'               => 'relation_aggregate',
                            'value_type'         => 'currency',
                            'currency_in_header' => true,
                            'sortable'           => true,
                            'filterable'         => true,
                            'filter_type'        => 'number_range',
                            'description'        => ['ru' => 'Суммарная договорная стоимость проданных объектов (не считая аннулированных сделок)', 'en' => 'Total contract amount of sold units (excluding cancelled deals, status ≠ 140)'],
                            'aggregate'    => [
                                'function'    => 'sum',
                                'relation'    => 'estateSells',
                                'through'     => ['estateDeals'],
                                'value_field' => 'deal_sum',
                                'through_where' => [
                                    0 => [['column' => 'deal_status', 'operator' => '!=', 'value' => 140]],
                                ],
                            ],
                        ],
                        [
                            'field'              => 'paid_total',
                            'header'             => ['ru' => 'Оплачено', 'en' => 'Paid'],
                            'type'               => 'relation_aggregate',
                            'value_type'         => 'currency',
                            'currency_in_header' => true,
                            'sortable'           => true,
                            'filterable'         => true,
                            'filter_type'        => 'number_range',
                            'description'        => ['ru' => 'Сумма фактически проведённых поступлений по сделкам проекта (статус платежа «Проведено», типы «Поступления от продажи» и «Бронь»)', 'en' => 'Total payments actually received for deals in this project (payment status "Paid", types "Sale proceeds" and "Reservation")'],
                            'aggregate'    => [
                                'function'    => 'sum',
                                'relation'    => 'estateSells',
                                'through'     => ['estateDeals', 'finances'],
                                'value_field' => 'summa',
                                'through_where' => [
                                    0 => [['column' => 'deal_status', 'operator' => '!=', 'value' => 140]],
                                ],
                                'where' => [
                                    ['column' => 'status',   'operator' => '=',  'value' => 1],
                                    ['column' => 'types_id', 'operator' => 'in', 'value' => [
                                        ['$company_var' => 'finance_type_sale_ids'],
                                        ['$company_var' => 'finance_type_booking_ids'],
                                    ]],
                                ],
                            ],
                        ],
                        [
                            'field'              => 'due_total',
                            'header'             => ['ru' => 'К оплате', 'en' => 'Due'],
                            'type'               => 'currency',
                            'currency_in_header' => true,
                            'expression'         => '(sold_total ? sold_total : 0) - (paid_total ? paid_total : 0)',
                            'description'        => ['ru' => 'Остаток долга покупателей по проекту: договорные суммы проданных объектов минус фактические поступления', 'en' => 'Remaining buyer debt for the project: total contract amounts of sold units minus payments received'],
                        ],
                        [
                            'field'              => 'avg_price_m2',
                            'header'             => ['ru' => 'Ст./м²', 'en' => 'Price /m²'],
                            'type'               => 'currency',
                            'currency_in_header' => true,
                            'currency_suffix'    => ['ru' => '/м²', 'en' => '/m²'],
                            'expression'         => 'total_area > 0 ? ((unsold_total ? unsold_total : 0) + (sold_total ? sold_total : 0)) / total_area : 0',
                            'description'        => ['ru' => 'Средняя стоимость квадратного метра: общая стоимость проекта делённая на суммарную площадь всех объектов', 'en' => 'Average price per square metre: total project value divided by total area of all units'],
                        ],
                    ],
                    'sort' => [
                        'default' => ['field' => 'name', 'direction' => 'asc'],
                    ],
                    'where' => [
                        ['type' => 'whereNotNull', 'field' => 'name'],
                        ['type' => 'where', 'field' => 'name', 'operator' => '!=', 'value' => ''],
                    ],
                    'totals' => ['total_area', 'total_value', 'unsold_total', 'sold_total', 'paid_total', 'due_total', 'avg_price_m2'],
                ],
            ],
            [
                'title' => ['ru' => 'Акты сверки', 'en' => 'Reconciliation acts'],
                'description' => ['ru' => 'Сверка платежей по сделкам', 'en' => 'Payment reconciliation by deal'],
                'is_system' => true,
                'is_published' => true,
                'sort_order' => 60,
                'config' => [
                    'primary_model' => 'EstateDeals',
                    'columns' => [
                        [
                            'field'        => 'contactsBuy.contacts_buy_name',
                            'header'       => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
                            'type'         => 'text',
                            'truncate'     => 'first_word',
                            'sortable'     => true,
                            'filter_type'  => 'async_select',
                        ],
                        [
                            'field'          => 'estateSells.estate_sell_id',
                            'header'         => ['ru' => 'Номер договора', 'en' => 'Contract No.'],
                            'type'           => 'link',
                            'label_field'    => 'agreement_number',
                            'label_fallback' => ['ru' => 'Не указан', 'en' => 'Not specified'],
                            'link_template'  => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'       => true,
                            'filterable'     => true,
                            'filter_type'    => 'async_select',
                            'filter_field'   => 'agreement_number',
                        ],
                        [
                            'field'        => 'deal_sum',
                            'header'       => ['ru' => 'Стоимость договора', 'en' => 'Contract amount'],
                            'type'         => 'currency',
                            'sortable'     => true,
                            'description'  => ['ru' => 'Полная договорная стоимость объекта', 'en' => 'Full contractual value of the property'],
                        ],
                        [
                            'field'        => 'payment_schedule',
                            'header'       => ['ru' => 'График платежей', 'en' => 'Payment schedule'],
                            'type'         => 'payment_schedule',
                            'description'  => ['ru' => 'Детальный график платежей по договору: плановые суммы, фактически оплаченные и остаток. Включает только типы «Поступления от продажи» и «Бронь»; типы по возврату не учитываются.', 'en' => 'Detailed payment schedule for the contract: planned amounts, paid amounts, and balance. Includes only types "Sale proceeds" and "Reservation"; refund types are excluded.'],
                            'payments' => [
                                'relation'    => 'finances',
                                'types_id'    => [
                                    ['$company_var' => 'finance_type_sale_ids'],
                                    ['$company_var' => 'finance_type_booking_ids'],
                                ],
                                'status_paid' => 1,
                                'status_due'  => 3,
                                'expose'      => [
                                    'paid_total' => 'paid_total',
                                    'due_total'  => 'due_total',
                                ],
                            ],
                        ],
                        [
                            'field'        => 'estateSells.estateHouses.name',
                            'header'       => ['ru' => 'Дом', 'en' => 'House'],
                            'type'         => 'text',
                            'sortable'     => true,
                        ],
                        [
                            'field'        => 'estateSells.estate_sell_category',
                            'header'       => ['ru' => 'Тип объекта', 'en' => 'Property type'],
                            'type'         => 'text',
                            'sortable'     => true,
                            'description'  => ['ru' => 'Категория объекта недвижимости: квартира, парковка, коммерческое помещение или кладовая', 'en' => 'Property category: flat, garage, commercial, or storage unit'],
                            'options'      => [
                                'flat'    => ['ru' => 'Квартира',  'en' => 'Flat'],
                                'garage'  => ['ru' => 'Парковка',  'en' => 'Garage'],
                                'comm'    => ['ru' => 'Коммерция', 'en' => 'Commercial'],
                                'storage' => ['ru' => 'Кладовая',  'en' => 'Storage'],
                            ],
                        ],
                        [
                            'field'        => 'estateSells.geo_flatnum',
                            'header'       => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
                            'type'         => 'text',
                            'sortable'     => true,
                            'filter_type'  => 'async_select',
                        ],
                        [
                            'field'         => 'estateSells.estate_sell_id',
                            'header'        => ['ru' => 'ID объекта', 'en' => 'Unit ID'],
                            'type'          => 'link',
                            'label_field'   => 'estateSells.estate_sell_id',
                            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
                            'sortable'      => false,
                            'filterable'    => false,
                            'is_crm_id'     => true,
                        ],
                    ],
                    'sort' => [
                        'default' => ['field' => 'deal_date', 'direction' => 'desc'],
                    ],
                    'pagination' => [
                        'default' => 50,
                        'options' => [25, 50, 100, 200],
                    ],
                    'where' => [
                        ['type' => 'whereNotNull', 'field' => 'deal_date'],
                    ],
                    'totals' => ['deal_sum', 'paid_total', 'due_total'],
                    'primary_filter' => 'contactsBuy.contacts_buy_name',
                ],
            ],
        ];

        foreach ($reports as $reportData) {
            // Check if report already exists by title
            $report = Report::where('title->ru', $reportData['title']['ru'] ?? null)
                ->where('is_system', true)
                ->first();

            if (!$report) {
                $report = Report::create($reportData);
            } else {
                // Update existing system report
                $report->update($reportData);
            }
        }

        $this->command->info('Created ' . count($reports) . ' system reports.');
    }
}
