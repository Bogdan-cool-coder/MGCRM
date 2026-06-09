<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyMacrodataMapping;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Apart Group (Georgian developer, macro_bi_cmp_607).
 *
 * Company upsert is CREDS-SAFE: firstOrCreate by name, macrodata_* fields
 * are only set on initial creation (stub without real creds — owner adds
 * them manually or they already exist on prod). Existing prod record with
 * real credentials is never overwritten.
 *
 * Run:
 *   php artisan db:seed --class=ApartGroupSeeder
 */
class ApartGroupSeeder extends Seeder
{
    public function run(): void
    {
        // ── Company (creds-safe) ───────────────────────────────────────────────
        // firstOrCreate: only touches the record on first creation.
        // On prod the company already exists with real macrodata_* creds —
        // those fields are left untouched.
        $company = Company::firstOrCreate(
            ['name' => 'Apart Group'],
            [
                'crm_url'             => '',
                'currency_code'       => 'GEL',
                'timezone'            => 'Asia/Tbilisi',
                // macrodata_* intentionally left blank — owner sets them manually.
                'macrodata_host'      => '',
                'macrodata_port'      => 3306,
                'macrodata_database'  => 'macro_bi_cmp_607',
                'macrodata_username'  => 'macro_bi_cmp_607',
                'macrodata_password'  => '',
            ]
        );

        // ── company_macrodata_mappings upsert ─────────────────────────────────
        // finance_type_design_id: types_id for "Design" payment type in Apart Group's MacroData.
        CompanyMacrodataMapping::updateOrCreate(
            [
                'company_id'   => $company->id,
                'semantic_key' => 'finance_type_design_id',
            ],
            [
                'value' => 4280,
                'notes' => 'Apart Group: types_id for design-payment finances (probed from prod 2026-05-28)',
            ]
        );

        // ── Admin user (idempotent) ────────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['email' => 'admin@apartgroup.ge'],
            [
                'name'             => 'Apart Group Admin',
                'password'         => 'AG2026admin',
                'role'             => 'admin',
                'company_id'       => $company->id,
                'iframe_token'     => 'seeder-apart-group-admin-fixed-token',
                'company_accesses' => [['company_id' => $company->id, 'role' => 'admin']],
            ]
        );

