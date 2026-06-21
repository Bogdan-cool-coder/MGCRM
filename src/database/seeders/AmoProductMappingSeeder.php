<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Migration\Models\AmoProductMapping;
use Illuminate\Database\Seeder;

/**
 * SAMPLE seeder (skipped by the "Сброс настроек" clean reset). Pre-loads every
 * AMO "Продукт/Product" (multiselect, field 590196) enum option into the
 * amo_product_mappings curation table so the user can map products to MGCRM
 * catalog entries one-by-one later.
 *
 * Every row lands with action='skip' and catalog_product_id=null — nothing is
 * imported until a human curates it (action='map' + catalog_product_id). The
 * curation values (action / catalog FKs / notes) are NEVER clobbered on re-run:
 * we updateOrCreate by amo_enum_id but only re-assert the canonical AMO label,
 * leaving the human-edited columns intact.
 *
 * Idempotent: re-running converges on the same 94 rows without duplicating and
 * without resetting curation.
 */
class AmoProductMappingSeeder extends Seeder
{
    /**
     * All 94 AMO "Продукт/Product" enum options (id => label), pulled verbatim
     * from the AMO API (CF 590196). Source of truth — do not hand-edit.
     *
     * @var list<array{0: int, 1: string}>
     */
    private const PRODUCT_OPTIONS = [
        [1125732, '1.  MacroCRM (база)'],
        [1125734, '2. MacroERP (база)'],
        [1125736, '4.1. MacroCatalog, модуль'],
        [1125738, '4.5. MacroTender'],
        [1125740, 'СНЯТ С ПРОДАЖИ ПРОЕКТНОЕ ФИНАНСИРОВАНИЕ'],
        [1125742, 'СНЯТ С ПРОДАЖИ MacroPRO'],
        [1125744, '4.6. MacroPlan'],
        [1125746, 'СНЯТ С ПРОДАЖИ MacroDOM'],
        [1125750, '9.1. Учетные записи (ОС)'],
        [1125752, '9.2. ТП: Стандартная техподдержка'],
        [1125754, '4.3. Кабинет агента, модуль'],
        [1125756, '4.2. Кабинет клиента, модуль'],
        [1125836, '1.5. Распознавание паспортов, опция'],
        [1125838, 'СНЯТ С ПРОДАЖИ ПИК Проектное финансирование'],
        [1132176, '1.3. ДЦО, опция'],
        [1132264, '1.4. Эскроу-счета: открытие'],
        [1132722, 'СНЯТ С ПРОДАЖИ Партнерский продукт'],
        [1136000, 'СНЯТ С ПРОДАЖИ Аудит силами MACRO'],
        [1137106, '3. MacroBank (база)'],
        [1137246, 'не определен'],
        [1137318, '1.2. Передача квартир, опция'],
        [1137604, 'СНЯТ С ПРОДАЖИ 8.1. GOOD.BI'],
        [1137940, '2.1. Финансы, модуль'],
        [1188140, '9.3. ТП: Премиальное сопровождение'],
        [1188142, '8.1. Объектив'],
        [1188144, '1.1. API для маркетинговых интеграций, опция'],
        [1188146, 'СНЯТ С ПРОДАЖИ 1.6. DataBI API, опция'],
        [1188148, '2.2. ТМЦ, модуль'],
        [1188158, '10.1. Поднятие на 10%'],
        [1188218, '8.2. Сделка.рф'],
        [1188266, '7.3. Backup данных'],
        [1188946, '7.1. Группа компаний'],
        [1189000, '11. ППИ (аудит) / обучение'],
        [1189140, '12. Продление подписки'],
        [1189142, '13.1. Обмен данными с 1С'],
        [1189168, '13.2. Доработки CRM'],
        [1189170, '13.3. Доработки ERP'],
        [1189320, '13.5. Доп.услуги в рамках ТП'],
        [1189802, '13.4. Доработки Bank'],
        [1190032, '8.3. НмаркетПРО'],
        [1192030, '8.4. СКБ Техно'],
        [1192896, '7.4. MacroData'],
        [1193558, '7.5. Расшифровка звонков в текст'],
        [1193976, '3.1. MacroBank.Строительный учет (база)'],
        [1193978, '1.6. DataBI API, опция'],
        [1193980, '7.2. Новый филиал'],
        [1193982, '8.5. Продукты СБЕР'],
        [1193984, '8.6. Поддержка ВАТС'],
        [1193986, '13.6. Дополнительный чат ТП'],
        [1193988, '13.7. Дополнительное обучение сотрудников'],
        [1194452, '8.7. WebJack'],
        [1194530, '3.2. MacroBank.Банковский учет'],
        [1194532, '3.3. MacroBank.Финансовый учет расширенный'],
        [1194534, '3.4. MacroBank.Строительный учет расширенный'],
        [1194536, '3.5. Доработка отчетных форм'],
        [1194538, '3.6. Интеграция с MacroERP входящая'],
        [1194540, '3.7. Интеграция с 1С исходящая'],
        [1194542, '3.8. Финансист на аутсорсе'],
        [1194544, '3.9. Интеграция со СберАСТ'],
        [1194546, '3.10. Интеграция с СББОЛ'],
        [1194592, '8.10. Flatseller'],
        [1197478, '1.4.1. Эскроу-счета: платежи'],
        [1197702, '4.4. Сайт ЖК'],
        [1197910, '8.8. Asterisk АТС'],
        [1198006, '8.9. Интеграция Soliq'],
        [1198070, '7.6. Распознавание документов 2.0'],
        [1198156, '6. MacroWeb (basic)'],
        [1198158, '5. MacroSales (basic)'],
        [1198196, '14. Scrumo'],
        [1198198, '14.1. Scrumo база знаний'],
        [1198200, '15.1. MacroBroker'],
        [1198202, '15.2. MacroAgent'],
        [1199176, '10.2. Поднятие на 15%'],
        [1200502, '11.1 ППИ (аудит) / обучение MacroCRM'],
        [1200504, '11.2 ППИ (аудит) / обучение MacroERP'],
        [1200506, '11.3 ППИ (аудит) / обучение MacroBank'],
        [1200644, 'СНЯТ С ПРОДАЖИ Macro 3D'],
        [1200678, '8.11. Wazzup'],
        [1200680, '8.12. Pact.im'],
        [1200682, '8.13. HartEstate / GetFloorPlan'],
        [1200684, '8.14. StillDoc'],
        [1202922, '8.15 CallTouch'],
        [1202924, '8.16 Smartis'],
        [1202926, '8.17 ROIStat'],
        [1202928, '8.18 Phonix'],
        [1203368, '15.3 ClientPortal'],
        [1203432, '8.19 UIS'],
        [1203434, '8.20 Polis.Online'],
        [1203436, '8.21 ДВИЖ'],
        [1203438, '8.22 PlanRadar'],
        [1203440, '8.23 Техзор'],
        [1203442, '8.24 Диадок'],
        [1203444, '8.25 СБИС'],
        [1203740, '8.26 TouchLink'],
    ];

    public function run(): void
    {
        foreach (self::PRODUCT_OPTIONS as [$enumId, $label]) {
            // Re-assert only the AMO label; leave human curation (action /
            // catalog_product_id / catalog_plan_id / notes) untouched on re-run.
            // On first insert the model defaults action='skip' and the FKs to null.
            AmoProductMapping::updateOrCreate(
                ['amo_enum_id' => $enumId],
                ['amo_value' => $label],
            );
        }
    }
}
