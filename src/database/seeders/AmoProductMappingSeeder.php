<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Models\Product;
use App\Domain\Migration\Models\AmoProductMapping;
use Illuminate\Database\Seeder;

/**
 * SAMPLE seeder (skipped by the "Сброс настроек" clean reset). Pre-loads every
 * AMO "Продукт/Product" (multiselect, field 590196) enum option into the
 * amo_product_mappings curation table with curated action/catalog_product_id.
 *
 * Mapping rules applied (2026-06-22 curation pass):
 *   map   — clear match to a MACRO Global 2026 catalog product
 *   skip  — no direct analog; deprecated, discontinued, customization services,
 *            legacy modules subsumed by the main product, or 3rd-party connectors
 *
 * UNCERTAIN entries are mapped but carry a notes flag for human confirmation.
 *
 * Idempotent: re-running converges on the same 94 rows.
 * This seeder now DOES assert action/catalog_product_id/notes on each run so that
 * the curated state is reproducible across dev resets. Human overrides will be
 * re-applied by running this seeder; keep that in mind.
 */
class AmoProductMappingSeeder extends Seeder
{
    /**
     * All 94 AMO "Продукт/Product" enum options with curated mapping.
     *
     * Format: [amo_enum_id, amo_value, action, catalog_code|null, notes|null]
     *
     * @var list<array{0: int, 1: string, 2: string, 3: string|null, 4: string|null}>
     */
    private const PRODUCT_OPTIONS = [
        // ── 1.x MacroCRM (база + опции) ──────────────────────────────────────
        // Main product maps to macro_sales_crm; sub-options (1.1–1.6) are legacy
        // module flags — no separate 2026 catalog entries, skip.
        [1125732, '1.  MacroCRM (база)',                             'map',  'macro_sales_crm', null],
        [1125836, '1.5. Распознавание паспортов, опция',             'skip', null,              null],
        [1132176, '1.3. ДЦО, опция',                                 'skip', null,              null],
        [1132264, '1.4. Эскроу-счета: открытие',                     'skip', null,              null],
        [1137318, '1.2. Передача квартир, опция',                    'skip', null,              null],
        [1188144, '1.1. API для маркетинговых интеграций, опция',    'skip', null,              null],
        [1193978, '1.6. DataBI API, опция',                          'skip', null,              null],
        [1197478, '1.4.1. Эскроу-счета: платежи',                   'skip', null,              null],

        // ── 2.x MacroERP ──────────────────────────────────────────────────────
        [1125734, '2. MacroERP (база)',                               'map',  'macro_erp',       null],
        [1137940, '2.1. Финансы, модуль',                            'map',  'macro_erp',       'Sub-module of MacroERP; mapped to parent product'],
        [1188148, '2.2. ТМЦ, модуль',                               'map',  'macro_erp',       'Sub-module of MacroERP; mapped to parent product'],

        // ── 3.x MacroBank ─────────────────────────────────────────────────────
        // MacroBank is financial accounting SaaS; closest 2026 product is FlowFix.
        // UNCERTAIN — confirm with product owner before ETL.
        [1137106, '3. MacroBank (база)',                              'map',  'flowfix',         'UNCERTAIN: MacroBank→FlowFix (финучёт), подтвердить у юзера'],
        [1193976, '3.1. MacroBank.Строительный учет (база)',          'map',  'flowfix',         'UNCERTAIN: MacroBank module→FlowFix, подтвердить у юзера'],
        [1194530, '3.2. MacroBank.Банковский учет',                   'map',  'flowfix',         'UNCERTAIN: MacroBank module→FlowFix, подтвердить у юзера'],
        [1194532, '3.3. MacroBank.Финансовый учет расширенный',       'map',  'flowfix',         'UNCERTAIN: MacroBank module→FlowFix, подтвердить у юзера'],
        [1194534, '3.4. MacroBank.Строительный учет расширенный',     'map',  'flowfix',         'UNCERTAIN: MacroBank module→FlowFix, подтвердить у юзера'],
        [1194536, '3.5. Доработка отчетных форм',                    'skip', null,              'MacroBank custom dev — no 2026 catalog entry'],
        [1194538, '3.6. Интеграция с MacroERP входящая',              'skip', null,              'MacroBank integration work — no 2026 catalog entry'],
        [1194540, '3.7. Интеграция с 1С исходящая',                  'skip', null,              'MacroBank integration work — no 2026 catalog entry'],
        [1194542, '3.8. Финансист на аутсорсе',                      'skip', null,              'Service/outsourcing — no 2026 catalog entry'],
        [1194544, '3.9. Интеграция со СберАСТ',                      'skip', null,              'MacroBank integration work — no 2026 catalog entry'],
        [1194546, '3.10. Интеграция с СББОЛ',                        'skip', null,              'MacroBank integration work — no 2026 catalog entry'],

        // ── 4.x Catalog/Web/Portal modules ───────────────────────────────────
        [1125736, '4.1. MacroCatalog, модуль',                       'map',  'macro_catalog',   null],
        [1125756, '4.2. Кабинет клиента, модуль',                    'map',  'customer_portal', null],
        [1125754, '4.3. Кабинет агента, модуль',                     'map',  'macro_broker',    null],
        [1197702, '4.4. Сайт ЖК',                                    'map',  'macro_web',       null],
        [1125738, '4.5. MacroTender',                                 'skip', null,              'No 2026 catalog entry for MacroTender'],
        [1125744, '4.6. MacroPlan',                                   'skip', null,              'No 2026 catalog entry for MacroPlan'],

        // ── 5.x MacroSales ────────────────────────────────────────────────────
        [1198158, '5. MacroSales (basic)',                            'map',  'macro_sales_crm', null],

        // ── 6.x MacroWeb ─────────────────────────────────────────────────────
        [1198156, '6. MacroWeb (basic)',                              'map',  'macro_web',       null],

        // ── 7.x Инфраструктурные/AI-сервисы ─────────────────────────────────
        // 7.1–7.4 are infrastructure/ops features; no direct 2026 catalog product.
        [1188946, '7.1. Группа компаний',                            'skip', null,              'Infrastructure feature — no 2026 catalog entry'],
        [1193980, '7.2. Новый филиал',                               'skip', null,              'Infrastructure feature — no 2026 catalog entry'],
        [1188266, '7.3. Backup данных',                              'skip', null,              'Infrastructure feature — no 2026 catalog entry'],
        [1192896, '7.4. MacroData',                                  'skip', null,              'Legacy data product — no direct 2026 catalog entry'],
        // 7.5 — speech-to-text → Voice AI Broker (UNCERTAIN: was a utility, not a broker)
        [1193558, '7.5. Расшифровка звонков в текст',                'map',  'voice_ai_broker', 'UNCERTAIN: speech-to-text feature→voice_ai_broker, подтвердить у юзера'],
        // 7.6 — document recognition → Data Analytics AI (UNCERTAIN)
        [1198070, '7.6. Распознавание документов 2.0',               'map',  'data_analytics_ai', 'UNCERTAIN: doc recognition→data_analytics_ai, подтвердить у юзера'],

        // ── 8.x Партнёрские интеграции ───────────────────────────────────────
        // Only 8.26 TouchLink maps; all others are 3rd-party connector SKUs with
        // no 2026 catalog analog (TouchLink is the ESB, not the connectors).
        [1188218, '8.2. Сделка.рф',                                  'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1188142, '8.1. Объектив',                                    'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1190032, '8.3. НмаркетПРО',                                 'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1192030, '8.4. СКБ Техно',                                  'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1193982, '8.5. Продукты СБЕР',                              'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1193984, '8.6. Поддержка ВАТС',                             'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1194452, '8.7. WebJack',                                    'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1197910, '8.8. Asterisk АТС',                               'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1198006, '8.9. Интеграция Soliq',                           'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1194592, '8.10. Flatseller',                                'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1200678, '8.11. Wazzup',                                    'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1200680, '8.12. Pact.im',                                   'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1200682, '8.13. HartEstate / GetFloorPlan',                 'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1200684, '8.14. StillDoc',                                  'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1202922, '8.15 CallTouch',                                  'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1202924, '8.16 Smartis',                                    'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1202926, '8.17 ROIStat',                                    'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1202928, '8.18 Phonix',                                     'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203432, '8.19 UIS',                                        'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203434, '8.20 Polis.Online',                               'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203436, '8.21 ДВИЖ',                                       'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203438, '8.22 PlanRadar',                                  'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203440, '8.23 Техзор',                                     'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203442, '8.24 Диадок',                                     'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203444, '8.25 СБИС',                                       'skip', null,              '3rd-party connector — no 2026 catalog entry'],
        [1203740, '8.26 TouchLink',                                  'map',  'touchlink',       null],

        // ── 9.x Поддержка / учётные записи ──────────────────────────────────
        [1125750, '9.1. Учетные записи (ОС)',                         'skip', null,              'Support/licensing SKU — no 2026 catalog entry'],
        [1125752, '9.2. ТП: Стандартная техподдержка',               'skip', null,              'Support SKU — no 2026 catalog entry'],
        [1188140, '9.3. ТП: Премиальное сопровождение',              'skip', null,              'Support SKU — no 2026 catalog entry'],

        // ── 10.x Поднятие % ──────────────────────────────────────────────────
        [1188158, '10.1. Поднятие на 10%',                           'skip', null,              'Pricing modifier — no 2026 catalog entry'],
        [1199176, '10.2. Поднятие на 15%',                           'skip', null,              'Pricing modifier — no 2026 catalog entry'],

        // ── 11.x ППИ ─────────────────────────────────────────────────────────
        [1189000, '11. ППИ (аудит) / обучение',                      'map',  'ppi',             null],
        [1200502, '11.1 ППИ (аудит) / обучение MacroCRM',            'map',  'ppi',             'PPI sub-type → ppi product'],
        [1200504, '11.2 ППИ (аудит) / обучение MacroERP',            'map',  'ppi',             'PPI sub-type → ppi product'],
        [1200506, '11.3 ППИ (аудит) / обучение MacroBank',           'map',  'ppi',             'PPI sub-type → ppi product'],

        // ── 12. Продление ────────────────────────────────────────────────────
        [1189140, '12. Продление подписки',                          'skip', null,              'Renewal line item — no 2026 catalog entry'],

        // ── 13.x Доработки / доп. услуги ─────────────────────────────────────
        [1189142, '13.1. Обмен данными с 1С',                        'skip', null,              'Custom development SKU — no 2026 catalog entry'],
        [1189168, '13.2. Доработки CRM',                             'skip', null,              'Custom development SKU — no 2026 catalog entry'],
        [1189170, '13.3. Доработки ERP',                             'skip', null,              'Custom development SKU — no 2026 catalog entry'],
        [1189802, '13.4. Доработки Bank',                            'skip', null,              'Custom development SKU — no 2026 catalog entry'],
        [1189320, '13.5. Доп.услуги в рамках ТП',                   'skip', null,              'Ad-hoc services — no 2026 catalog entry'],
        [1193986, '13.6. Дополнительный чат ТП',                     'skip', null,              'Support add-on — no 2026 catalog entry'],
        [1193988, '13.7. Дополнительное обучение сотрудников',        'skip', null,              'Training SKU — no 2026 catalog entry'],

        // ── 14.x Scrumo ──────────────────────────────────────────────────────
        [1198196, '14. Scrumo',                                      'skip', null,              'External product, not in 2026 catalog'],
        [1198198, '14.1. Scrumo база знаний',                        'skip', null,              'External product, not in 2026 catalog'],

        // ── 15.x Broker/Portal ───────────────────────────────────────────────
        [1198200, '15.1. MacroBroker',                               'map',  'macro_broker',    null],
        [1198202, '15.2. MacroAgent',                                'map',  'macro_broker',    'MacroAgent is the agent-facing side of MacroBroker'],
        [1203368, '15.3 ClientPortal',                               'map',  'customer_portal', null],

        // ── Снятые с продажи ─────────────────────────────────────────────────
        [1125740, 'СНЯТ С ПРОДАЖИ ПРОЕКТНОЕ ФИНАНСИРОВАНИЕ',         'skip', null,              'Discontinued'],
        [1125742, 'СНЯТ С ПРОДАЖИ MacroPRO',                         'skip', null,              'Discontinued'],
        [1125746, 'СНЯТ С ПРОДАЖИ MacroDOM',                         'skip', null,              'Discontinued'],
        [1125838, 'СНЯТ С ПРОДАЖИ ПИК Проектное финансирование',     'skip', null,              'Discontinued'],
        [1132722, 'СНЯТ С ПРОДАЖИ Партнерский продукт',              'skip', null,              'Discontinued'],
        [1136000, 'СНЯТ С ПРОДАЖИ Аудит силами MACRO',               'skip', null,              'Discontinued'],
        [1137604, 'СНЯТ С ПРОДАЖИ 8.1. GOOD.BI',                    'skip', null,              'Discontinued'],
        [1188146, 'СНЯТ С ПРОДАЖИ 1.6. DataBI API, опция',           'skip', null,              'Discontinued'],
        [1200644, 'СНЯТ С ПРОДАЖИ Macro 3D',                         'skip', null,              'Discontinued (live product is macro3d_tours, but this SKU is retired)'],

        // ── Не определён ─────────────────────────────────────────────────────
        [1137246, 'не определен',                                    'skip', null,              'Undefined AMO product label'],
    ];

    public function run(): void
    {
        // Build a code→id lookup for all catalog products in one query.
        /** @var array<string, int> $productIdByCode */
        $productIdByCode = Product::query()
            ->select(['id', 'code'])
            ->get()
            ->pluck('id', 'code')
            ->all();

        foreach (self::PRODUCT_OPTIONS as [$enumId, $label, $action, $catalogCode, $notes]) {
            $catalogProductId = null;

            if ($action === 'map' && $catalogCode !== null) {
                $catalogProductId = $productIdByCode[$catalogCode] ?? null;

                // If the product code doesn't exist yet, fall back to skip so the
                // row never ends up with a broken FK. Notes will explain why.
                if ($catalogProductId === null) {
                    $action = 'skip';
                    $notes = "CODE_NOT_FOUND: {$catalogCode} — ".($notes ?? 'product missing from catalog');
                }
            }

            AmoProductMapping::updateOrCreate(
                ['amo_enum_id' => $enumId],
                [
                    'amo_value' => $label,
                    'action' => $action,
                    'catalog_product_id' => $catalogProductId,
                    'notes' => $notes,
                ],
            );
        }
    }
}