        // ── Report: Sales Registry («Реестр Продаж») ──────────────────────────
        //
        // NOTES ON DESIGN:
        // • primary_model = EstateDeals  (1 deal = 1 row)
        //
        // • Living area (col 6): type=custom_attribute, attr_source=estate_sells_attr,
        //   attr_name=estate_area_living. Correlated subquery via estate_deals.estate_sell_id.
        //   estate_area_inside is NULL for Apart Group — living area lives in EAV only.
        //
        // • Balcony area (col 7): type=custom_attribute, attr_source=estate_sells_attr,
        //   attr_name=estate_area_balcony, value_type:number. ~854 objects filled.
        //
        // • Terrace area (col 8): type=custom_attribute, attr_source=estate_sells_attr,
        //   attr_name=estate_area_terrace, value_type:number. ~42 objects filled.
        //   estate_areaBti_terrace direct field is always NULL — never use it.
        //
        // • Total Area (col 9): estateSells.estate_area (direct BelongsTo field, always
        //   filled). Used as-is — authoritative total from the MACRO unit card.
        //
        // • Hidden anchor col (visible=false): estateSells.estate_area — its dot-path
        //   alias `estateSells_estate_area` is used in expressions for Design m² and
        //   Sq.m.$ Without Design. Without this hidden col the alias would not exist in
        //   $row and those expressions would evaluate to 0.
        //
        // • Language (col 27): type=custom_attribute, attr_source=estate_attributes,
        //   attr_id=3, entity=contacts. Correlates on estate_deals.contacts_buy_id.
        //
        // • Expression columns that depend on relation_aggregate aliases
        //   (paid_design, design_value) are placed AFTER those aggregate columns
        //   so the alias is populated in $row before the expression runs.
        //
        // • Number of bedroom (col 24) shares field=estateSells.estate_rooms with Total Rooms
        //   (col 23) — same data, different header (owner's request: bedrooms = rooms).
        //   Frontend distinguishes columns by _key (index|field|label_field); mapRow()
        //   guard ensures the field value is written once and shared between both columns.
        //
        // • where clause removed (deal_date is NULL for many valid sold deals including
        //   EZO records — the report now uses signed_date coalesce for Sales Date).
        //   Sort by deal_date DESC naturally surfaces deals with date first.
        //
        $config = [
            'primary_model' => 'EstateDeals',

            'columns' => [

                // 1 — Project
                [
                    'field'       => 'estateHouses.name',
                    'header'      => ['ru' => 'Проект', 'en' => 'Project'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'description' => ['ru' => 'Название жилого комплекса / дома', 'en' => 'Residential complex / house name'],
                ],

                // 2 — Block (entrance)
                [
                    'field'       => 'estateSells.geo_house_entrance',
                    'header'      => ['ru' => 'Блок', 'en' => 'Block'],
                    'type'        => 'number',
                    'sortable'    => true,
                    'filterable'  => true,
                    'align'       => 'center',
                    'description' => ['ru' => 'Номер подъезда / блока', 'en' => 'Entrance / block number'],
                ],

                // 3 — Floor
                [
                    'field'       => 'estateSells.estate_floor',
                    'header'      => ['ru' => 'Этаж', 'en' => 'Floor'],
                    'type'        => 'number',
                    'sortable'    => true,
                    'filterable'  => true,
                    'align'       => 'center',
                    'description' => ['ru' => 'Этаж объекта', 'en' => 'Unit floor number'],
                ],

                // 4 — Unit number (#)
                [
                    'field'       => 'estateSells.geo_flatnum',
                    'header'      => ['ru' => '№', 'en' => '#'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'align'       => 'center',
                    'description' => ['ru' => 'Номер квартиры / апартаментов', 'en' => 'Flat / apartment number'],
                ],

                // 5 — Type
                [
                    'field'       => 'estateSells.estate_sell_type',
                    'header'      => ['ru' => 'Тип', 'en' => 'Type'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'options'     => [
                        'living'   => ['ru' => 'Жилое',     'en' => 'Residential Area'],
                        'comm'     => ['ru' => 'Коммерция', 'en' => 'Commercial'],
                        'parking'  => ['ru' => 'Паркинг',   'en' => 'Parking'],
                    ],
                    'description' => ['ru' => 'Тип объекта: жилое, коммерческое или паркинг', 'en' => 'Unit type: residential, commercial, or parking'],
                ],

                // 6 — Living area (EAV estate_sells_attr, attr_name=estate_area_living)
                // estate_area_inside is NULL for Apart Group — the correct living area
                // for this client lives in EAV only (confirmed via prod probe 2026-06-02).
                // value_type=number: cast varchar EAV value to float in mapRow() so that
                // arithmetic expressions downstream receive a numeric operand.
                // hide_zero=true: commercial units have no living area — EAV missing →
                // cast to 0.0 sentinel → converted back to null for display (empty cell).
                [
                    'field'       => 'living_area',
                    'header'      => ['ru' => 'Жилая площадь', 'en' => 'Living area'],
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_living',
                    'value_type'  => 'number',
                    'hide_zero'   => true,
                    'sortable'    => false,
                    'filterable'  => false,
                    'format'      => '0.00',
                    'unit'        => ['ru' => 'м²', 'en' => 'm²'],
                    'description' => ['ru' => 'Жилая площадь из EAV (estate_sells_attr.estate_area_living)', 'en' => 'Living area from EAV (estate_sells_attr.estate_area_living)'],
                ],

                // 7 — Balcony area (EAV: estate_sells_attr, attr_name=estate_area_balcony)
                // Correlated subquery keyed on estate_deals.estate_sell_id.
                // hide_zero=true: units without balcony return null EAV → 0.0 sentinel
                // → converted to null for display (~900 out of 1750 filled).
                [
                    'field'       => 'balcony_area',
                    'header'      => ['ru' => 'Балкон', 'en' => 'Balcony'],
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_balcony',
                    'value_type'  => 'number',
                    'hide_zero'   => true,
                    'sortable'    => false,
                    'filterable'  => false,
                    'format'      => '0.00',
                    'unit'        => ['ru' => 'м²', 'en' => 'm²'],
                    'description' => ['ru' => 'Площадь балкона (из EAV estate_sells_attr)', 'en' => 'Balcony area (from EAV estate_sells_attr)'],
                ],

                // 8 — Terrace area (EAV: estate_sells_attr, attr_name=estate_area_terrace)
                // estate_areaBti_terrace direct field is always NULL for Apart Group —
                // real terrace values live in EAV (probe confirmed ~42 filled objects).
                // hide_zero=true: ~1708 units have no terrace → null EAV → 0.0 sentinel
                // → converted to null for display.
                [
                    'field'       => 'terrace_area',
                    'header'      => ['ru' => 'Терраса', 'en' => 'Terrace'],
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_sells_attr',
                    'attr_name'   => 'estate_area_terrace',
                    'value_type'  => 'number',
                    'hide_zero'   => true,
                    'sortable'    => false,
                    'filterable'  => false,
                    'format'      => '0.00',
                    'unit'        => ['ru' => 'м²', 'en' => 'm²'],
                    'description' => ['ru' => 'Площадь террасы (из EAV estate_sells_attr; заполнено у ~42 объектов)', 'en' => 'Terrace area (from EAV estate_sells_attr; filled for ~42 units)'],
                ],

                // 9 — Total Area: estateSells.estate_area (authoritative total from MACRO unit card)
                // This is the official total area stored directly on the estate_sells record.
                // It is always filled and matches what MACRO displays in the CRM.
                // The EAV components (living+balcony+terrace) may not sum to this value due
                // to rounding or missing entries, so we use the direct field as ground truth.
                [
                    'field'       => 'estateSells.estate_area',
                    'header'      => ['ru' => 'Общая площадь', 'en' => 'Total Area'],
                    'type'        => 'number',
                    'sortable'    => true,
                    'filterable'  => true,
                    'format'      => '0.00',
                    'unit'        => ['ru' => 'м²', 'en' => 'm²'],
                    'description' => ['ru' => 'Общая площадь объекта (прямое поле estate_sells.estate_area)', 'en' => 'Total unit area (direct field estate_sells.estate_area)'],
                ],

                // 10 — Status (int field deal_status on EstateDeals)
                //
                // estate_deals_statuses reference table for Apart Group (probed 2026-06-02):
                //   5   → Undefined
                //   10  → Show
                //   15  → Interested in
                //   20  → Options sent
                //   101 → Liked
                //   103 → Did not like
                //   105 → Reserved
                //   110 → Deal in progress
                //   140 → Deal canceled
                //   150 → Done deal
                //
                // Codes actually observed in estate_deals for Apart Group (probed 2026-06-02):
                //   5, 103, 105, 110, 140, 150
                //
                // options MUST use dict format {"<value>": {ru,en}} — NOT array format.
                // The frontend resolves labels via column.options[rawKey] (dict lookup),
                // NOT via Array.find(). Array format causes raw numeric codes to show in UI.
                [
                    'field'       => 'deal_status',
                    'header'      => ['ru' => 'Статус', 'en' => 'Status'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'options'     => [
                        '5'   => ['ru' => 'Неопределён',      'en' => 'Undefined'],
                        '103' => ['ru' => 'Не понравилось',   'en' => 'Did not like'],
                        '105' => ['ru' => 'Бронь',            'en' => 'Reserved'],
                        '110' => ['ru' => 'Сделка в работе',  'en' => 'Deal in progress'],
                        '140' => ['ru' => 'Сделка отменена',  'en' => 'Deal canceled'],
                        '150' => ['ru' => 'Сделка состоялась','en' => 'Done deal'],
                    ],
                    'description' => ['ru' => 'Статус сделки (estate_deals.deal_status)', 'en' => 'Deal status'],
                ],

                // 11 — Owner / Counterparty
                [
                    'field'       => 'contactsBuy.contacts_buy_name',
                    'header'      => ['ru' => 'Покупатель', 'en' => 'Owner'],
                    'type'        => 'text',
                    'truncate'    => 'first_word',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'description' => ['ru' => 'Контрагент (покупатель) по сделке', 'en' => 'Counterparty (buyer) on the deal'],
                ],

                // 12 — Sale Price per m²
                [
                    'field'               => 'estateSells.estate_price_m2',
                    'header'              => ['ru' => 'Цена продажи (м²)', 'en' => 'Sale Price m2'],
                    'type'                => 'currency',
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Цена продажи за 1 м² общей площади', 'en' => 'Sale price per 1 m² of total area'],
                ],

                // 13 — Sale Price (deal price, not deal_sum)
                [
                    'field'               => 'deal_price',
                    'header'              => ['ru' => 'Цена продажи', 'en' => 'Sale Price'],
                    'type'                => 'currency',
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Прайс объекта на момент сделки', 'en' => 'Unit price at time of deal'],
                ],

                // 17 — Paid Design (relation_aggregate SUM, alias=paid_design)
                //      MUST come before col 14 (Paid Total uses paid_design alias)
                //      and before col 18 (Design Sq.m.$ uses paid_design as proxy).
                //      $company_var resolved via company_macrodata_mappings:
                //        finance_type_design_id = 4280 (Apart Group "2. Design" type).
                //      status=1 means "Paid / Проведено".
                [
                    'field'               => 'paid_design',
                    'header'              => ['ru' => 'Оплачено дизайн', 'en' => 'Paid Design'],
                    'type'                => 'relation_aggregate',
                    'aggregate'           => [
                        'function'    => 'sum',
                        'relation'    => 'finances',
                        'value_field' => 'summa',
                        'where'       => [
                            ['column' => 'types_id', 'operator' => '=', 'value' => ['$company_var' => 'finance_type_design_id']],
                            ['column' => 'status',   'operator' => '=', 'value' => 1],
                        ],
                    ],
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Фактически оплаченная сумма дизайна (статус «Проведено»)', 'en' => 'Actually paid design amount (status "Paid")'],
                ],

                // 19 — Design Value (relation_aggregate SUM, alias=design_value)
                //      MUST come before col 18 (Design Sq.m.$ uses design_value alias)
                //      All statuses — full planned design payment.
                [
                    'field'               => 'design_value',
                    'header'              => ['ru' => 'Сумма дизайна', 'en' => 'Design Value'],
                    'type'                => 'relation_aggregate',
                    'aggregate'           => [
                        'function'    => 'sum',
                        'relation'    => 'finances',
                        'value_field' => 'summa',
                        'where'       => [
                            ['column' => 'types_id', 'operator' => '=', 'value' => ['$company_var' => 'finance_type_design_id']],
                        ],
                    ],
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Полная плановая сумма по дизайн-платежу (все статусы)', 'en' => 'Full planned design payment amount (all statuses)'],
                ],

                // 14 — Paid Total = finances_income + paid_design
                //      Depends on: paid_design alias (col 17 above)
                [
                    'field'               => 'paid_total',
                    'header'              => ['ru' => 'Итого оплачено', 'en' => 'Paid Total'],
                    'type'                => 'currency',
                    'expression'          => 'finances_income + paid_design',
                    'currency_in_header'  => true,
                    'sortable'            => false,
                    'filterable'          => false,
                    'description'         => ['ru' => 'Суммарная оплата: поступления по проекту + оплата дизайна', 'en' => 'Total paid: project income + design payment'],
                ],

                // 15 — Sales Date = coalesce(deal_date, signed_date)
                // deal_date is NULL for many EZO deals (incl. gold record Zubchenko);
                // signed_date is the reliable fallback for Apart Group.
                [
                    'field'       => 'sales_date',
                    'header'      => ['ru' => 'Дата продажи', 'en' => 'Sales Date'],
                    'type'        => 'date',
                    'expression'  => 'coalesce(deal_date, signed_date)',
                    'sortable'    => false,
                    'filterable'  => false,
                    'description' => ['ru' => 'Дата продажи: дата договора или дата подписания (что заполнено первым)', 'en' => 'Sale date: deal date or signed date (whichever is set first)'],
                ],

                // 16 — Manager
                [
                    'field'       => 'usersManager.users_name',
                    'header'      => ['ru' => 'Менеджер', 'en' => 'Manager'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'description' => ['ru' => 'Ответственный менеджер по сделке', 'en' => 'Responsible manager on the deal'],
                ],

                // HIDDEN anchor — estateSells.estate_area (visible=false)
                // Purpose: inject the dot-path alias `estateSells_estate_area` into $row
                // so that expression columns (Design Sq.m.$ and Sq.m.$ Without Design)
                // can divide by estate_area without getting 0.
                // This col is never shown in the UI; it only serves as an expression anchor.
                [
                    'field'   => 'estateSells.estate_area',
                    'header'  => ['ru' => '', 'en' => ''],
                    'type'    => 'number',
                    'visible' => false,
                ],

                // 18 — Design Sq.m.$ = design_value / estate_area
                //      Depends on: design_value alias (col 19 above)
                //      Divides by estateSells_estate_area (hidden anchor above).
                [
                    'field'               => 'design_price_m2',
                    'header'              => ['ru' => 'Дизайн (м²)', 'en' => 'Design Sq.m.$'],
                    'type'                => 'currency',
                    'expression'          => 'estateSells_estate_area > 0 ? design_value / estateSells_estate_area : 0',
                    'currency_in_header'  => true,
                    'sortable'            => false,
                    'filterable'          => false,
                    'description'         => ['ru' => 'Стоимость дизайна за 1 м² (сумма дизайна / общая площадь)', 'en' => 'Design cost per 1 m² (design value / total area)'],
                ],

                // 20 — Paid Project (finances_income — direct field on EstateDeals)
                [
                    'field'               => 'finances_income',
                    'header'              => ['ru' => 'Оплачено по проекту', 'en' => 'Paid Project'],
                    'type'                => 'currency',
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Сумма фактически проведённых платежей по договору (статус «Проведено»)', 'en' => 'Total completed payments on the contract (status "Paid")'],
                ],

                // 21 — Sq.m.$ Without Design = deal_sum / estate_area
                //      Divides by estateSells_estate_area (hidden anchor above).
                [
                    'field'               => 'price_m2_no_design',
                    'header'              => ['ru' => 'Цена м² без дизайна', 'en' => 'Sq.m.$ Without Design'],
                    'type'                => 'currency',
                    'expression'          => 'estateSells_estate_area > 0 ? deal_sum / estateSells_estate_area : 0',
                    'currency_in_header'  => true,
                    'sortable'            => false,
                    'filterable'          => false,
                    'description'         => ['ru' => 'Сумма договора без дизайна, делённая на общую площадь', 'en' => 'Contract amount without design divided by total area'],
                ],

                // 22 — Value Without Design (deal_sum)
                [
                    'field'               => 'deal_sum',
                    'header'              => ['ru' => 'Сумма без дизайна', 'en' => 'Value Without Design'],
                    'type'                => 'currency',
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Сумма договора (без учёта дизайн-платежа)', 'en' => 'Contract amount (excluding design payment)'],
                ],

                // 23 — Total Rooms
                [
                    'field'       => 'estateSells.estate_rooms',
                    'header'      => ['ru' => 'Комнат', 'en' => 'Total Rooms'],
                    'type'        => 'number',
                    'sortable'    => true,
                    'filterable'  => true,
                    'align'       => 'center',
                    'description' => ['ru' => 'Количество комнат в объекте', 'en' => 'Number of rooms in the unit'],
                ],

                // 24 — Number of bedrooms
                //      Owner: bedrooms = rooms for Apart Group (same field, different header).
                //      Uses the same dot-path field as Total Rooms (col 23).
                //      mapRow() guard ensures the value is written only once — both columns
                //      read from the same $row key, which is correct (identical value).
                //      Frontend differentiates the columns by _key (index|field|label_field).
                [
                    'field'       => 'estateSells.estate_rooms',
                    'header'      => ['ru' => 'Спальни', 'en' => 'Number of bedroom'],
                    'type'        => 'number',
                    'sortable'    => false,
                    'filterable'  => false,
                    'align'       => 'center',
                    'description' => ['ru' => 'Количество спальных комнат (равно общему числу комнат для данного клиента)', 'en' => 'Number of bedrooms (equals total rooms for this client)'],
                ],

                // 25 — Sales Source
                [
                    'field'       => 'channel_name',
                    'header'      => ['ru' => 'Источник продажи', 'en' => 'Sales Source'],
                    'type'        => 'text',
                    'sortable'    => true,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'description' => ['ru' => 'Рекламный канал / источник сделки', 'en' => 'Advertising channel / deal source'],
                ],

                // 27 — Language / Язык
                // EAV: estate_attributes, attr_id=3 (Apart Group custom attribute).
                // Previously labelled "Nationality" — attr_id=3 is actually "Language"
                // (confirmed by diagnostic 2026-05-28). Renamed to reflect real semantics.
                // entity='contacts' → correlates on estate_deals.contacts_buy_id.
                [
                    'field'       => 'language',
                    'header'      => ['ru' => 'Язык', 'en' => 'Nationality'],
                    'type'        => 'custom_attribute',
                    'attr_source' => 'estate_attributes',
                    'attr_id'     => 3,
                    'entity'      => 'contacts',
                    'value_type'  => 'string',
                    'sortable'    => false,
                    'filterable'  => true,
                    'filter_type' => 'async_select',
                    'description' => [
                        'ru' => 'Язык/гражданство контакта (custom-атрибут MACRO, attr_id=3; данные заполнены клиентом вручную, возможны неточности)',
                        'en' => 'Contact language/nationality (MACRO custom attribute, attr_id=3; data entered manually by client, may be imprecise)',
                    ],
                ],

                // 26 — Broker Paid (commission)
                [
                    'field'               => 'deal_mediator_comission',
                    'header'              => ['ru' => 'Брокер оплачено', 'en' => 'Broker Paid'],
                    'type'                => 'currency',
                    'currency_in_header'  => true,
                    'sortable'            => true,
                    'filterable'          => true,
                    'filter_type'         => 'number_range',
                    'description'         => ['ru' => 'Комиссия брокера / посредника по сделке', 'en' => 'Broker / mediator commission on the deal'],
                ],

            ],

            // No global where filter: deal_date is NULL for many valid sold deals in
            // Apart Group (EZO project uses signed_date instead). Removing whereNotNull
            // ensures all 320 sold deals appear. Sort by deal_date DESC brings dated
            // records first; deals without deal_date appear at the end.
            'where' => [],

            'totals' => [
                'deal_price',
                'paid_design',
                'design_value',
                'paid_total',
                'finances_income',
                'deal_sum',
                'deal_mediator_comission',
            ],

            'sort' => [
                'default' => ['field' => 'deal_date', 'direction' => 'desc'],
            ],

            'pagination' => [
                'default' => 50,
                'options' => [25, 50, 100, 200],
            ],

            'primary_filter' => 'sales_date',
        ];

        // Canonical titles match the existing client report (/reports/13 on prod):
        // lowercase «п» in «Реестр продаж» and "Sales Register". The seeder must
        // not rename the report the client already knows.
        $canonicalRu = 'Реестр продаж';
        $title       = ['ru' => $canonicalRu, 'en' => 'Sales Register'];
        $description = [
            'ru' => 'Реестр сделок Apart Group: объекты, площади, суммы, дизайн-платежи, менеджеры',
            'en' => 'Apart Group deals registry: units, areas, amounts, design payments, managers',
        ];

        // This is a COMPANY-SCOPED user report, NOT a system report.
        //
        // System reports (is_system=true) are GLOBAL templates: ReportController
        // ::index shows them to every company/role unconditionally (the first
        // OR-branch is `is_system = true` with no company check), and
        // AssertsConfigEntityReadAccess::guardReadable lets any user of any
        // company open a system report regardless of its company_id. Seeding
        // this report as is_system=true would therefore leak Apart Group's sales
        // registry to every other client company.
        //
        // To scope it strictly to Apart Group: is_system=false, company_id set,
        // is_published=true (so all Apart Group roles incl. viewer/analyst see
        // it — published company reports are visible to the whole company).
        // user_id is set to the Apart Group admin so author/analyst-owner ACL
        // and the `author` projection behave correctly.
        //
        // Upsert match is by (company_id, LOWER(title->>'ru')) so re-running is
        // idempotent and never produces a duplicate. The match is:
        //   • case-insensitive on title->ru — on prod the existing client report
        //     (#13) is «Реестр продаж» (lowercase «п»), while older seeds used
        //     «Реестр Продаж» (uppercase «П»). LOWER() reconciles both.
        //   • deterministic — orderBy('id') asc + first() takes the EARLIEST
        //     matching row, so on prod we always lock onto #13 (13 < the leaking
        //     #15 system dupe) and update that exact record rather than a later
        //     duplicate. We also self-heal any matched SYSTEM record by demoting
        //     it to a company-scoped user report.
        //
        // The jsonb extraction uses PostgreSQL's `title->>'ru'` via whereRaw.
        // This seeder only runs under db:seed on pgsql (never inside the sqlite
        // :memory: test suite), so the PG-specific operator is safe here.
        //
        // NOTE: we assign every field directly on the model and call save()
        // instead of update($attributes). A mass-assignment update() only
        // writes attributes Eloquent considers "dirty", and the dirty diff is
        // taken against whatever the freshly-loaded row already holds. When a
        // stale row matched on title->ru + company_id but already carried some
        // of the target scalar values, update() would silently drop the
        // remaining flags (is_system among them) from the SET clause — the
        // self-heal demotion never reached the DB. Direct property assignment
        // forces each field into the dirty set unconditionally, so save()
        // always persists the full intended state. This is also the legal way
        // to write guarded fields without weakening the model's guard.
        $report = Report::where('company_id', $company->id)
            ->whereRaw("LOWER(title->>'ru') = LOWER(?)", [$canonicalRu])
            ->orderBy('id')
            ->first() ?? new Report();

        $report->title        = $title;
        $report->description  = $description;
        $report->config       = $config;
        $report->company_id   = $company->id;
        $report->user_id      = $admin->id;
        $report->is_system    = false;
        $report->is_published = true;
        $report->save();
    }
}
