<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Per-model short semantic notes for the **in-report quick_qa** mode.
 *
 * When the user opens a chat from a Report page (MiniChat), the system prompt
 * for quick_qa drops the full QUICK_QA_PROMPT.md catalog (~10 KB of 64 models)
 * and instead injects ONLY the short note for the report's `primary_model`.
 * The LLM doesn't need to know about EstateMortgage or InventoryNomsTop when
 * the user is looking at an EstateDeals report — it needs the *gotchas* for
 * the model that's already on screen.
 *
 * Source of truth lives here (not in QUICK_QA_PROMPT.md / REPORTS_GUIDE.md) so:
 *  - tests can pin behaviour without I/O,
 *  - we get a single point of edit when MacroData semantics shift,
 *  - the file ships in the docker image (no extra mount).
 *
 * MAINTAINER: update this map when MacroData semantics change. Keep each note
 * under ~600 chars — the budget for in-report quick_qa system prompts is tight
 * (we cut full catalog to leave room for report config + filters context).
 *
 * For models without a curated note, getNote() returns a generic fallback that
 * tells the LLM to use probe_data — safe degradation, no breakage.
 */
class ModelSemanticNotes
{
    /**
     * Keys are PascalCase model names matching `report.config.primary_model`
     * (and the MacroData Eloquent class basenames). Values are short prose
     * notes — semantic gotchas, status enums, common pitfalls.
     *
     * @var array<string, string>
     */
    private const NOTES = [
        'EstateDeals' => <<<'NOTE'
EstateDeals = сделки (договоры). 1 строка = 1 договор. Ключевые поля:
- deal_sum (сумма сделки), deal_date (дата заключения), deal_status (статус сделки)
- user_id (менеджер), complex_id (ЖК), house_id (дом), estate_sell_id (объект)
Статусы (deal_status — см. EstateDealsStatuses для имён): черновик / активная /
закрытая / расторгнутая. По умолчанию для "активных сделок" — отфильтровывай
расторгнутые / черновики. Расторгнутые сделки НЕ учитывай в выручке.
NOTE,

        'EstateSells' => <<<'NOTE'
EstateSells = объекты недвижимости (квартиры / паркинг / коммерция). 1 строка
= 1 объект. Поля: estate_price (цена), estate_area (площадь), geo_flatnum
(номер), status (статус — см. EstateStatuses), house_id (дом).
Статусы (status): свободна / бронь / продана / снята. "Остаток" / "непроданные"
= status = свободна. "В продаже" обычно = свободна + бронь.
NOTE,

        'EstateBuys' => <<<'NOTE'
EstateBuys = заявки клиентов (лиды). 1 строка = 1 заявка. Поля: status,
user_id (менеджер), source (источник), created_at (дата заявки).
Воронка: заявка (EstateBuys) → встреча (EstateMeetings) → сделка (EstateDeals).
Конверсию считай по unique contact / отдельным агрегатом.
NOTE,

        'Finances' => <<<'NOTE'
Finances = финансовые операции. Поля: sum, pay_date, status, types_id, deal_id.

КРИТИЧНО — фильтры по статусу и типу:
- status = 1 → действующая операция (default для большинства вопросов)
- status = 3 → отменённая (исключай если не сказано иначе)
- status = 50 → архивная
- types_id = 3786 → ПРИХОД (фактическая выручка / поступления)
- types_id = 3787 → НАЧИСЛЕНИЕ / план (НЕ дебиторка целиком!)
- types_id = 3788 → расход

Дебиторка ≠ types_id=3787. Дебиторка = sum(types_id=3787 AND pay_date<=today)
МИНУС sum(types_id=3786 AND pay_date<=today). Если вопрос про дебиторку без
явной формулы — объясни логику или уточни.
NOTE,

        'EstateHouses' => <<<'NOTE'
EstateHouses = дома / секции ЖК. Поля: name, complex_id. Для срезов по
секциям / корпусам. complex_id — связь с Projects.
NOTE,

        'Projects' => <<<'NOTE'
Projects = жилые комплексы / проекты застройщика. Поле: name. Срезы "по ЖК"
обычно требуют JOIN'а к Projects через complex_id из EstateDeals / EstateSells.
NOTE,

        'Tasks' => <<<'NOTE'
Tasks = задачи менеджеров (встречи / звонки / демонстрации). Поля: user_id
(исполнитель), type (тип задачи), status, start_date (дата начала).
Для отчётов "активность менеджеров" фильтруй по type. Конкретные значения
type см. через probe_data — словарь зависит от настроек CRM клиента.
NOTE,

        'Calls' => <<<'NOTE'
Calls = звонки. Поля: user_id, direction (входящий/исходящий), duration
(длительность сек), created_at. Для агрегатов по звонкам обычно нужен count
по user_id или sum(duration).
NOTE,

        'EstateMeetings' => <<<'NOTE'
EstateMeetings = встречи и показы объектов. Один из этапов воронки между
заявкой и сделкой. Связь с EstateBuys (заявка) и EstateSells (показываемый
объект).
NOTE,

        'Contacts' => <<<'NOTE'
Contacts = контрагенты (покупатели / клиенты). Поля: contacts_buy_name,
contacts_sell_name. PII (email, phone, passport, iin) ЗАПРЕЩЕНЫ в quick_qa —
вернётся ошибка query_data. Скажи юзеру что PII доступны только в режиме
отчётов с правами доступа.
NOTE,

        'Users' => <<<'NOTE'
Users = менеджеры / сотрудники CRM. Поля: name, email, department_id. Имена
нужны для расшифровки user_id в других моделях. PII (email, phone, password)
ЗАПРЕЩЕНЫ в quick_qa — только name / department_id доступны.
NOTE,

        'CompanyDepartments' => <<<'NOTE'
CompanyDepartments = отделы компании. Для срезов "по отделам". Связь:
users.department_id → company_departments.id.
NOTE,

        'EstateStatuses' => <<<'NOTE'
EstateStatuses = справочник статусов объектов (для EstateSells.status).
Лукап-таблица: id + name. Нужна для расшифровки числовых статусов в человекочитаемые.
NOTE,

        'EstateDealsStatuses' => <<<'NOTE'
EstateDealsStatuses = справочник статусов сделок (для EstateDeals.deal_status).
Лукап-таблица: id + name. Нужна для расшифровки числовых статусов.
NOTE,

        'FinancesTypes' => <<<'NOTE'
FinancesTypes = справочник типов финансовых операций. Расшифровка
finances.types_id. Известные ID: 3786=приход, 3787=начисление/план, 3788=расход.
NOTE,
    ];

    /**
     * Get the semantic note for a model name. PascalCase expected (e.g.
     * "EstateDeals"). Returns a safe generic fallback when no curated note
     * exists — better than null because the LLM still needs *some* anchor.
     */
    public function getNote(string $modelName): string
    {
        return self::NOTES[$modelName]
            ?? "Модель {$modelName} — подробной семантической справки нет. Используй probe_data чтобы изучить структуру (sample, count, поля).";
    }

    /**
     * Check whether a curated note exists. Useful for tests / introspection;
     * also lets future callers decide to skip the in-report short path if the
     * model is exotic enough that the full catalog would be safer.
     */
    public function hasNote(string $modelName): bool
    {
        return array_key_exists($modelName, self::NOTES);
    }

    /**
     * Return all known model names with curated notes. For test asserts and
     * possible admin tooling ("which models have curated semantic notes?").
     *
     * @return array<int, string>
     */
    public function knownModels(): array
    {
        return array_keys(self::NOTES);
    }
}
