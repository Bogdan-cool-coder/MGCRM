# Дебиторская задолженность — сгруппированная версия (ПОД СОМНЕНИЕМ)

Дата: 2026-05-12

Версия отчёта с group_by по контрагенту. Пользователь усомнился, что нужна именно такая раскладка. Сохранено на случай возврата.

```php
'config' => [
    'primary_model' => 'Finances',
    'columns' => [
        [
            'field'    => 'date_to',
            'header'   => ['ru' => 'Дата платежа', 'en' => 'Due date'],
            'type'     => 'datetime',
            'sortable' => true,
            'badge'    => [
                'condition' => [
                    'type'           => 'overdue',
                    'date_field'     => 'date_to',
                    'unpaid_status'  => [3],
                    'status_field'   => 'status',
                ],
                'severity' => 'danger',
                'label'    => ['ru' => 'Просрочено {days} д.', 'en' => 'Overdue {days}d'],
            ],
        ],
        [
            'field'    => 'estateSells.estateHouses.name',
            'header'   => ['ru' => 'Дом', 'en' => 'House'],
            'type'     => 'text',
            'sortable' => true,
        ],
        [
            'field'         => 'estateSells.estate_sell_id',
            'header'        => ['ru' => 'Номер объекта', 'en' => 'Unit No.'],
            'type'          => 'link',
            'label_field'   => 'estateSells.geo_flatnum',
            'link_template' => '{crm_url}/account/estate/view/{estateSells.estate_sell_id}/',
            'sortable'      => false,
        ],
        [
            'field'    => 'estateDeals.contactsBuy.contacts_buy_name',
            'header'   => ['ru' => 'Контрагент', 'en' => 'Counterparty'],
            'type'     => 'text',
            'truncate' => 'first_word',
        ],
        [
            'field'    => 'summa',
            'header'   => ['ru' => 'К оплате', 'en' => 'Due amount'],
            'type'     => 'currency',
            'sortable' => true,
        ],
    ],
    'chart' => null,
    'where' => [
        ['type' => 'where', 'field' => 'status', 'value' => 3],
        ['type' => 'whereNotNull', 'field' => 'deal_id'],
        ['type' => 'whereIn', 'field' => 'types_id', 'value' => [3786, 3787, 3788]],
        ['type' => 'where', 'field' => 'date_to', 'operator' => '<=', 'value' => '{end_of_month}'],
    ],
    'totals' => ['summa'],
    'sort' => [
        'default' => ['field' => 'date_to', 'direction' => 'desc'],
    ],
    'pagination' => [
        'default' => 50,
        'options' => [25, 50, 100, 200],
    ],
    'group_by' => [
        'fields' => [
            'estateSells.estateHouses.name',
            'estateSells.geo_flatnum_postoffice',
            'estateDeals.contactsBuy.contacts_buy_name',
        ],
        'aggregates' => [
            'overdue_count' => [
                'type'  => 'count',
                'where' => [
                    'type'          => 'overdue',
                    'date_field'    => 'date_to',
                    'unpaid_status' => [3],
                    'status_field'  => 'status',
                ],
                'label' => ['ru' => 'Просрочено платежей', 'en' => 'Overdue payments'],
            ],
            'overdue_sum' => [
                'type'  => 'sum',
                'field' => 'summa',
                'where' => [
                    'type'          => 'overdue',
                    'date_field'    => 'date_to',
                    'unpaid_status' => [3],
                    'status_field'  => 'status',
                ],
                'label' => ['ru' => 'Сумма просрочки', 'en' => 'Overdue amount'],
            ],
            'group_total' => ['type' => 'sum', 'field' => 'summa', 'label' => ['ru' => 'Всего к оплате', 'en' => 'Total due']],
        ],
        'collapsible'          => true,
        'collapsed_by_default' => true,
    ],
],
```

TODO: подтвердить с пользователем — оставлять или удалить окончательно.
