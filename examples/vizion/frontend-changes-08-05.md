# Frontend changes — журнал

Файл ведётся main-сессией с 2026-05-08 на период отпуска frontend-specialist'а.
Каждое изменение во `front/` фиксируется здесь: задача, файлы, что именно изменено, чем обосновано, статус QA/PM.

## Принципы (на период отпуска)

- Стек закреплён: Vue 3.5 + TypeScript + Pinia 3 + Vue Router 4 + PrimeVue 4.5 + Bootstrap 5 + vue-i18n + Chart.js 4. Никаких новых библиотек без явного аппрува.
- Архитектура слоёв: `application/` (coordinators), `composables/async/`, `shared/`, `services/`, `entities/`, `pages/<Page>/{index.vue, composables/}`. Новые компоненты — в существующие папки, не плодить параллельные структуры.
- i18n: каждая новая строка — в `lang/` (RU + EN), без хардкода в шаблоне.
- Перед любым изменением: `frontend-specialist` (агент) читает существующий код в зоне правки, не угадывает.
- После UI-итерации — обязательный прогон `qa-tester` (happy-path + console + network).
- Без правок типов на бэке — расширять типы только во `front/src/api/types/` либо в `entities/*/types.ts`.

---

## 2026-05-26 — страница отчёта `/reports/:id`: 4 правки слоя рендера таблицы

**Задача:** четыре правки в рендере таблицы отчёта. (1) Sticky-заголовок «ID объекта» прозрачный — строки наслаиваются при скролле. (2) `relation_aggregate` колонки форматируются неправильно — нет учёта `value_type`. (3) Регрессия: пропал fallback «Не указан» у пустого «Номер договора». (4) Строка «Итого» — добавить единицы измерения («75 шт.», «4134.3 м²»).

### ЗАДАЧА 1 — sticky thead прозрачен над CRM-id колонкой

**Root cause:** `.crm-id-cell--link` — это `position: absolute; inset: 0` якорь внутри `.crm-id-cell-td` (`position: relative`, `z-index: auto`). Sticky thead был на `z-index: 1`. Позиционированный якорь (без z-index) в `z-index: auto` родителе красился в том же стэкинг-контексте и перебивал sticky-заголовок при прокрутке → значения ID просвечивали сквозь заголовок.

**Файл:** `front/src/pages/ReportPage/index.vue` (SCSS-блок)
- `.p-datatable-thead > tr > th`: `z-index: 1` → `z-index: 3`; фон `$surface-50` оставлен (опаковый hard-stop, комментарий про возможную полупрозрачность токена).
- `.crm-id-cell-td`: добавлен `z-index: 0` — устанавливает стэкинг-контекст НИЖЕ заголовка, абсолютный якорь внутри теперь содержится под thead. Ранее починенное выравнивание ID / горизонтальный скролл не тронуты (правки только z-index/фон, layout якоря не менялся).

### ЗАДАЧА 2 — `value_type` для relation_aggregate (+ window_aggregate бонусом)

**Файлы:**
- `front/src/api/types/reports.ts` — `ReportColumnDto.value_type?: string` (passthrough-поле; бэк отдаёт весь конфиг колонки через `getVisibleColumns()` дословно — поле уже приходит, backend-правка НЕ нужна).
- `front/src/pages/ReportPage/composables/useReportPresentation.ts`:
  - `resolveColumnType(type, field, valueType?)` — добавлен 3-й параметр.
  - `case 'relation_aggregate'` / `case 'window_aggregate'`: `return resolveColumnType(valueType, field)` — рекурсивно резолвит формат по подсказке (`value_type: 'number'` → числовой с разделителями без валюты; `'currency'` → money). Если `value_type` нет — fallback на эвристику по имени поля.
  - Call-site: `resolveColumnType(col.type, col.field, col.value_type)`.
- **Побочный фикс:** колонка `cumulative_receipts` (`window_aggregate` + `value_type: 'currency'`) в «Ежедневнике поступлений» раньше падала в `detectColumnType('cumulative_receipts')` → 'string' (имя не матчит sum/pay/income); теперь корректно money.

### ЗАДАЧА 3 — fallback «Не указан» у «Номер договора» (config-driven)

**Расследование:** механизм fallback пустого link-label — ТОЛЬКО config-driven через `label_fallback` (есть в DTO, фронт его читает: muted-italic span, класс `link-cell-label--fallback`). История подтверждает: «Номер договора» в «Реестре договоров» НИКОГДА не имел `label_fallback` в сидере (в отличие от одноимённой колонки в другом отчёте на коммите 0698775, где `label_fallback` был). Generic frontend-плейсхолдера для пустых link/text ячеек в истории не было — пустой label всегда рендерил пустой span. Наша недавняя правка CRM-id ячейки этот путь НЕ задела (трогали только template/SCSS CRM-id, `buildLinkRef` цел).

**Решение:** фронт-правка НЕ нужна — механизм рабочий и config-driven. Фикс в сидере (см. блок «Для report-author» ниже).

### ЗАДАЧА 4 — единицы измерения в строке «Итого»

**Подход:** per-column проп `unit` в конфиге колонки, читается фронтом ТОЛЬКО для totals-строки (body-ячейки не затронуты). Деньги остаются с ₸ (символ от money-форматтера, `unit` для них null/не задаётся — двойного суффикса нет).

**Файлы:**
- `front/src/api/types/reports.ts` — `ReportColumnDto.unit?: string | Record<string, string>` (локализуемо).
- `front/src/pages/ReportPage/composables/useReportPresentation.ts`:
  - `PresentationColumn.unit?: string | null` (резолв через `getLocalizedText`, пустое → null).
  - В `tableColumns`: резолв `unit` рядом с `description`.
  - В `formattedTotalsRow`: хелпер `withUnit(formatted, column)` — добавляет ` {unit}` суффикс к непустому форматированному итогу; применён к обеим веткам (count-колонка через `formatCountTotal` и обычный `format`). Итог: «75 шт.», «4134.3 м²».

**Статус:** type-check 0 ошибок, lint 0 ошибок (1 pre-existing warning в `plugins/persist.ts` — не наш файл). QA/PM — pending.

---

## 2026-05-26 — фильтры отчёта "Ежедневник поступлений": ввод в number range + диагностика async_select

**Задача:** два бага в панели фильтров `/reports/:id`. (1) Фильтр «Оплачено» (number range) не принимает ввод — нельзя напечатать в "От"/"До". (2) Фильтр «Контрагент» (async_select) при поиске пишет «нет доступных опций».

### БАГ 1 — «Оплачено» (number range): ввод не работал

**Root cause (НЕ регрессия от удаления `.separator`):** кольцевая синхронизация двух источников значения. `emitChange` строил новый объект `{from,to}` на каждый keystroke → родитель писал его в `localFilters[field]` → значение возвращалось обратно как `modelValue` → deep+immediate watcher переписывал локальные refs `fromValue`/`toValue` на каждый ввод → `InputNumber` сбрасывал внутреннее состояние набора. Плюс `@input` читал значения из `fromValue.value`/`toValue.value`, которые на момент события ещё не обновлены (v-model InputNumber обновляется позже keystroke).

**Файл:** `front/src/components/filters/NumberRangeFilter.vue`
- Watcher на `props.modelValue`: добавлен echo-guard — локальный ref переписывается только если входящее значение реально отличается (`nextFrom !== fromValue.value`), а не при отражении собственного эмита.
- Оба `InputNumber`: `v-model` → `:model-value` + явные хендлеры `@input="onFromInput"` / `@input="onToInput"`, читающие распарсенное число из payload события PrimeVue (`{ value }`), а не из ещё-не-обновлённого ref.
- `emitChange` оставлен как сборщик/эмиттер, добавлены `onFromInput`/`onToInput` + тип `InputNumberInputEvent`.
- Удаление `.separator` (предыдущая правка пользователя) ввод не ломало — дифф чистый, template валиден, v-model на месте; баг был и до неё.

### БАГ 2 — «Контрагент» (async_select): «нет опций» — BACKEND ISSUE

**Фронт корректен, не тронут.** Проверены `AsyncSelectFilter.vue` + `api/reports.ts::fetchFilterOptions`:
- Запрос формируется правильно: `GET /api/reports/{id}/filter-options/estateDeals.contactsBuy.contacts_buy_name?q=<query>&limit=20`.
- Парсинг ответа корректен (`.options`, ожидает `{value,label}[]`), дебаунс 300ms, requestGate, спиннер пока `firstLoadComplete=false`, «нет опций» только после ответа бэка — всё работает как задумано.
- Поле фильтра в `ReportSeeder.php`: `estateDeals.contactsBuy.contacts_buy_name` — трёхуровневый nested-relation путь (самый глубокий среди async_select-фильтров; остальные одноуровневые или прямые). Сильный кандидат: backend `ReportDataService::searchAsyncFilterOptions` не резолвит distinct-опции по двухуровневому nested-relation пути `A.B.field` → возвращает пустой список.
- **Делегировано backend/macrodata-агенту** (см. отчёт ниже). Фронт-фикса не требуется.

### Проверки
- type-check / lint — НЕ запускались в этой сессии (пользователь собирает фиксы и проверит разом; build/docker не трогаем).
- i18n не затрагивался.

---

## 2026-05-08 — кликабельная ссылка на сделку в "Реестре договоров"

**Задача:** во 2-й колонке реестра договоров показать кликабельную ссылку. Label = `agreement_number`, href = `{crm_url}/account/estate/view/{deal_id}/`. `crm_url` — новое per-company поле; рендер ссылки делается на фронте.

**Решения по развилкам:**
- Сборка URL: фронт (берёт `crm_url` из `companiesStore.currentCompany`, шаблонизирует placeholder'ы из `link_template`).
- Позиция колонки: новая 2-я, ЖК сдвигается на 3-ю.

**Запланированные изменения во `front/`:**

| Файл | Что меняем | Статус |
|---|---|---|
| `front/src/entities/company/types.ts` | `Company.crm_url?: string` | — |
| `front/src/entities/company/mappers.ts` | маппинг `crm_url` в payload/from-api | — |
| `front/src/components/Company/CompanyFormModal.vue` | поле URL (input type=url, валидация) | — |
| `front/src/components/Company/CompanySettingsSection.vue` | отображение/редактирование `crm_url` | — |
| `front/src/composables/useFormatter.ts` | `FormatType += 'link'` | — |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | `resolveColumnType` → case `'link'` | — |
| `front/src/pages/ReportPage/index.vue` | `<Column #body>` для `col.type === 'link'`: `<a target="_blank" :href="resolveLink(col, row, currentCompany.crm_url)">{{ row[col.label_field] }}</a>` | — |
| `front/src/pages/ReportPage/composables/` (новый или существующий) | хелпер `resolveLink(col, row, crmUrl)` — подстановка `{crm_url}` + `{<field>}` из row, с экранированием | — |
| `front/src/api/types/reports.ts` | расширить тип колонки: `link_template?: string`, `label_field?: string` | — |

**Ограничения, на которые обратить внимание:**
- `target="_blank"` обязательно с `rel="noopener noreferrer"`.
- Если `crm_url` у компании пустой — НЕ строим `<a>`, рендерим plain-text (label) без href.
- Если в шаблоне неизвестный плейсхолдер — рендерим plain-text + console.warn (защита от падения таблицы).
- Колонка-ссылка не сортируема, не фильтруется. В `useReportPresentation` уже есть пропуск renderer-колонок в filter builder — новый `type='link'` должен попадать в этот же пропуск.

**Связанные backend-изменения:**
- Миграция `add_crm_url_to_companies_table`, `Company::$fillable += 'crm_url'`, `CompanyController` validation.
- `ReportSeeder.php` — новая колонка 2-й позиции (зона `report-author`).

**Прогрес:** реализовано. `type-check` — чисто. `lint` — 2 pre-existing warnings в незатронутых файлах.

### Фикс по итогам QA

**Что было:** для строк, где `label_field` (`agreement_number`) равен `null`, рендерился пустой кликабельный `<a>` — невидимая ссылка в таблице. Условие `v-if="resolveLink(...)"` проверяло только то, что URL удалось собрать (`crm_url` есть, `deal_id` есть), но не то, что label-значение непустое. Плюс `resolveLink()` вызывался дважды: в `v-if` и в `:href`.

**Что сделано:**

- **`useReportPresentation.ts`** — расширена сигнатура: принимает второй аргумент `crmUrl: Ref<string | null | undefined>`. Добавлен импорт `useReportLink`. Добавлен computed `linkRefs: ComputedRef<Record<string, Array<{ href: string | null; label: string }>>>` — для каждой link-колонки массив `{ href, label }` по индексу строки. `href` выставляется в `null` если label пустой (trim) или если `resolveLink` вернул `null`. Экспортируется в return.

- **`index.vue`** — добавлен `const crmUrl = computed(...)` из `currentCompany.crm_url`; `useReportLink` и двойной вызов `resolveLink` убраны; `useReportPresentation` вызывается с `(report, crmUrl)`; деструктурирован `linkRefs`, убран `tableData` (больше не нужен в шаблоне). Добавлен хелпер `getLinkRef(field, rowIndex)` для типобезопасного доступа из шаблона. Шаблонный блок `#body` для `col.type === 'link'` переписан: `<a>` рендерится только если `ref.href && ref.label`, иначе `<span>` (пустая ячейка для null-значений).

- **`useReportLink.ts`** — убран `console.warn('[resolveLink] Unknown or null placeholder: ...')`. Graceful fallback (`return null`) и так работает корректно; debug-вывод в prod не нужен.

**Подход:** мемоизация через `linkRefs` computed в `useReportPresentation` (не локальные const в шаблоне). Каждый вызов `resolveLink` — один раз на (колонка × строка) при изменении `tableData` или `crmUrl`.

**type-check:** чисто. **lint:** 2 pre-existing warnings в незатронутых файлах, новых нет.

**Фактические изменения:**

| Файл | Что сделано |
|---|---|
| `front/src/entities/company/types.ts` | добавлено `crm_url?: string \| null` в `Company` |
| `front/src/api/types/companies.ts` | добавлено `crm_url?: string \| null` в `CompanyDto` и `CreateCompanyRequest` |
| `front/src/components/Company/types.ts` | добавлено `crm_url: string` в `CompanyFormData` |
| `front/src/components/Company/modals/CompanyFormModal.vue` | добавлен `InputText` для `crm_url` между name-полем и MacroData-секцией |
| `front/src/components/Company/modals/ManagementModal/useCompanyManagementModal.ts` | `formData`, `resetFormData`, `openEditModal`, `buildPayload` — добавлен `crm_url` |
| `front/src/pages/CompanyPage/composables/useCompanySettingsActions.ts` | `emptyCompanyForm`, `watch`, `toCompanyUpdatePayload` — добавлен `crm_url` |
| `front/src/components/Company/CompanySettingsSection.vue` | добавлено отображение `crm_url` (всегда видимо, placeholder "—" если null) |
| `front/src/components/Company/locale/ru.json` | добавлены ключи `settingsCrmUrl` и `crmUrlLabel` |
| `front/src/components/Company/locale/en.json` | добавлены ключи `settingsCrmUrl` и `crmUrlLabel` |
| `front/src/composables/useFormatter.ts` | `FormatType` union расширен типом `'link'` |
| `front/src/api/types/reports.ts` | `ReportColumnDto` расширен полями `link_template?: string`, `label_field?: string` |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | `PresentationColumn` + `tableColumns` несут `link_template`/`label_field`; `resolveColumnType` добавлен `case 'link'`; экспортирован `tableData` |
| `front/src/pages/ReportPage/composables/useReportLink.ts` | **новый файл** — хелпер `resolveLink(template, row, crmUrl)` |
| `front/src/pages/ReportPage/index.vue` | импорт `useCompaniesStore` + `useReportLink`; деструктурирован `tableData`; добавлен `#body`-слот для `col.type === 'link'` в `<Column>` |

**Дополнительные находки:**
- `useCompanySettingsActions.ts` (CompanyPage) — второй файл, где инициализируется `CompanyFormData`; обновлён вместе с ManagementModal, иначе type-check упал бы.
- Mapper `entities/company/mappers.ts` не нужно трогать — он делает `{ ...companyDto }`, поле пробрасывается автоматически.

---

## 2026-05-08 — truncate first_word с tooltip

**Задача:** добавить опциональный флаг `truncate: 'first_word'` в конфиг колонки отчёта. При наличии флага ячейка показывает только первое слово значения, на hover — полное оригинальное значение через tooltip.

**Use case:** колонка "Контрагент" в реестре договоров — показывает "Иванов" вместо "Иванов Иван Иванович", tooltip показывает полное ФИО. Флаг будет выставлен в `ReportSeeder` отдельным агентом (`report-author`).

**Контракт:** `ReportColumnDto.truncate?: 'first_word'` — строковый enum, потенциально расширяемый. Применяется только к текстовым/строковым колонкам (не `link`). Если значение null/пустое — ячейка пустая (стандартное поведение DataTable). Если `truncate` не задан — поведение прежнее.

**Механизм tooltip:** PrimeVue Tooltip directive (`primevue/tooltip`), подключённая локально через `const vTooltip = Tooltip` — тот же паттерн, что в `ToolboxToggle.vue`. Глобальная регистрация не требуется. Директива `v-tooltip="String(rowData[col.field])"` передаёт полное оригинальное значение.

**Изменённые файлы:**

| Файл | Что сделано |
|---|---|
| `front/src/api/types/reports.ts` | Добавлено `truncate?: 'first_word'` в `ReportColumnDto` |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | `PresentationColumn` расширен полем `truncate?: 'first_word'`; маппинг колонок пробрасывает `col.truncate` |
| `front/src/pages/ReportPage/index.vue` | Импорт `Tooltip from 'primevue/tooltip'`; локальная директива `const vTooltip = Tooltip`; в `<Column #body>` добавлена ветка `v-else-if="col.truncate === 'first_word'"` — рендерит `<span v-tooltip="...">{{ firstWord }}</span>` |

**Детали реализации:**
- `#body`-слот теперь активируется для `col.type === 'link' || col.truncate === 'first_word'` (одна `<template v-if>` на оба случая).
- Данные берутся из `rowData` (`data`-слот DataTable = строка `formattedTableData`). Для строковых колонок `format()` возвращает значение как есть, поэтому `rowData[col.field]` == оригинальная строка.
- Первое слово: `String(rowData[col.field]).split(/\s+/)[0]` — обрезает по любому пробельному символу.
- Null/empty guard: `v-if="rowData[col.field] != null && String(rowData[col.field]).trim() !== ''"`.

**type-check:** чисто. **lint:** 0 новых предупреждений (2 pre-existing warnings в незатронутых файлах — не регрессия).

---

## 2026-05-08 — group_by master/detail + overdue badge

**Задача:** поддержать два новых контракта в ответе `GET /api/reports/{id}/data`: (1) grouped rows с master/detail раскрытием, (2) badge `_badge_<field>` рядом с ячейкой.

### Контракт group rows

Если в `meta.grouped: true` (или первая строка содержит `group_key`), отчёт отображается в grouped-режиме. Backend возвращает строки двух видов:
- Обычная строка: `{ deal_id, summa, ... }`
- Group row: `{ group_key, group_meta: { fields, aggregates }, children: [...] }`

В config отчёта присутствует `group_by.collapsible` и `group_by.collapsed_by_default`.

### Контракт badge

Строка может содержать `_badge_<field>: { severity, label: { ru, en } }`. Колонка в config может иметь `badge: {}` — признак того, что для этого поля нужно рендерить Badge.

---

### Изменённые / созданные файлы

| Файл | Что сделано |
|---|---|
| `front/src/api/types/reports.ts` | Добавлены: `ReportBadgeDto`, `ReportGroupMetaDto`, `ReportGroupRowDto`, `ReportAnyRowDto`, `ReportGroupByAggregateDto`, `ReportGroupByDto`. `ReportColumnDto.badge?: Record<string, unknown>`. `ReportMetaDto.grouped?: boolean`. `ReportConfigDto.group_by?: ReportGroupByDto`. `ReportDto.rows` расширен до `ReportAnyRowDto[]`. `ReportTableRowDto` расширен — индекс допускает `Record<string, unknown>` для badge-ключей. |
| `front/src/entities/report/types.ts` | Добавлены: `ReportGroupRow`, `ReportAnyRow`, `ReportTotalsRow`. `ReportTableRow` расширен. `Report.rows → ReportAnyRow[]`, `Report.totals → ReportTotalsRow`. `ReportConfig.group_by?: ReportGroupByDto`. |
| `front/src/entities/report/reportItem.ts` | `ReportItem.rows → ReportAnyRow[]`, `ReportItem.totals → ReportTotalsRow`. |
| `front/src/entities/report/index.ts` | Экспортированы `ReportAnyRow`, `ReportGroupRow`, `ReportTotalsRow`. |
| `front/src/mocks/data.ts` | В `buildMockReportResponse` — cast к `ReportTableRowDto[]` (mock data без group rows). |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | Добавлены: `isGrouped`, `groupRows`, `resolveBadge()`, `formatAggregate()`, `formatGroupChildren()`. `PresentationColumn.badge?`. В `tableColumns` маппинг пробрасывает `col.badge`. В `formattedTableData` пропускаются `_badge_`-ключи. |
| `front/src/pages/ReportPage/components/ReportGroupHeader.vue` | **Новый компонент.** Рендерит master row: название группы, агрегаты, счётчик children. |
| `front/src/pages/ReportPage/index.vue` | Импорт `Badge`, `ReportGroupHeader`, `useReportLink`. Два `<DataTable>` в TabPanel[0]: grouped (с `expander`, `#expansion`, вложенным DataTable) и flat (прежнее поведение). `Badge` рендерится в обоих вариантах если `col.badge`. `expandedRows` — local ref. |
| `front/src/pages/ReportPage/locale/ru.json` | Секция `group`: `expand`, `collapse`, `items_count`, `overdue_count`. |
| `front/src/pages/ReportPage/locale/en.json` | Та же секция `group` на EN. |

### Подход к expansion

PrimeVue `<DataTable :expandedRows="..." @row-expand="..." @row-collapse="...">` с `<Column expander>` и `<template #expansion>`. Состояние — локальный `ref<Record<string, boolean>>`. По умолчанию все группы свёрнуты; если `config.group_by.collapsed_by_default === false` — все раскрываются при загрузке.

### Master row

Отдельный компонент `ReportGroupHeader.vue` (рендерится в `#body` колонки), получает `groupMeta`, `childCount`, `formatAggregate` через props.

### Обратная совместимость

Если `isGrouped === false` — рендерится плоский `<DataTable>` с прежним поведением. Badge только если `col.badge` явно задан в config. Реестр договоров не затронут.

**type-check:** чисто. **lint:** 2 pre-existing warnings, новых нет.

---

## 2026-05-08 — fix: восстановлена кнопка/панель фильтров (регрессия group_by)

### Диагностика

**Что было:** пользователь сообщил, что после переделки под две ветки `<DataTable>` (grouped vs flat) на мобильном устройстве исчезла кнопка фильтра. Выполнен анализ всех четырёх предполагаемых сценариев:

1. **Кнопка удалена из шаблона** — проверка `git diff 8e58405..HEAD` показала, что шаблон кнопки (`v-if="hasFilters"` + `toggleFilter`) идентичен старой версии. Не то.
2. **Кнопка есть, но в одной ветке** — в шаблоне кнопка расположена ВНЕ DataTable-веток, в `header-right`. Применяется к обеим веткам. Не то.
3. **`hasFilters` возвращает false** — логика `useReportPageData.ts` не менялась; `filters_available` приходит с бэка для всех отчётов с колонками. Не то.
4. **CSS/layout: кнопка есть, но визуально недоступна** — это оказался РЕАЛЬНЫЙ сценарий.

**Корневая причина.** На мобильном экране (`< 1100px`) `header-right` получает `width: 100%` и `flex-wrap: wrap`. Внутри него три конкурирующих элемента: `header-pagination` (paginator, виден когда `last_page > 1`), `report-tablist` (TabList «Таблица | График»), и кнопка «Фильтры». Для группированных отчётов (receivables, income diary) пагинация активна — у них 50 записей/страницу и данных много. В итоге на узком экране paginator + tablist занимают первую строку, а кнопка «Фильтры» переносится на вторую. Из-за `overflow: hidden` на `.report-card` и фиксированной высоты контейнера вторая строка `header-right` уходит за видимую область. Пользователь не видит кнопку.

**Почему регрессия появилась именно с group_by:** до этого коммита все системные отчёты были плоскими, без пагинации (мало строк). Два новых отчёта (receivables, income diary) — первые с реальной пагинацией `last_page > 1`, что делает paginator видимым на mobile и создаёт трёхэлементный header-right.

### Что сделано

**Файл:** `front/src/pages/ReportPage/index.vue`

**Изменение 1 — шаблон.** Кнопка фильтра обёрнута в `<div class="filter-toggle-wrap">`. Сам `v-if="hasFilters"` перенесён на wrapper-div (поведение не изменилось). Это даёт CSS-точку опоры для адаптивного позиционирования.

**Было:**
```html
<Button
  v-if="hasFilters"
  :icon="filterCollapsed ? 'pi pi-filter' : 'pi pi-filter-fill'"
  :label="filterCollapsed ? t('filters') : t('collapse')"
  severity="secondary"
  @click="toggleFilter"
/>
```

**Стало:**
```html
<div v-if="hasFilters" class="filter-toggle-wrap">
  <Button
    :icon="filterCollapsed ? 'pi pi-filter' : 'pi pi-filter-fill'"
    :label="filterCollapsed ? t('filters') : t('collapse')"
    severity="secondary"
    @click="toggleFilter"
  />
</div>
```

**Изменение 2 — SCSS.** Добавлено два блока:

```scss
// В .header-right (любой экран)
.filter-toggle-wrap {
  flex-shrink: 0;
}

// @media (max-width: 767px) — mobile specific
@media (max-width: 767px) {
  .header-right {
    .filter-toggle-wrap {
      flex-basis: 100%;
      width: 100%;

      :deep(.p-button) {
        width: 100%;
        justify-content: center;
      }
    }
  }
}
```

На мобильном экране `flex-basis: 100%` гарантирует, что `.filter-toggle-wrap` всегда занимает отдельную строку в `header-right`. Кнопка растягивается на всю ширину (`width: 100%`) и центрируется — это стандартный mobile-first паттерн для action-кнопок. Paginator + tablist остаются на предыдущей строке.

**Состояние фильтров не затрагивается** — `filterCollapsed` / `toggleFilter` / `applyFilters` / `resetFilters` используются в обеих DataTable-ветках через общий composable `useReportPage`, состояние одно.

### Файлы изменены

| Файл | Что сделано |
|---|---|
| `front/src/pages/ReportPage/index.vue` | Обёртка `filter-toggle-wrap` вокруг кнопки фильтра; SCSS: `flex-shrink: 0` в `.header-right`, `@media (max-width: 767px)` — `flex-basis: 100%` + `width: 100%` + кнопка full-width centered |

### QA — на что обратить внимание

1. **Mobile viewport (< 768px):** кнопка «Фильтры» должна быть на отдельной строке под paginator и tablist, full-width, иконка + label по центру.
2. **Desktop (> 768px):** кнопка компактная, inline рядом с tablist — без изменений.
3. **Grouped report (receivables / income diary) с пагинацией:** пагинатор видим → кнопка фильтра на mobile — на следующей строке.
4. **Flat report без пагинации:** paginator скрыт → header-right = tablist + filter-button → оба на одной строке; на mobile filter-button всё равно получает full-width строку.
5. **Нажатие кнопки:** `filterCollapsed` переключается, панель фильтров раскрывается/схлопывается. Работает в обеих DataTable-ветках (grouped + flat).
6. **Применение / сброс фильтров:** данные обновляются. Grouped DataTable и flat DataTable реагируют одинаково.

### type-check + lint

- `type-check`: чисто (0 ошибок).
- `lint`: 2 pre-existing warnings в незатронутых файлах (`application/index.ts`, `plugins/persist.ts`), новых предупреждений нет.

---

## 2026-05-08 — дофикс: numeric labels в filter options

### Диагностика

**Что было:** `isReportFilterOption` в `front/src/entities/report/filters.ts` проверял `label` только как `string | object`:

```ts
(typeof option.label === 'string' ||
  (typeof option.label === 'object' && option.label !== null))
```

API для фильтра `deal_id` возвращает опции вида `{ "value": 775390, "label": 775390 }` — `label` числовое. Число не проходило guard. Каскад: один option fail → `every(isReportFilterOption)` false → весь filter config invalid → `filters_available: undefined` → `hasFilters` computed = false → `v-if="hasFilters"` не проходит → кнопка «Фильтры» не монтируется на `/reports/6`, `/reports/7`, `/reports/8`.

**Почему CSS-фикс прошлой итерации не помог:** предыдущий фикс устранял проблему видимости кнопки (layout overflow на mobile). Здесь проблема другая — кнопка вообще не монтировалась из-за `v-if="hasFilters" === false`. CSS не мог помочь, если DOM-узел не создаётся.

### Что сделано

**`front/src/entities/report/filters.ts`**

1. Тип `ReportFilterOption.label`: `LocalizedText` → `LocalizedText | number` (было `string | Record<string, string>`, стало `string | number | Record<string, string>`).
2. Guard `isReportFilterOption`: добавлена ветка `typeof option.label === 'number'`.

Diff guard'а:
```diff
- (typeof option.label === 'string' ||
-   (typeof option.label === 'object' && option.label !== null))
+ (typeof option.label === 'string' ||
+   typeof option.label === 'number' ||
+   (typeof option.label === 'object' && option.label !== null))
```

**`front/src/components/filters/SelectFilter.vue`**

Маппинг `options` computed приводил numeric label через тернар `opt.label === 'object' ? getLocalizedText(...) : opt.label`. После расширения типа `opt.label` стал `string | number | Record<string, string>`, и TypeScript отклонил присвоение `number` в `SelectOption.label: string`. Фикс — заменить финальную ветку с `opt.label` на `String(opt.label)`. Коерция безопасна: для строк — identity, для чисел — `"775390"`, для boolean — не встречается в этом контракте.

### Изменённые файлы

| Файл | Что сделано |
|---|---|
| `front/src/entities/report/filters.ts` | `ReportFilterOption.label: LocalizedText | number`; guard — добавлена ветка `typeof === 'number'` |
| `front/src/components/filters/SelectFilter.vue` | `options` computed: финальная ветка `opt.label` → `String(opt.label)` |

### type-check + lint

- `type-check`: чисто (0 ошибок).
- `lint`: 2 pre-existing warnings, новых нет.

---

## 2026-05-12 — фиксы по QA (link key, collapsed_by_default, aggregate labels)

### Фикс 1: дубликат col.field ломает linkRefs

**Баг:** в "Непроданных" (id=9) две link-колонки используют один и тот же `field: 'estate_sell_id'` (для разных label). В `useReportPresentation.ts` computed `linkRefs` итерировал колонки и писал результат по ключу `col.field`. Второй цикл перезаписывал данные первого — обе ячейки получали значения последнего конфига.

**Что изменено:**

`front/src/pages/ReportPage/composables/useReportPresentation.ts` — тип `linkRefs` изменён с `Record<string, LinkRef[]>` на `Record<number, LinkRef[]>`. Итерация через `.forEach((col, colIndex) => ...)`, ключ = `colIndex`. Перезаписей при дублирующихся `field` больше нет.

`front/src/pages/ReportPage/index.vue` — сигнатура хелпера `getLinkRef` изменена с `(field: string, rowIndex: number)` на `(colIndex: number, rowIndex: number)`. В `v-for` плоского DataTable добавлен `(col, colIndex)`, вызов обновлён до `getLinkRef(colIndex, rowIndex)`. `:key` колонки дополнен `colIndex` для гарантии уникальности.

---

### Фикс 2: collapsed_by_default игнорируется

**Баг:** в Своде (id=10) `collapsed_by_default: false`, но все группы стартуют свёрнутыми. `watch` в `index.vue` читал `report.value?.config?.group_by` — но API не возвращает `config` в ответе данных, только в ответе настроек. Backend пробрасывает group_by через `meta`.

**Что изменено:**

`front/src/api/types/reports.ts` — в `ReportMetaDto` добавлено опциональное поле `group_by?: ReportGroupByDto`. Тип `ReportGroupByDto` определён позже в том же файле; TypeScript interface-forward references в пределах одного файла разрешаются корректно.

`front/src/pages/ReportPage/index.vue` — watch переключён на `report.value?.meta?.group_by ?? report.value?.config?.group_by` (фоллбэк на config для будущей совместимости). При `collapsed_by_default === false` все `group_key` добавляются в `expandedRows` — группы раскрываются сразу.

**Backend-контракт:** `meta.group_by` (частичная копия `config.group_by` только с нужными полями: `collapsed_by_default`, `collapsible`, `fields`). Frontend поддерживает оба пути через `??`.

---

### Фикс 3: aggregate labels в master row

**Баг:** в master row Свода числа агрегатов отображались без подписей (40 494 775 047 · 115 770 · ...) — пользователь не понимал, что есть что.

**Backend-контракт:** macrodata-engineer добавляет labels в `group_meta`. Поддерживаются оба варианта (резилиентность к финальному контракту):
- Вариант A (inline): `aggregates: { key: { value: 115770, label: { ru: "...", en: "..." } } }`
- Вариант B (parallel): `aggregates: { key: 115770 }, aggregate_labels: { key: { ru: "...", en: "..." } }`

**Что изменено:**

`front/src/api/types/reports.ts` — добавлены типы: `ReportGroupAggregateInlineDto` (вариант A, `{ value, label? }`), `ReportGroupAggregateValueDto` (union скаляра и инлайн-объекта). `ReportGroupMetaDto.aggregates` переключён с `Record<string, ReportTableCellDto>` на `Record<string, ReportGroupAggregateValueDto>`. Добавлено поле `aggregate_labels?: Record<string, LocalizedText>` (вариант B).

`front/src/pages/ReportPage/composables/useReportPresentation.ts` — добавлен хелпер `resolveAggregateValue(agg)` — извлекает скалярное значение из обоих вариантов. `formatAggregate` обновлён: сначала вызывает `resolveAggregateValue`, затем форматирует.

`front/src/pages/ReportPage/components/ReportGroupHeader.vue` — добавлены: импорт `useI18n`, тип `LocalizedText`, функции `resolveAggregateValue` и `resolveAggregateLabel` (ищет в variant B → variant A → пустая строка), `getLocalizedString`. Шаблон агрегата переработан: рядом с `agg-value` показывается `agg-label` (если есть). `overdue_count` передаёт значение через `resolveAggregateValue`. SCSS: `.group-agg-item` стал flex-контейнером с `.agg-label` и `.agg-value`.

---

### type-check + lint

- `type-check`: чисто (0 ошибок).
- `lint`: 2 pre-existing warnings в незатронутых файлах (`application/index.ts`, `plugins/persist.ts`), новых предупреждений нет.

---

## 2026-05-19 — Фикс переключения языка (откат с EN на RU после клика)

**Баг от пользователя:** «При переключении языка на английский, как будто бы сразу же прожимается под ним кнопка Русский и язык возвращается обратно».

**Симптом:** пользователь кликает EN в селекторе языка в `ProfileMenu` → UI на мгновение становится английским → сразу же откатывается обратно в RU.

**Root cause:** `application/locale/localeCoordinator.ts::changeLocale` имел rollback-логику в `catch`-блоке. Если `PUT /api/user` падал (ошибка сети, 4xx, 5xx, таймаут), фронт откатывал i18n-локаль на предыдущее значение через `setLocale(previousLocale)`. Видимый эффект — ровно тот, что описал пользователь: переключение → flash EN → возврат в RU. Локаль уже сохранена в `localStorage` синхронно перед запросом, так что rollback ломал пользовательский выбор без видимой причины.

**Что изменено:**

`front/src/application/locale/localeCoordinator.ts`:
- Удалён rollback `setLocale(previousLocale)` из `catch`-блока `changeLocale`. Вместо этого — `notificationCenter.warn(...)` с понятным сообщением.
- Удалены неиспользуемые `previousLocale`, `requestSession`, `sessionId` (последний только записывался, нигде не читался — мертвый guard для бывшего rollback).
- `startNewLocaleSession` теперь только сбрасывает `currentRequestId` и `isChangingLocale`.
- Подключён `notificationCenter` для уведомления пользователя при backend-failure (типа «язык не сохранился на сервере, но останется выбранным в этом браузере»).

`front/src/locales/ru.json` + `front/src/locales/en.json`:
- Добавлен ключ `errors.localeSyncFailed` в обоих файлах (RU + EN).

**Почему так, а не иначе:**
- Локаль уже синхронно записывается в `localStorage` ДО PATCH (в `setLocale`). На следующей загрузке `resolveInitialLocale` подхватит её. То есть пользовательский выбор НЕ теряется, даже если backend-sync упал.
- Phantom revert (флэш EN → возврат в RU) был хуже backend-sync-failure: пользователь видел эффект, но не понимал почему. Теперь — он видит свой выбор + warn-тост «не сохранилось на сервере».
- Backend-sync восстановится при следующем переключении локали (`changeLocale` вызовет PATCH снова) или при следующем bootstrap'е сессии.

**Что НЕ менялось (выходит за immediate fix, оставлено как отдельный вопрос):**
- `syncOnce` при bootstrap всё ещё может перетереть localStorage-локаль значением из backend (если они расходятся). Это не симптом текущего бага (он на клик), но потенциально может всплыть после reload, если PATCH упал. Если будет всплывать — фиксить отдельно (вероятно, инвертировать приоритет: localStorage > backend.user.locale при bootstrap).

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/application/locale/localeCoordinator.ts` | Удалён rollback в `catch` + cleanup мертвых переменных + warn-тост через `notificationCenter` |
| `front/src/locales/ru.json` | + `errors.localeSyncFailed` |
| `front/src/locales/en.json` | + `errors.localeSyncFailed` |

**Проверки:**
- `npm run type-check` — чисто (0 ошибок).
- `npm run lint` — чисто (одно pre-existing warning в `plugins/persist.ts`, не наше).

**QA сценарий для проверки на `devizion.macroglobal.tech`:**
1. Залогиниться (любая страница — например `/reports` или `/`).
2. Открыть профиль (правый верхний угол → клик по аватарке).
3. В дропдауне `Language` выбрать `English`.
4. **Ожидаемое:** UI становится английским, остаётся английским >2 секунд. Дропдаун профиля закрывается.
5. Перезагрузить страницу (F5).
6. **Ожидаемое:** язык остался English.
7. Если бэк PATCH `/api/user` всё ещё падает — в правом верхнем углу появляется warn-тост `Could not save the language on the server...`. Но UI **не возвращается** в Русский.
8. Сменить обратно на `Русский` — аналогично, должно остаться RU.

---

## 2026-05-20 — Reports list: плитка «Сгенерировать собственный отчёт»

**Задача:** на странице `/reports` добавить плитку-кнопку в конце сетки. Клик — создаёт новый `report_generation` чат и редиректит на `/ai-reports` так, чтобы пользователь сразу оказался внутри только что созданного чата. Без префилла промпта.

**Решение:**

Использован существующий action-marker паттерн из `useChatPage.handleActionMarker` (страница `/ai-chat`, переход в `/ai-reports`). Логика идентична: `chat.createAndOpenChat('report_generation', { setCurrent: false })` создаёт чат, кладёт его в `chatsStore` (через `prependChat + setActive`), и затем `router.push({ name: 'AiReports' })`. На целевой странице `useChatPage.initScope` подхватывает `activeChatId` из store и `loadChat`'ит свеже-созданный чат — пользователь сразу попадает внутрь.

Без префилла промпта (вариант A из ТЗ): никакого `pendingFirstMessage` не ставится — пользователь сам пишет, что хочет сгенерировать.

**Видимость плитки:** только при `canUseAi` (роли `superadmin/admin/analyst`). Для `viewer` плитка скрыта — потому что `/ai-reports` всё равно защищён той же ролью, и клик завершился бы редиректом.

**Стилизация:**
- Та же сетка, что и `ReportCard` (`grid-template-columns: repeat(auto-fill, minmax(250px, 1fr))` — не трогалось).
- Цвет — фирменный `$danger` (`var(--app-danger)`), CSS-переменная из проектной темы. Hover — `$red-50` фон + `$red-700` border/text. Не хардкодились hex'ы.
- Border-radius / тень / hover-transform — синхронизированы с `ReportCard` (`shadow-lg`, `translateY(-4px)`).
- Внутри: крупный красный `pi pi-plus` (`font-size: 2.5rem`) + подпись «Сгенерировать собственный отчёт» по центру.
- A11y: `role="button"`, `tabindex="0"`, `aria-label`, реакция на Enter / Space.

**Новый чат — НЕ новый параллельный путь:** использован `useChat()` (тот же кор, что и `useChatPage`). Никаких дублирующих API-вызовов.

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue` | Новый компонент — плитка с `+` и подписью |
| `front/src/components/cards/GenerateReportTile/index.ts` | Барель-экспорт |
| `front/src/pages/ReportsPage/index.vue` | Подключена плитка в `reports-grid` после `ReportCard` v-for; `canUseAi`-gate |
| `front/src/pages/ReportsPage/composables/useReportsPageActions.ts` | + `generateCustomReport()` action; `openAiReports` переведён на `name: 'AiReports'` для консистентности |
| `front/src/pages/ReportsPage/locale/ru.json` | + `generateCustomTile: "Сгенерировать собственный отчёт"` |
| `front/src/pages/ReportsPage/locale/en.json` | + `generateCustomTile: "Generate custom report"` |

**Проверки:**
- `npm run type-check` — чисто (0 ошибок).
- `npm run lint` — чисто (0 ошибок). Один pre-existing warning в `plugins/persist.ts`, не наше.
- i18n ключи RU ↔ EN симметричны (`diff <(jq keys ru.json) <(jq keys en.json)` — пусто).

**QA-сценарий для `devizion.macroglobal.tech`:**

1. Залогиниться под аккаунтом с ролью `superadmin/admin/analyst`.
2. Открыть `/reports`.
3. **Ожидаемое:** в конце сетки плиток отчётов — новая плитка с красным `+` и подписью «Сгенерировать собственный отчёт» (или «Generate custom report» при EN).
4. Hover мышкой — плитка должна слегка подняться (`translateY(-4px)`), тень усилиться, фон стать светло-красным.
5. Клик по плитке.
6. **Ожидаемое:** редирект на `/ai-reports`, открыт **новый** (пустой) чат — без сообщений, готов принять ввод. В сайдбаре чатов этот чат — первый в списке.
7. Перейти под ролью `viewer`.
8. **Ожидаемое:** плитка скрыта (`v-if="canUseAi"`).
9. Mobile (≤576px): плитка отображается одним столбцом, как остальные карточки.

**Fixup (2026-05-20, continuation):** replaced non-existent `$font-weight-bold` with `$font-weight-semibold` in `GenerateReportTile.vue` per QA finding (`npm run build` failed on Sass undefined variable). `npm run build` теперь чистый.

**Fixup #2 (2026-05-20, continuation):** router-state hand-off для активации только что созданного чата на `/ai-reports`. QA-tester pass 2 поймал, что плитка создаёт чат (POST /api/chats → 201), но страница `/ai-reports` показывает пустое состояние «Выберите отчёт или создайте новый» — `activeReportGenerationChatId = null` после `chat.clearScope()` → `chatsStore.clear()` в `initScope`. Pre-existing flow (UX «открыть AI-конструктор» страдает тем же), глобально не правим.

Решение — локально под нашу фичу: `generateCustomReport()` передаёт `chatId` через Vue Router 4 `state` (`router.push({ name: 'AiReports', state: { activateChatId } })`). В `useChatPage.initScope` после `await chat.fetchChats()` — новый helper `consumeActivateChatIdFromRouterState()` читает `router.options.history.state.activateChatId` (числовой), сразу очищает поле через `window.history.replaceState({...rest}, '')` (one-shot — back/forward не повторит активацию), затем сверяет что чат присутствует в загруженном списке И `chat.type === options.type` (защита от stale state / wrong-scope landing) → `chat.loadChat(id, { type })`. `clearScope` / `chatsStore.clear()` / `reconcileActiveChats` НЕ тронуты.

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/pages/ReportsPage/composables/useReportsPageActions.ts` | `generateCustomReport()` теперь передаёт `state: { activateChatId: result.chatId }` в `router.push`; обновлён jsdoc с объяснением почему не хватает `chatsStore.setActive` |
| `front/src/pages/shared/useChatPage.ts` | + helper `consumeActivateChatIdFromRouterState()` (читает + сразу гасит state). В `initScope` после `fetchChats()` (но до фоллбэка на `activeChat.value`) — попытка активировать переданный чат, если он есть в свежем списке и совпадает по scope |

**Проверки:**
- `npm run type-check` — чисто.
- `npm run build` — чисто (только pre-existing chunk-size warning для primevue).
- `npm run lint` — чисто (один pre-existing warning в `plugins/persist.ts`, не наш).
- Pre-existing flows не задеты: `handleActionMarker` (CTA из ассистент-бабла) продолжает работать через `pendingFirstMessage`-механизм, `openAiReports` (без state) идёт по старой ветке `activeChat.value`.

**QA-сценарий (повторный pass для плитки):**

1. `/reports` → клик по плитке «Сгенерировать собственный отчёт».
2. Ожидаемое: редирект на `/ai-reports`, **открыт новый пустой чат** (нет «Выберите отчёт…»). В сайдбаре чатов он первый.
3. После клика — в DevTools: `history.state.activateChatId` уже стёрто (one-shot).
4. Назад в браузере → `/reports`, вперёд → `/ai-reports`: чат **не** реактивируется автоматически, открывается тот, что в `activeReportGenerationChatId` (стандартный flow).

---

## 2026-05-20 — Restyle GenerateReportTile под ReportCard

**Задача:** пользователь сказал «полная хуйня» про текущий дизайн плитки «Сгенерировать собственный отчёт» — красный + dashed border не вписываются в дашборд. Сделать визуально как `ReportCard` (сестра), только внутри крупный плюс + подпись. Логика не меняется.

**Что было (плохо):**
- `border: 1px dashed $danger`, фон transparent, `color: $danger`.
- Hover: `background: $red-50`, `border-color: $red-700`, `color: $red-700`.
- Focus: `outline: 2px solid $danger`.

**Что стало (выровнено с `ReportCard.vue`):**
- Бордер / фон — наследуются от PrimeVue Card (никакого кастомного `border` / `background`), как у соседних карточек отчётов.
- Hover — точная копия `ReportCard`: `transform: translateY(-4px)` + `box-shadow: $shadow-lg`. Никаких смен цвета.
- Focus-visible — нейтральный `outline: 2px solid $primary` (без хардкода красного).
- Иконка `+` — `color: $surface-700` (нейтральный тёмно-серый, как у Toolbox icon-button'ов в этой теме).
- Подпись — `color: $surface-900`, как `.card-title` в `ReportCard`.
- Размер иконки / spacing / `min-height` / `:deep(.p-card-body / .p-card-content)` — не тронуты.

**Шаблон / пропсы / эмиты / a11y (`role="button"`, `tabindex="0"`, keyboard handlers, `aria-label`) — без изменений.**

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue` | переписан `<style scoped>`: убраны все `$danger / $red-*` и `dashed`, добавлен hover + focus в стиле `ReportCard`. Иконка и подпись — нейтральные `$surface-700` / `$surface-900` |

**Проверки:**
- `npm run type-check` — чисто.
- `npm run build` — чисто (только pre-existing chunk-size warning для primevue).

**Fixup (2026-05-20, продолжение): removed `min-height: 7rem` — tile no longer stretches the grid row.** Пользователь увидел, что плитка диктовала высоту всему ряду на ReportsPage и соседние `ReportCard` визуально растягивались. У `ReportCard` нет собственного `min-height` (высоту задаёт контент: title + description, а ряд выравнивается через grid `align-items: stretch` + `height: 100%`). Привёл `GenerateReportTile` к той же модели: убрал `min-height: 7rem` с `.tile-inner`, оставил `height: 100%` — теперь grid сам подтягивает плитку к высоте соседок, а не наоборот. Padding (`$space-5` в `.p-card-body`) совпадает с `ReportCard`, не трогал. Шаблон / props / a11y / иконка / подпись — без изменений. `type-check` + `build` — чисто.


---

## 2026-05-20 — GenerateReportTile: inner dashed rectangle + subtitle

**Запрос пользователя:** переделать визуал плитки «Generate custom report». Внешнюю карточку (`p-card.generate-report-tile`) с её hover/focus/sizing **не трогать**. Внутри добавить **внутренний прямоугольник** с серым фоном и **прерывистой (dashed)** границей; основной текст сделать приглушённо-серым; под ним добавить локализованную подпись.

**Что сделано в `GenerateReportTile.vue`:**

- Шаблон: вокруг иконки + текста добавлен `<div class="tile-dashed">` (новый внутренний прямоугольник). Сам `.tile-inner` стал тонким flex-контейнером (`height: 100%`), который растягивает `.tile-dashed` на всю плитку — внешний `Card`, его padding и hover остались как были.
- `.tile-dashed` — фон `$surface-100`, `border: 1px dashed $surface-400`, `border-radius: $card-border-radius`, центрирует иконку + текст внутри (`flex-direction: column`, `align-items: center`, `justify-content: center`).
- Иконка `pi pi-plus` — оставлена, цвет приглушён с `$surface-700` → `$surface-500` (более тихо, в тон новому фону).
- `.tile-label` — цвет с `$surface-900` → `$surface-600` (приглушённый серый, как просили).
- Добавлен `.tile-subtitle` — `$font-size-sm`, `font-weight: 400`, `color: $surface-500` (ещё тише, чем label).
- Новый опциональный prop `subtitle?: string` — обратно-совместимо, существующих вызовов не ломает.
- A11y (`role="button"`, `tabindex="0"`, `aria-label`, keyboard handlers), хук `click` emit, внешние `.generate-report-tile` стили (hover/focus/sizing/`:deep(.p-card-body)`/`:deep(.p-card-content)`) — **не тронуты**.

**Что сделано в `ReportsPage`:**

- `pages/ReportsPage/index.vue` — пробросил `:subtitle="t('generateCustomTileSubtitle')"` в `<GenerateReportTile>`.
- `pages/ReportsPage/locale/ru.json` — добавлен ключ `generateCustomTileSubtitle: "Вы можете создать свой собственный отчёт с помощью ИИ"`.
- `pages/ReportsPage/locale/en.json` — добавлен симметричный ключ `generateCustomTileSubtitle: "You can create your own report with the help of AI"`.

**i18n-ключи (новые):**
- `pages/ReportsPage`: `generateCustomTileSubtitle` (RU + EN, симметрично).

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue` | добавлен `.tile-dashed` (серый фон + dashed border), опциональный prop `subtitle`, приглушены цвета icon/label, новый `.tile-subtitle` |
| `front/src/pages/ReportsPage/index.vue` | проброшен `:subtitle="t('generateCustomTileSubtitle')"` |
| `front/src/pages/ReportsPage/locale/ru.json` | новый ключ `generateCustomTileSubtitle` |
| `front/src/pages/ReportsPage/locale/en.json` | новый ключ `generateCustomTileSubtitle` |

**Проверки:**
- `npm run type-check` — чисто.
- `npm run lint` — 0 errors. 1 warning остаётся в `plugins/persist.ts` (pre-existing, не относится к этим правкам — см. memory `feedback_lint_preexisting`).

### 2026-05-20 — follow-up: i18n key path verification (QA-report raw `generateCustomTileSubtitle`)

**Симптом:** QA на локальном билде увидел raw-строку `generateCustomTileSubtitle` вместо переведённого subtitle.

**Диагностика:**
- Ключ лежит в `front/src/pages/ReportsPage/locale/{ru,en}.json` — это корректная page-scoped локаль.
- `useReportsPageData` пользуется `useLocalI18n({ en, ru })` (из `composables/useLocalI18n.ts`), которая мержит global + page messages и возвращает `useI18n({ useScope: 'local', inheritLocale: true, messages })`. То есть `t('generateCustomTileSubtitle')` в `ReportsPage/index.vue` корректно резолвится из page-scoped locale.
- Сосед-ключ `generateCustomTile` живёт ровно там же и работает — значит и wiring, и path в порядке.
- Локальные изменения в `pages/ReportsPage/locale/{ru,en}.json` ещё не закоммичены и не попали в перебилженный `dist/` локального контейнера → frontend (nginx + статика) отдаёт старую сборку. После rebuild контейнера ключ подхватится.

**Действие:** ничего перекладывать не надо — путь верный, обе локали симметричны. Достаточно локального rebuild'а frontend-контейнера (зона `deploy-engineer`).

**Проверки:** `npm run type-check` — чисто; `npm run lint` — 0 errors, 1 pre-existing warning в `plugins/persist.ts`.

---

## 2026-05-20 — GenerateReportTile: жирный dashed + drop subtitle + новый заголовок

**Запрос пользователя (следующая итерация по тому же тайлу):**

1. Сделать dashed-границу внутреннего прямоугольника `.tile-dashed` крупнее и жирнее (CSS `border-style: dashed` не настраивается по длине штрихов — переключиться на SVG `<rect stroke-dasharray>`).
2. Полностью убрать subtitle (prop, шаблон, стили, передачу из страницы, ключ i18n).
3. Сменить заголовок плитки на:
   - RU: «Сгенерировать собственный отчёт с ИИ»
   - EN: «Generate your own report with AI»

**Что сделано в `GenerateReportTile.vue`:**

- **Dashed-граница → SVG-фон.** Убран `border: 1px dashed $surface-400`, вместо него `background-image: url("data:image/svg+xml;utf8,<svg ...>")` с `<rect>`: `stroke='%23cbd5e1'` (= $surface-400), `stroke-width='3'`, `stroke-dasharray='14 8'`, `rx='12' ry='12'` (= $card-border-radius), `width/height='100%'`, `fill='none'`. `background-repeat: no-repeat`. Это даёт стабильно крупные штрихи на любых браузерах, не зависит от user-agent-defaults. Background растягивается под фактический размер `.tile-dashed`, штрихи остаются одинаковой длины во всех направлениях.
- **Subtitle полностью удалён:**
  - Prop `subtitle?: string` удалён из `interface Props` (теперь только `label: string`).
  - `<span v-if="subtitle" class="tile-subtitle">{{ subtitle }}</span>` удалён из шаблона.
  - Стили `.tile-subtitle` удалены из `<style scoped>`.
- A11y / hover / focus / sizing / шаблон карточки внешней `Card` / иконка / `.tile-label` — без изменений.

**Что сделано в `ReportsPage`:**

- `pages/ReportsPage/index.vue` — убрана передача `:subtitle="t('generateCustomTileSubtitle')"` в `<GenerateReportTile>`. Остался только `:label`.
- `pages/ReportsPage/locale/ru.json`:
  - `generateCustomTile`: `"Сгенерировать собственный отчёт"` → `"Сгенерировать собственный отчёт с ИИ"`.
  - Удалён ключ `generateCustomTileSubtitle`.
- `pages/ReportsPage/locale/en.json`:
  - `generateCustomTile`: `"Generate custom report"` → `"Generate your own report with AI"`.
  - Удалён ключ `generateCustomTileSubtitle`.

**Файлы:**

| Файл | Изменение |
|---|---|
| `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue` | dashed-border переведён на SVG `background-image` (крупные штрихи 14/8 + stroke-width 3); удалён prop `subtitle`, шаблонный `<span>` и стили `.tile-subtitle` |
| `front/src/pages/ReportsPage/index.vue` | убран проп `:subtitle` |
| `front/src/pages/ReportsPage/locale/ru.json` | обновлён `generateCustomTile`; удалён `generateCustomTileSubtitle` |
| `front/src/pages/ReportsPage/locale/en.json` | обновлён `generateCustomTile`; удалён `generateCustomTileSubtitle` |

**Проверки:**
- `npm run type-check` — чисто (0 ошибок).
- `npm run lint` — 0 errors, 1 pre-existing warning в `plugins/persist.ts` (не наш — см. memory `feedback_lint_preexisting`).
- i18n ключи RU ↔ EN симметричны (`diff <(jq keys ru.json) <(jq keys en.json)` — пусто).

**Note о размере штрихов:** при необходимости тонкой настройки — править `stroke-dasharray` (`14 8`) и/или `stroke-width` (`3`) в SVG-URL. `rx/ry='12'` синхронизированы с дефолтным `$card-border-radius` темы Aura; если переменная изменится — поправить вручную.

---

## 2026-05-20 — AI-чат: sync frontend to M4 async contract + SSE wiring

**Баг от пользователя:** при отправке сообщения в AI-чат вылетает toast `Cannot read properties of undefined (reading 'id')`. Создание отчёта не работало.

**Root cause.** После backend M4-рефакторинга `POST /api/chats/{chat}/messages` возвращает 202 c полями `user_message`, `assistant_message`, `stream_url` (+ опциональный `chat`). Фронт же ждал старую sync-форму `{message, chat?}` — поле `message` теперь `undefined` → mapper `mapChatMessageDtoToMessage(undefined)` падал на чтении `dto.id`. Toast «Cannot read properties of undefined» — это эта самая ошибка из mapper'а, пойманная в `notifyApiError`.

Авторитетный контракт — `chats_frontend.md` (M7-sync): секции «Отправить сообщение», «Streaming активного AI-turn'а», «Reload-восстановление через batch endpoint». Frontend приведён к нему как к source of truth.

### Что сделано

**1. Типы DTO** (`front/src/api/types/chats.ts`)

- Новый тип `ChatMessageStatus = 'pending'|'running'|'done'|'error'|'cancelled'`.
- `ChatMessageDto`:
  - `content: string | null` (для assistant-placeholder'ов с `status=pending`).
  - Опциональные lifecycle-поля: `status?`, `started_at?`, `finished_at?`, `events_count?`.
  - `updated_at` и `metadata` стали опциональными (полно-возвращаются только из `GET /messages`, но в 202-payload могут отсутствовать).
- `ChatMessageMetadataDto.error`: union `ChatMessageErrorDto | string | null` (M4 → `{exception_class, message}`; legacy sync — строка).
- Новый `ChatMessageErrorDto = {exception_class?, message?}`.
- **`SendMessageResponseDto` полностью переписан** под M4: `user_message`, `assistant_message`, `stream_url`, `chat?`. Прежняя плоская форма `{message, chat?}` удалена.
- Новые типы для batch-endpoint replay: `ChatMessageEventType`, `ChatMessageEventDto`, `ChatMessageEventsResponseDto`.

**2. Entity + mapper** (`front/src/entities/chat/types.ts`, `mappers.ts`, `index.ts`)

- `ChatMessage`: `content: string | null`, опциональные `status`, `startedAt`, `finishedAt`, `eventsCount`.
- `ChatMessageMetadata.error: ChatMessageError | null` (нормализованный объект; legacy-строка превращается в `{message: <string>}`).
- `ChatListItem.lastMessage.content: string | null` (assistant-placeholder в сайдбаре).
- `mapChatMessageDtoToMessage` пробрасывает все новые поля.
- `index.ts` экспортирует `ChatMessageStatus`, `ChatMessageError`, остальные служебные типы.

**3. API** (`front/src/api/chats.ts`)

- `chatsApi.sendMessage`: убран кастомный 10-минутный timeout — теперь 202 за десятки мс, дефолтного axios-timeout хватает. Возвращает `SendMessageResponseDto` нового вида.
- Новый метод `chatsApi.fetchMessageEvents(chatId, messageId, since=0, limit=100)` для batch-replay event-log'а.

**4. Сервис** (`front/src/services/ChatService.ts`)

- `sendMessage` теперь возвращает `SendMessageResult = {userMessage, assistantMessage, streamUrl, chat?}` — типизированная домен-форма. Поле `chat` маппится через `mapChatDetailDtoToDetail`.
- Новый метод `fetchMessageEvents(chatId, messageId, since?, limit?)` — пробрасывает batch-endpoint наружу.

**5. SSE-композабл — НОВЫЙ ФАЙЛ** (`front/src/components/chat/composables/useChatStream.ts`)

Изолированный composable, отвечающий за подписку на event-stream одного assistant-сообщения. Используется и при свежем send (с `streamUrl` из 202-ответа), и при reload (resume по уже идущему сообщению).

- **Транспорт — `fetch` + `ReadableStream`** (не `EventSource`). Причина: Vizion использует Bearer-token (`Authorization: Bearer <token>`), а `EventSource` не поддерживает кастомные заголовки. SSE-фреймы парсятся вручную: разбиение на `\n\n`, поля `event:`/`data:`/`id:`, JSON-payload в `data`.
- **Resume через cursor.** Composable хранит `lastSequence`; каждое (пере)подключение — `?since=lastSequence`. На wall-clock close (480 сек) сервер закрывает поток без `done`-sentinel → composable переподключается до 2 раз, сохраняя cursor. События с `sequence ≤ lastSequence` отбрасываются (защита от дубликатов на reconnect).
- **Reload-restore.** При старте сначала вызывает `fetchMessageEvents(chatId, messageId, since=lastSequence)` рекурсивно по pagination до `has_more=false`. Если backend возвращает `message_status` ∈ {done, error, cancelled} — стрим не открывается, lifecycle сразу терминальный. Иначе — открывается живой стрим с advanced cursor.
- **Lifecycle:** `idle → connecting → streaming → done|error|cancelled`. Реактивный `lifecycle: Ref<ChatStreamLifecycle>` — наружу. Реактивный `events: shallowRef<ChatMessageEventDto[]>` — append-only timeline.
- **Callbacks:** `onEvent(event)` — за каждый event (live или replay), `onSettled(finalStatus, {error?})` — при терминальном статусе. Caller сам решает, что отрисовывать.
- **Cleanup:** `stop()` — `AbortController.abort()` + lifecycle → cancelled. `reset()` — обнуляет state. `messageId`-guard защищает от race conditions: если caller вызывает `start()` повторно с другим `messageId`, старые callbacks не дёргаются.

**6. Messaging composable обновлён** (`front/src/components/chat/composables/useChatMessaging.ts`)

- Использует новую форму ответа: разбирает `userMessage`/`assistantMessage`, дропает optimistic-плейсхолдер, прикрепляет оба persistent-сообщения. Если 202-ответ принёс `chat` snapshot — применяет `title`/`reportId`/`aiContext`/`report` сразу (без отдельного `fetchChat`), плюс `syncChatListItemFromDetail`.
- Удалена мёртвая ветка `if (responseMessage.role === 'system')` — в M4-мире ошибки приходят как `assistant`+`status=error`, не как `system`.
- После 202 — вызывает `subscribeToAssistantStream(chatId, assistant_message.id, stream_url)`. Stream callbacks:
  - На `event.type === 'started'` — patch'ит `status: 'running'` в локальном сообщении.
  - На `final_message` — инкрементально подставляет `content` (preview-render). Финальный canonical-контент придёт из `fetchChat` после settled.
  - На `onSettled(status)` — re-fetch'ит `chat` через `fetchChat`, обновляет `currentChat`, синхронизирует `ChatListItem`. На `status=error` — `notifyError` с message из `metadata.error.message` или fallback `errors.aiTurnFailed`.
- **`isSending` теперь computed** = `isPostingMessage || stream.lifecycle ∈ {connecting, streaming}`. Кнопка «Отправить» остаётся disabled на всё время стрима — backend всё равно вернёт 409 при попытке параллельного POST.
- Новая публичная функция `resumeActiveStream(chatId)` — для reload-flow. Ищет в `currentChat.messages` assistant-сообщение со `status ∈ {pending, running}` и стартует stream c `resumeFromBeginning: true` (full batch replay → live stream). URL строится по контракту: `/api/chats/{chatId}/stream/{messageId}`.

**7. Query composable — hook для reload-resume** (`front/src/components/chat/composables/useChatQueries.ts`, `useChat.ts`)

- `useChatQueries` принимает новую опцию `onChatLoaded?: (chatId: number) => void`. Вызывается в `commit` коллбэке `loadChat` после успешной загрузки.
- `useChat` связывает messaging и queries: `chatQueries({ ..., onChatLoaded: chatMessaging.resumeActiveStream })`. Это означает: каждый раз когда чат открывается (через `loadChat` — initial mount, manual select, router-state activation), мы автоматически проверяем нужен ли SSE-resume.

**8. UI — рендер pending-ассистента** (`front/src/components/chat/ChatMessageBubble.vue`)

- Добавлен computed `isAwaitingContent` — true когда `role='assistant'` и (`status` ∈ {pending, running} ИЛИ `content == null`).
- В template: assistant-bubble в этом состоянии рендерит embedded `<ChatTypingIndicator>` вместо `v-html` (который упал бы на null) + скрывает bubble-time (нечего показывать пока не settled).
- Imports: `ChatTypingIndicator from './ChatTypingIndicator.vue'`.
- `extracted` computed coerce'ит `content ?? ''` — safety для legacy кода, который мог дёрнуть `extractActionMarker(null)`.
- Минимальный SCSS-блок `.bubble-typing { padding:0; margin:0; }` — typing-индикатор уже сидит внутри bubble (а не отдельной строкой), поэтому собственный padding не нужен.
- `ChatTypingIndicator` в `ChatMessageList.vue` оставлен — он отражает короткое окно «user-message ушёл, ассистентского placeholder'а ещё нет» (пока летит POST). Двойного индикатора не будет: `isPostingMessage` сбрасывается ровно когда appendится `result.assistantMessage`.

**9. Мок-handler** (`front/src/mocks/handlers.ts`)

- Ответ `POST /messages` приведён к новому контракту: `{user_message, assistant_message, stream_url, chat}` + status 202. Assistant-message эмитируется уже `status='done'` (mock не симулирует SSE — UI рендерит финальный контент без стрима).
- Error-ветка `MOCK_AI_ERROR` тоже на новый контракт: ассистент с `status='error'` и `metadata.error: {message: '...'}`.
- Cast `assistantMessage.content ?? ''` для `last_message.content` — DTO теперь nullable.

**10. i18n** (`front/src/components/chat/locale/{ru,en}.json`)

- Добавлен симметричный ключ `errors.aiTurnFailed` (RU + EN).
- `diff <(jq paths ru) <(jq paths en)` — пусто. Симметрия сохранена.

### Файлы (итого)

| Файл | Изменение |
|---|---|
| `front/src/api/types/chats.ts` | `ChatMessageStatus`, lifecycle-поля в `ChatMessageDto`, nullable `content`, error-объект, новый `SendMessageResponseDto`, типы для events endpoint |
| `front/src/entities/chat/types.ts` | `ChatMessage.{status,startedAt,finishedAt,eventsCount}`, nullable `content`, `ChatMessageError` |
| `front/src/entities/chat/mappers.ts` | Маппинг lifecycle-полей + нормализация error из string/object |
| `front/src/entities/chat/index.ts` | Реэкспорт `ChatMessageStatus`, `ChatMessageError`, `ChatMessageMetadata`, `ChatAiContext`, `ChatToolCall`, `ChatUsage` |
| `front/src/api/chats.ts` | `sendMessage` без 10-мин timeout; новый `fetchMessageEvents` |
| `front/src/services/ChatService.ts` | `sendMessage` возвращает `SendMessageResult` (userMessage/assistantMessage/streamUrl/chat?); новый `fetchMessageEvents` |
| `front/src/components/chat/composables/useChatStream.ts` | **новый файл** — SSE через fetch+ReadableStream, batch replay, resume cursor, reconnect-loop |
| `front/src/components/chat/composables/useChatMessaging.ts` | Чтение `assistant_message`, snapshot `chat`, подписка на stream через callbacks; computed `isSending`; новый `resumeActiveStream` |
| `front/src/components/chat/composables/useChatQueries.ts` | Опция `onChatLoaded`, вызов в commit-коллбэке loadChat |
| `front/src/components/chat/composables/useChat.ts` | Wiring `onChatLoaded: chatMessaging.resumeActiveStream` |
| `front/src/components/chat/ChatMessageBubble.vue` | `isAwaitingContent` computed, embedded typing indicator, null-safe extract |
| `front/src/components/chat/locale/ru.json` | + `errors.aiTurnFailed` |
| `front/src/components/chat/locale/en.json` | + `errors.aiTurnFailed` |
| `front/src/mocks/handlers.ts` | POST /messages мок на новом контракте (202 + user/assistant/stream_url); error-ветка тоже |
| `front/src/stores/chats.ts` | Нет правок — тип `lastMessage.content` стал nullable в entity, оператор `?? null` уже корректен. |

### Проверки

- `npm run type-check` — чисто (0 ошибок).
- `npm run lint` — 0 errors. 1 pre-existing warning в `plugins/persist.ts` (не наше — см. memory `feedback_lint_preexisting`).
- i18n RU↔EN — симметрия `chat/locale/{ru,en}.json` проверена через `jq paths` diff.

### Что НЕ делалось

- Не отрисовываем timeline с tool-step индикаторами (probe_data → create_report → ...). Контракт `events` фронт читает и хранит в `useChatStream.events`, но визуально пока используем только `started`/`final_message` для статуса. Полная timeline-визуализация — отдельная задача (можно поверх существующего composable добавить компонент `ChatStreamTimeline.vue` без правок логики).
- Не реализовали отдельный `messages/{id}/events` debug-просмотр для завершённых сообщений с `events_count > 0`. Контракт поддержан (`chatService.fetchMessageEvents` + composable `replayBatchEvents`), но в UI пока не используется.
- Backend не правился — только фронт-контракт приводился под source-of-truth `chats_frontend.md`.

### QA-сценарии для следующего pass'а

1. **Главный сценарий — создание отчёта через AI-чат** (был сломан):
   - `/ai-reports` → создать чат → отправить запрос «Покажи топ 5 ЖК по сумме сделок».
   - Ожидаемое: user-сообщение → assistant-плейсхолдер с typing-индикатором (`status=pending` → `running` после первого SSE-event) → постепенное появление tool-call событий (внутренний state, не отрисован) → после settled: финальный текст + кнопка «Открыть отчёт» (если `chat.report_id` появился).
   - **НЕТ toast'а** «Cannot read properties of undefined (reading 'id')».
2. **Кнопка «Отправить» disabled пока стрим идёт.** Попытка отправить второе сообщение во время `streaming` — кнопка inactive (не пускает в backend 409).
3. **Reload во время стрима.** Отправить сообщение → ждать пока assistant в `running` → нажать F5. На реалоаде чат загружается с `pending`/`running` ассистентом → `useChat.loadChat` → `onChatLoaded` → `resumeActiveStream` → batch-replay + live-resume → settled.
4. **Error-handling.** Если backend упадёт с `status=error` (rate limit, etc.) — toast с message из `metadata.error.message` (или fallback `errors.aiTurnFailed`), бабл остаётся, content из бэка отрисовывается.
5. **quick_qa flow.** Чат `/ai-chat`, обычный вопрос — должен работать тем же путём (assistant placeholder → SSE → final). Action-marker (CTA «Открыть в AI-конструкторе») продолжает работать через `handleActionMarker` + router state — этот flow не трогали.

---

## 2026-05-20 — GenerateReportTile: компактнее + dashed на чистом CSS

**Задача:** тайл «сгенерировать отчёт» доминировал в гриде `/reports` — был визуально выше/толще соседних `ReportCard`. Плюс пунктирная рамка собиралась SVG data-URL, что неестественно — нужен чистый CSS-border.

**Файл:** `front/src/components/cards/GenerateReportTile/GenerateReportTile.vue`.

**Что изменил:**

- Удалил `height: 100%` с корня `.generate-report-tile` — высоту теперь диктует контент. Карточка осталась резиновой по ширине (`grid-template-columns: repeat(auto-fill, minmax(250px, 1fr))` в `ReportsPage`).
- Внешний `:deep(.p-card-body)` padding: `$space-5` → `$space-3` (убрал двойной отступ — внутренний `.tile-dashed` тоже имел свой padding).
- Внутренний `.tile-dashed` padding: `$space-5` → `$space-3`, gap: `$space-3` → `$space-2`.
- Заменил SVG-data-URL `background-image` (dashed rect через stroke-dasharray) на стандартное CSS-свойство `border: 3px dashed $surface-400`. Цвет сохранён (`$surface-400` ≈ `#cbd5e1`), толщина 3px даёт крупные штрихи; соотношение штрих/промежуток отдано браузеру (Chromium ≈ 2:1, что приемлемо по ТЗ).
- Иконка `.tile-icon`: `2.5rem` → `1.5rem` (близко к font-size заголовка `ReportCard`).
- Label `.tile-label`: `$font-size-md` → `$font-size-sm` (соответствует описанию в `ReportCard`).
- Серый фон `.tile-dashed` (`$surface-100`) и `border-radius: $card-border-radius` сохранены.

**Почему не задавал точное соотношение 1:2 для dash:** через `border-style: dashed` это невозможно без `border-image`/SVG/`repeating-linear-gradient` — пользователь явно попросил «встроенное CSS свойство». Принят дефолт браузера.

**Прогресс:** `type-check` — чисто. `lint` — 1 pre-existing warning в `plugins/persist.ts` (не связан).

---

## 2026-05-20 — AI-чат iteration 2: fix duplicate indicator + streaming contract review

**Контекст от пользователя:** после предыдущей AI-чат итерации (M4-sync + SSE wiring) пользователь увидел два дефекта:
1. Два typing-индикатора одновременно — один внутри ассистент-bubble (новый, нужный), второй — отдельным блоком ниже.
2. Текст ассистента появляется одним куском после `final_message`, без посимвольного стриминга «как ChatGPT/Claude».

### Дефект 1 — дубль typing-индикатора

**Root cause.** Прежний (sync) флоу использовал `<ChatTypingIndicator v-if="isSending" />` непосредственно в `ChatMessageList.vue` — это «отдельный блок ниже всех сообщений». В предыдущей итерации добавили индикатор **внутри bubble** (через `isAwaitingContent` в `ChatMessageBubble.vue`), но list-level индикатор оставили намеренно — для «брифного окна между отправкой и появлением assistant-placeholder'а». Но в реальности `assistant_message` приходит сразу в 202-ответе → одновременно: (а) `isSending = true` (стрим идёт), (б) ассистент-bubble в `status=pending` рендерит свой typing. Два индикатора подряд.

**Фикс.** Удалена строка `<ChatTypingIndicator v-if="isSending" />` из шаблона `ChatMessageList.vue` + убран теперь неиспользуемый import `ChatTypingIndicator`. Prop `isSending` остаётся в компоненте — он нужен в `watch(() => props.isSending, ...)` для авто-скролла при начале отправки.

«Бриф-окно до появления assistant_message» в M4 фактически отсутствует — `POST /messages` возвращает оба сообщения (user + assistant placeholder со `status='pending'`, `content=null`) в одном 202-ответе, и `useChatMessaging.sendMessage` пушит их обоих одной операцией перед стартом SSE. Optimistic user-message виден сразу; ассистент-bubble с typing — сразу после 202.

### Дефект 2 — посимвольный стриминг контента

**Read `chats_frontend.md` (секция «Streaming активного AI-turn'а», строки 526-538) повторно.** Контракт событий:

| Тип | Что несёт |
|---|---|
| `started` | Job подхватил сообщение |
| `thinking` | Промежуточный шаг AI (рассуждения, **per-step marker**, не text-delta) |
| `tool_call` / `tool_result` | вызовы инструментов |
| `dry_run_start` / `dry_run_result` | dry-run валидация |
| `retry` | повтор |
| `final_message` | **Финальный текст одним куском** в `payload.content` |
| `error` | ошибка |

**Контракт НЕ предусматривает text-delta / chunk / content-piece событий.** Backend эмитит финальный текст **одним shot'ом** через `final_message` (это то, что сейчас фронт и делает: `useChatMessaging.subscribeToAssistantStream` слушает `event.type === 'final_message'` и подставляет `payload.content` целиком).

Событие `thinking` — это **per-step marker** (флаг что AI «думает на этом шаге»), а не поток reasoning-токенов. Payload его в md не специфицирован как text-delta.

**Вывод: посимвольный стриминг — это backend-задача**, не фронт. Нужно: (а) расширить контракт в `chats_frontend.md` (новый event-тип `text_delta` / `content_chunk` с `payload.delta: string` и/или `payload.is_thinking: bool`), (б) на стороне backend ChatService прокачать дельты из Prism stream-API в `ChatEventEmitter`. **Это зона `chat-ai-engineer`** + sync `chats_frontend.md` через `product-manager`.

Чтобы фронт был готов, в `useChatMessaging.subscribeToAssistantStream` уже сидит generic-handler `onEvent`. Когда backend начнёт эмитить text-delta — добавится одна ветка `event.type === 'text_delta'` (или как назовём): `replaceMessageById(..., { content: (currentContent ?? '') + delta })`. Bubble уже корректно ведёт себя: если `content !== null` — рендерит markdown, typing-индикатор гаснет (`isAwaitingContent` smart: `pending/running` ИЛИ `content == null`). Сейчас условие `isAwaitingContent` оставлено как есть — при появлении первой дельты `content` станет non-null, индикатор выключится и пойдёт инкрементальный markdown. Перерисовка опирается на реактивность `currentChat.messages`.

**Минимальная backend-задача (флаг для main / chat-ai-engineer):**

- В `App\Services\AI\ChatService` (внутри Prism-loop) обернуть поток текстовых дельт от провайдера и эмитить через `ChatEventEmitter`. Тип события — например `text_delta` с `payload: {delta: string, kind: 'content'|'thinking'}` (`thinking` — отдельным kind'ом, если каскад поддерживает; для GLM-5-turbo и Anthropic Sonnet 4.6 — оба поддерживают stream API, у Anthropic дополнительно есть `thinking` content blocks с reasoning-токенами).
- Обновить `chats_frontend.md` §«Типы событий» — добавить `text_delta` с описанием payload + пример SSE-фрейма.
- Сохранить `final_message` как «canonical complete content» (фронт после сетл-а всё равно делает `fetchChat` для надёжности — это safety net против потерянных дельт на reconnect'е).

### Файлы (итого)

| Файл | Что |
|---|---|
| `front/src/components/chat/ChatMessageList.vue` | Удалён `<ChatTypingIndicator v-if="isSending" />` и неиспользуемый import. Prop `isSending` оставлен (используется в watcher'е авто-скролла). |

### Проверки

- `npm run type-check` — чисто.
- i18n — без правок (новых строк нет).
- Никаких упоминаний AI / Claude / 🤖 в коде.

### Что НЕ делалось

- **Не правился backend** — посимвольный стриминг требует backend-изменений (новый event-тип + Prism stream API wiring). Флагуется `chat-ai-engineer`'у.
- **Не вызывался qa-tester** — пользователь сказал «сам проверю».
- **Не делался commit.**

---

## 2026-05-20 — AI-чат iteration 3: thinking timeline + text streaming

**Контекст.** На бэке `chat-ai-engineer` ввёл новый event-тип `text_delta` (см. `chats_frontend.md` §`text_delta`). Z.AI GLM (текущий дефолтный провайдер) делает buffered-fallback — один `text_delta` с полным текстом сразу; Anthropic Claude — настоящий typewriter. Контракт-фронт идентичен для обоих режимов. Задача: визуализировать (а) пошаговый процесс AI как в Claude/ChatGPT webui («thinking-block» с tool-вызовами), (б) инкрементальный стрим content из `text_delta`, (в) blinking-каретку во время живой генерации.

### Архитектура изменений

| Файл | Что |
|---|---|
| `front/src/api/types/chats.ts` | В `ChatMessageEventType` добавлен `'text_delta'`. Новый тип `ChatTextDeltaPayload = { delta: string, kind: 'content'\|'thinking' }`. |
| `front/src/entities/chat/types.ts` | В `ChatMessage` добавлены runtime-only поля `timelineEvents?: ChatMessageEventDto[]` (накопленные SSE-события для рендера thinking-блока) и `thinkingContent?: string \| null` (аккумулятор `text_delta` с `kind=thinking`). Оба не сериализуются на backend, не идут через mappers. |
| `front/src/components/chat/composables/useChatStream.ts` | `text_delta` добавлен в `TYPED_EVENT_TYPES`. Поведение reader'а не изменилось — событие проходит дальше через `pushEvent` / `onEvent`. |
| `front/src/components/chat/composables/useChatMessaging.ts` | Расширен `subscribeToAssistantStream.onEvent`: <br>1. `appendTimelineEvent` — пушит event с whitelist-типом в `message.timelineEvents` через `replaceMessageById` (с dedup'ом по `sequence`). <br>2. `text_delta` → `applyTextDelta`: `kind=content` аппендит дельту к `content`, `kind=thinking` — к `thinkingContent`. <br>3. `final_message` остаётся canonical — перезаписывает аккумулятор. <br>В `finalizeAssistantMessage` после settle делается snapshot per-message `{timelineEvents, thinkingContent}` ПЕРЕД заменой `currentChat` на `freshChat`, и затем re-attach к соответствующим сообщениям. Иначе `fetchChat()` после settle затёр бы накопленный timeline. |
| `front/src/components/chat/ChatThinkingTimeline.vue` (новый) | Collapsable блок над контентом bubble'а. Header: spinner + "Думаю…"/"Использую инструменты…"/"Готово"/"Ошибка" (по `status` и наличию незакрытого `tool_call`). Body (раскрыт во время `pending/running`, авто-сворачивается на settle): <ul>(а) timeline-список: пары `tool_call`/`tool_result` сворачиваются в одну строку (spinner→✓), с локализованными именами инструментов (`probe_data`→«Изучаю данные», `create_report`→«Создаю отчёт», `update_report`→«Обновляю отчёт») и иконками PrimeIcons; `dry_run_start`/`dry_run_result` тоже парят; `started`/`retry`/`final_message` — отдельные строки; <br>(б) если есть `thinkingContent` — collapsable `<details>` «Размышления» с pre-форматированным reasoning-текстом.</ul> |
| `front/src/components/chat/ChatMessageBubble.vue` | <ul>1. Над content вставлен `<ChatThinkingTimeline :events :status :thinking-content>`. <br>2. `isAwaitingContent` заменён на: `isInFlight` (running/pending), `hasRenderableContent` (content !== '' && string), `showBareTyping` (in-flight + no content + no events — дни первого ивента), `isStreamingContent` (in-flight + renderable content — каретка), `showTime` (скрыть time во время in-flight). <br>3. Блок `bubble-caret` — `<span class="bubble-caret">` в собственном `.bubble-caret-row` после markdown-html (избегаем `<span v-html>` который оборачивал бы block-level элементы). CSS animation `bubble-caret-blink` 1s steps(2, start) infinite — стандартный typewriter-курсор.</ul> |
| `front/src/components/chat/locale/{ru,en}.json` | Добавлена ветка `thinkingTimeline.*` (header lines, step names, tool names, plural row count). Полностью симметрично RU↔EN (26 ключей в каждом). |

### Реактивный data-flow

```
SSE-event arrives
  ↓
useChatStream.consumeStream → pushEvent
  ↓
useChatMessaging.onEvent
  ↓ (если type ∈ TIMELINE_EVENT_TYPES)
appendTimelineEvent → replaceMessageById({ timelineEvents: [...] })
  ↓ (если type === 'text_delta')
applyTextDelta → replaceMessageById({ content: oldContent + delta })  // kind=content
                 либо     { thinkingContent: ... }                     // kind=thinking
  ↓
Vue реактивность → ChatMessageBubble перерендеривает (timeline + content + caret)
```

`replaceMessageById` пересоздаёт `chat.messages` массив поэлементно (immutable update) — Vue triggers re-render. ChatThinkingTimeline получает новые `events` props и пересчитывает `timelineItems` computed (pairing tool_call/tool_result).

### Edge-cases учтены

- **Buffered fallback (Z.AI GLM).** Бэк эмитит один `text_delta` с полным текстом — фронт работает идентично, просто визуально без typewriter (одной операцией content set, каретка успевает мигнуть до final_message). Anthropic — настоящий стрим символов.
- **Reload-restore.** При reload `useChatMessaging.resumeActiveStream` зовёт `stream.start({resumeFromBeginning: true})`, который через `replayBatchEvents` подгрузит все накопленные события из `/messages/{id}/events`. Каждый event пройдёт через тот же `onEvent` → timeline восстанавливается. Для terminal-сообщений с `events_count > 0` batch replay сейчас НЕ запускается (только при активном streamе) — это соответствует spec'у «опционально». Если потребуется отображать timeline для старых завершённых сообщений после reload — добавится отдельный path в `useChatQueries.loadChat`.
- **Snapshot before fetchChat.** `finalizeAssistantMessage` снимает `timelineEvents` + `thinkingContent` всех assistant-сообщений и re-attach'ит после `freshChat` — иначе они потерялись бы при `currentChat.value = freshChat`. Critical path.
- **Caret in markdown.** Каретка — отдельный sibling-блок ПОСЛЕ markdown-html (не внутри span), чтобы не нарушать DOM-структуру при block-level элементах. Trade-off: каретка визуально в новой строке после последнего параграфа, не «прилеплена» к последнему символу. Это OK для UX (как в Claude/ChatGPT).
- **Time hidden during stream.** Time-stamp не показывается пока сообщение `running` — иначе пользователь видит «11:32» рядом со спиннером. После settle время появляется.
- **Header tone switching.** "Думаю…" → "Использую инструменты…" автоматически переключается когда есть unclosed `tool_call`. Реализовано через look-back по `events` (последний `tool_call` без последующего `tool_result`).

### Что НЕ менялось

- Backend / контракт SSE — только consumed.
- `useChatStream` reader — только TYPED_EVENT_TYPES whitelist расширен.
- Mappers (`entities/chat/mappers.ts`) — runtime-only поля не сериализуются.
- `qa-tester` не вызывался — пользователь сам проверит.
- Никакого commit'а.

### Проверки

- `npm run type-check` — чисто.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts`, не наш файл).
- i18n RU↔EN symmetry — 26/26 ключей, diff пуст.
- Никаких упоминаний AI / Claude / 🤖 в коде и комментариях.

---

## 2026-05-20 — GenerateReportTile визуальный полиш (итерация 2)

`front/src/components/cards/GenerateReportTile/GenerateReportTile.vue`: фон `.tile-dashed` `$surface-100` → `$surface-50` (светлее), dashed border `$surface-400` → `$surface-200` (мягче, 3px сохранили), `.tile-label` font-size `$font-size-sm` → `$font-size-md` (соответствие `.report-card .card-title`). Цвет лейбла `$surface-600` без изменений. `type-check` чисто, `lint` чисто (warning в `plugins/persist.ts` — пре-существующий, не наш файл).

---

## 2026-05-20 — robots noindex meta-тег (пункт 4 DEVELOPMENT_PLAN_CAPITALDATA)

**Задача:** закрыть Vizion от поисковой индексации (corporate internal tool).

**Изменения:**

| Файл | Что меняем | Статус |
|---|---|---|
| `front/index.html` | После `<meta name="viewport">` добавлена строка `<meta name="robots" content="noindex, nofollow" />` | Готово |

**Diff:**

```html
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
+<meta name="robots" content="noindex, nofollow" />
<meta
  http-equiv="Content-Security-Policy"
```

**Проверки:**

- `npm run type-check` — чисто (HTML-изменение не затрагивает TS, но проверено).
- Никаких других изменений в файле.

**Визуальная проверка пользователем:** F12 → Elements → в `<head>` должен присутствовать `<meta name="robots" content="noindex, nofollow">` сразу после `<meta name="viewport">`. После полной production-сборки также можно проверить `curl -s https://vizion.lazarewww.ru/ | grep robots`.

---

## 2026-05-20 — Мини-чат-виджет в Toolbox (пункт 6 DEVELOPMENT_PLAN_CAPITALDATA.md)

**Задача:** иконка чата в Toolbox открывает overlay-виджет с чатом. На странице отчёта первое сообщение нового чата получает префикс с конфигом отчёта (auto-context). Кнопка `↗` открывает полноэкранный `/ai-reports?activate={id}` в новой вкладке. `viewer` иконку не видит.

**Решения по развилкам:**
- **reportContext через Pinia store**, не provide/inject (открытый вопрос #7 плана). Reactive, без прокидывания через DefaultLayout, минимум 5-10 строк правок в ReportPage.
- **Один экземпляр useChat() внутри виджета** — параллельный с AiReportsPage / AiChatPage; pinia chatsStore разделяемый. Стрим-логика и подключение к SSE — через существующий `useChatStream` (не дублируется).
- **Логика «какой чат открыть»:** на `/reports/{id}` всегда создаём свежий `report_generation` (старые чаты не имеют надёжной привязки к reportId до первого AI-турна). Вне отчёта — продолжаем последний `quick_qa` или создаём новый.
- **Размер контекста:** при `JSON.stringify(config).length > 2000` — slim-projection (`primary_model` + `columns: [{field, type}]`) + `filters_applied`. Иначе полный config.
- **Cross-tab handoff (expand):** `window.open('/ai-reports?activate={id}', '_blank')` → AiReportsPage в setup переводит query-param в `history.state.activateChatId` и `router.replace` без `activate`. Дальше работает существующий механизм `consumeActivateChatIdFromRouterState` в `useChatPage`.

**Изменения:**

| Файл | Что меняем | Статус |
|---|---|---|
| `front/src/shared/auth/capabilities.ts` | Новая capability `canUseMiniChat(role)` — alias `canUseAi` (viewer исключён), отдельная для будущего тюнинга | Готово |
| `front/src/stores/reportContext.ts` | **Новый Pinia store** `useReportContextStore`: `{ reportId, title, config, filtersApplied, hasReportContext, set(), clear() }` | Готово |
| `front/src/components/Toolbox/types.ts` | `ToolboxOverlayName` += `'miniChat'` | Готово |
| `front/src/components/Toolbox/Toolbox.vue` | Импорт `MiniChatWidget` + `canUseMiniChat`; в `#actions` slot добавлен MiniChatWidget (перед CompanySwitcher); `overlayControls.miniChat`; `toggleToolboxCollapsed` теперь закрывает и miniChat | Готово |
| `front/src/components/Toolbox/locale/{ru,en}.json` | Ключ `miniChat` (tooltip Toolbox-кнопки) | Готово |
| `front/src/components/chat/MiniChatWidget.vue` | **Новый компонент**: Button + PrimeVue Popover (~400×500px) + `<ChatMessageList>` + `<ChatInput>`. Логика: `initializeChat()` (один раз на overlay open) → `fetchChats()` → `resolveTargetChat()`. `buildContextPrefix()` собирает префикс при первом сообщении. Кнопка `↗` → `window.open('/ai-reports?activate=' + id, '_blank')`. Использует `useChat()` + `useChatsStore` + `useReportContextStore` | Готово |
| `front/src/components/chat/locale/{ru,en}.json` | Ключи `miniChat.{title, tooltip, openInFullScreen, close, loading, empty, emptyOnReport, placeholder, placeholderOnReport, contextBadge, contextHint, errors.initFailed, common.error}` | Готово |
| `front/src/components/chat/index.ts` | Экспорт `MiniChatWidget` | Готово |
| `front/src/pages/ReportPage/index.vue` | Импорт `useReportContextStore` + `watch([report, currentFilters, locale], ...)` который `set` либо `clear`. `onBeforeUnmount` → `clear()`. Минимум — 11 строк правок | Готово |
| `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts` | `consumeActivateQueryParam()` синхронно в `setup()` — переводит `?activate=N` в `history.state.activateChatId` ДО `useCompanySelection.onMounted`, затем `router.replace` без query | Готово |

**Логика инжекта контекста (`buildContextPrefix` в MiniChatWidget):**

```
[Контекст отчёта: {title}]
Конфиг: {JSON.stringify(config)}                 ← или slim-projection при >2KB
Применённые фильтры: {JSON.stringify(filtersApplied)}
---
{сообщение пользователя}
```

Префикс инжектируется **только в первое user-сообщение нового чата на странице отчёта**. Флаг `pendingContextInject` сбрасывается перед `chat.sendMessage` (даже при network-error — чтобы retry не дублировал префикс).

**Проверки:**

- `npm run type-check` — passed.
- `npm run lint` — passed (только pre-existing warning в `plugins/persist.ts`, не от этой итерации).

**Визуальная проверка (для qa-tester):**

Happy path:
1. Залогиниться superadmin / admin / analyst → в Toolbox видна иконка чата `pi-comments` (около CompanySwitcher / ProfileMenu).
2. Залогиниться viewer → иконки нет.
3. Открыть `/reports/{id}` → клик по иконке чата в Toolbox → открывается overlay (~400×500). В header — title "AI Чат", кнопка `↗` + `✕`. Под header — `[Контекст: <название отчёта>]` (паперклип-иконка).
4. Ввести `Покажи топ-3 строки` → отправить. Первое сообщение в чате должно быть user-bubble с префиксом `[Контекст отчёта: ...]` + конфигом. AI должен ответить (стрим работает).
5. Кликнуть `↗` → новая вкладка `/ai-reports`. Чат должен открыться тот же. URL должен очиститься от `?activate=`.
6. Закрыть widget (✕), перейти на `/reports` (список) → открыть widget снова. Не создаётся новый отчётный чат — используется последний `quick_qa` чат либо создаётся пустой `quick_qa`. Контекст-плашки нет.
7. Открыть другой overlay (CompanySwitcher / ProfileMenu) при открытом miniChat — miniChat должен закрыться (singleton-overlay через useToolboxOverlays).

Edge cases:
- Свернуть Toolbox (`pi pi-bars`) при открытом widget → widget должен закрыться.
- Большой отчёт (config > 2KB) — префикс должен содержать slim-projection (`primary_model` + `columns`).
- Backend упал на `POST /api/chats` → toast `Не удалось открыть чат` (через notifyApiError).

---

## 2026-05-20 — Мультивалютность + таймзоны на уровне компании (пункт 3 DEVELOPMENT_PLAN_CAPITALDATA, §3.3.C-E)

**Задача:** прокинуть новые per-company поля `currency_code` (ISO 4217) и `timezone` (IANA) с бэка во фронт, и завести универсальное форматирование сумм/дат, реактивно завязанное на активную компанию. Backend-часть (миграция, модель, контроллер, сидеры) уже закрыта `backend-specialist`'ом — фронт получает поля в `GET /api/user → active_company` и `GET /api/companies/{id}`.

**Решения по развилкам:**

- **`Intl.NumberFormat` style currency** + `currencyDisplay: 'narrowSymbol'` (`maximumFractionDigits: 0`) — компактный символ ₸/$/€ не ломает ширину колонок таблицы (риск из §3.6 закрыт).
- **Локаль для Intl** — отдельный mapper `toBcp47`: `'ru' → 'ru-RU'`, `'en' → 'en-US'`. Если кто-то передаст `'en-GB'` — pass-through. Хранится локально в файле, не вынесен в утилиту.
- **Реактивность** — через `useCompaniesStore().getCurrentCompany` (Pinia getter) внутри resolver-функций. При `switchActiveCompany` getter перевычисляется → computed-цепочки в `useReportPresentation` (`formattedTableData`, `formattedTotalsRow`, `footerCells`) пере-рендерятся автоматически.
- **Fallback на RUB и UTC** — если `currency_code === null` (системная Vizion-компания) или store пуст → `'RUB'`. Аналогично timezone → `'UTC'`. Никакой плашки «нет валюты» — рендер всегда даст осмысленный результат.
- **Не создавал отдельную `useCurrencyFormatter` / `useDateFormatter`** — расширил существующий `useFormatter` чтобы не плодить API. Все три callsite'а (`useReportPresentation`, `ReportPage/index.vue`, `PaymentScheduleCell.vue`) уже идут через `format(value, { type: 'money' | 'date' | 'datetime' })` — поведение поменялось внутри, callers не правились.
- **Старый `format()` API сохранён** — `FormatOptions.currency` теперь принимает `string | null` (раньше `string`), `timezone` добавлен. Никаких breaking changes для существующих вызовов.

**Фактические изменения:**

| Файл | Что сделано |
|---|---|
| `front/src/entities/company/types.ts` | `Company` получил поля `currency_code: string \| null`, `timezone: string \| null` (рядом с `is_system`, перед `crm_url`). |
| `front/src/api/types/companies.ts` | `CompanyDto` — те же два поля (required, nullable, для строгой синхронизации с API-ответом). `CreateCompanyRequest` — те же поля как `?string \| null` (optional на отправке, бэк проставит дефолты). `UpdateCompanyRequest = Partial<CreateCompanyRequest>` — обновляется автоматически. |
| `front/src/entities/company/mappers.ts` | Не менялся — `mapCompanyDtoToCompany` уже passthrough `{ ...companyDto }`, новые поля пробрасываются автоматически. |
| `front/src/composables/useFormatter.ts` | Расширен: импортирован `useI18n` и `useCompaniesStore`. Добавлены resolvers `resolveLocale` (BCP-47 mapper, читает vue-i18n locale), `resolveCurrency` (читает `getCurrentCompany.currency_code`, fallback `'RUB'`), `resolveTimezone` (читает `getCurrentCompany.timezone`, fallback `'UTC'`). Новые функции **`formatCurrency(value, { currency?, locale? })`** и **`formatDate(value, { timezone?, withTime?, locale? })`** в return. `formatNumber` для `type: 'money'` теперь делегирует в `formatCurrency` → ячейки `currency`-колонок выводятся с символом валюты активной компании. Старый `formatDate` (internal, по типу `date`/`datetime`) делегирует в новый `formatDate` с `withTime = (type === 'datetime')`. Глобальные опции `format(...)` расширены: `FormatOptions += { currency?: string \| null, timezone?: string \| null }`. try/catch обёртки внутри `formatCurrency` (unknown ISO code → degrade без символа) и `formatDate` (invalid IANA tz → retry с UTC) — защита от мусора в `companies.currency_code` / `companies.timezone`. |
| `front/src/mocks/data.ts` | `mockCompany` дополнен полями `currency_code: 'RUB'`, `timezone: 'Europe/Moscow'` — иначе TS падал на `CompanyDto`-cast (новые поля required). |

**Места рендера, которые поменяли поведение без правок собственного кода:**

- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `formattedTableData`, `formattedTotalsRow`, `footerCells`, `formatGroupChildren`, `formatAggregate` — все идут через `format(value, { type: column.type })`. При `column.type === 'currency'` → `resolveColumnType` → `FormatType 'money'` → `formatNumber` → теперь `formatCurrency` с currency_code активной компании.
- `front/src/pages/ReportPage/index.vue` — `format(cell.paidTotal, { type: 'money' })` и `format(cell.dueTotal, { type: 'money' })` в footer payment-schedule колонки.
- `front/src/pages/ReportPage/components/PaymentScheduleCell.vue` — `format(amount, { type: 'money' })` в `formatAmount()`.

**Не трогал (вне скоупа пункта 3):**

- `ChatMessageBubble.vue` строка 86 — `Intl.DateTimeFormat(locale.value, { hour, minute })` для time-stamp сообщения. Это AI-чат (зона `chat-ai-engineer`), задача явно сказала не лезть.
- Все остальные `.toLocaleString` callsite'ы — только внутри `useFormatter.ts` для типов `area`, `percent`, `number` (там currency не применимо).
- Никаких хардкодов `₽` / `руб` / `KZT` нигде в коде не обнаружено — поиск пуст.

**Реактивность — критическая проверка:**

`useFormatter()` вызывается в трёх местах (все — setup / composable scope, не lazy-import'ируется и не вызывается из вне реактивного контекста). Все три используют возвращённый `format` внутри `computed(...)` (`useReportPresentation`) или прямо в template (`ReportPage/index.vue`, `PaymentScheduleCell.vue`). При `companies.switchActiveCompany(...)` → `companiesStore.activeCompanyId` меняется → getter `getCurrentCompany` перевычисляется → `resolveCurrency` / `resolveTimezone` вернут новое значение → Vue inval'ит computed → таблица перерисуется с новым символом валюты и таймзоной.

При смене локали (`useLocaleManager.setLocale('en')`) — `i18nLocale` (Ref) меняется → `resolveLocale` через мой mapper переключится с `ru-RU` на `en-US`. `Intl.NumberFormat('en-US', { currency: 'KZT' })` даст `KZT 1,000`, тогда как `ru-RU` даст `1 000 ₸`. Это правильно — пользователь EN видит привычный формат.

**Проверки:**

- `npm run type-check` — 0 ошибок (исправил один TS2739 в `mocks/data.ts` после расширения `CompanyDto`).
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91:52` — не наш файл, известно).
- i18n RU↔EN — изменения не задели локали; новых ключей не вводилось (formatCurrency/formatDate — pure JS API без переводимых строк).
- Никаких упоминаний AI / Claude / 🤖 в коде и комментариях.

**Не реализовано в этой итерации (вне scope §3.3.C-E):**

- UI редактирования `currency_code` и `timezone` в `CompanyFormModal.vue` / `CompanySettingsSection.vue` — backend через `CompanyController::store/update` уже принимает поля, но UI на это нет. Backlog для отдельной итерации, если потребуется (сейчас значения проставляются сидерами).
- Подсветка несовместимости timezone-MacroData (если данные приходят в локальном времени БД, а не UTC) — это не задача фронта, она решается на бэке в `ReportDataService`.

**Что вернуть QA (когда дойдёт черёд):**

- Логинимся как клиентская компания с `currency_code = 'KZT'`, `timezone = 'Asia/Almaty'` (один из сидеров — CapitalInvest по плану). Открываем «Реестр договоров» / «Финансы» — суммы должны быть с символом `₸`, даты — в алматинском времени (UTC+5/+6). Переключаем компанию на другую (другой currency_code) — таблица перерисовывается без перезагрузки.
- На системной Vizion-компании (`currency_code = null`) суммы должны быть с `₽` (RUB-fallback) — это допустимо, т.к. user системной компании отчётов не открывает.
- Switch локали RU↔EN — формат чисел/дат меняется (русский: `1 000 ₸ · 31.12.2025`; английский: `KZT 1,000 · 12/31/2025`).

---

## 2026-05-20 — Дашборды как вид отчёта (пункт 1 DEVELOPMENT_PLAN_CAPITALDATA)

**Задача:** на странице отчёта добавить переключатель «Таблица / Дашборд». В режиме Dashboard — виджеты поверх Chart.js 4, layout через `grid-layout-plus`, состояние per user × per report в localStorage. Виджеты строятся из `report.config.dashboard_widgets[]`; backend-эндпоинт `GET /api/reports/{id}/dashboard-data` уже готов (см. `FRONTEND.md` §dashboard-data).

**Решения по развилкам:**

- **Toggle компонент** — PrimeVue `<SelectButton>` с `allow-empty=false` (sticky выбор, нельзя «снять» оба). Лейблы локализованы. Лежит в `header-right` ПЕРЕД `TabList` чтобы порядок чтения был «вид → подвид».
- **Persist view-mode** — отдельный composable `useReportViewMode.ts`, ключ `vizion-report-view-{reportId}-{userId}`, дефолт `'table'`. НЕ в Pinia: выбор устройство-специфичный (как `localStorage`-флаги PrimeVue DataTable), переживает logout и не должен сериализоваться в session снапшоты.
- **Persist layout** — отдельный composable `useDashboardLayout.ts`, ключ `vizion-dashboard-layout-{reportId}-{userId}`. Хранится `{ layout: DashboardLayoutItem[], hiddenWidgets: string[] }`. При появлении новых виджетов (AI добавил после reload) — reconcile: новые приклеиваются снизу, удалённые (нет в `report.config.dashboard_widgets[].id`) — отбрасываются. Это важно: пользовательский layout не должен теряться когда AI добавляет/удаляет виджет.
- **Lazy fetch dashboard-data** — composable `useReportDashboard.ts` принимает `enabled: Ref<boolean>` (=`isDashboard`). Пока пользователь сидит в Table mode — запрос НЕ идёт. При переключении или смене фильтра/`reportId` — refetch. Это спасает большие отчёты (5000 строк) от лишних round-trip'ов.
- **Aggregation на фронте** — utility `aggregateWidgetData(rows, widget)` группирует `rows` по `label_field`, агрегирует `value_field` стратегией `sum|count|avg|max|min`. Order labels = order первого появления в `rows` (т.е. backend's `config.sort.default` сохраняется). `count` игнорирует `value_field` (считает строки на label). NULL labels → `'—'`, NULL values отфильтровываются.
- **Палитра чартов** — PrimeVue Aura-inspired 10 цветов inline в `ReportDashboardWidget.vue` (`PALETTE`). Категориальные (pie/doughnut) — по цвету на slice. bar/line — один цвет (первый из палитры). НЕ выносил в `theme/` — пока только один callsite; перенесём когда появится второй.
- **Capability** — добавил `canManageDashboardLayout(role)` в `shared/auth/capabilities.ts`, mirrors `AI_ROLES` (superadmin/admin/analyst). Viewer **видит** дашборд, **не может** drag/resize/hide/reset. Acceptance §F.
- **Empty state vs «все скрыты» vs «нет виджетов»** — три разных state в `ReportDashboardView`: (1) `dashboardWidgets.length === 0` → плашка «нет виджетов, попросите AI» с иконкой `pi-comments`; (2) `visibleWidgets.length === 0` → плашка «все скрыты, включите в меню» с `pi-eye-slash`; (3) ошибка → плашка с retry-кнопкой.
- **Banner о лимите** — PrimeVue `<Message severity="warn">` НАД grid'ом если `meta.dashboard_limited === true`. Текст разный в зависимости от наличия `dashboard_total_estimate` (с числом или без).
- **GridLayout config** — 12-колоночная сетка, `row-height=60`, виджеты по дефолту 6×4 (=2 в ряд на десктопе). `vertical-compact=true`, `use-css-transforms=true` (плавный drag). `drag-ignore-from=".p-button, .p-chart, canvas, .dashboard-widget__actions"` — иначе drag триггерится при клике на кнопку «скрыть» или на chart-canvas.
- **MultiSelect виджетов** — `:max-selected-labels="0"` чтобы не показывать chip'ы (только счётчик), компактнее в шапке. Только для analyst+ (`canCustomize`).

**Установленная зависимость:**

`grid-layout-plus@^1.1.1` — актуальный Vue 3 форк `vue-grid-layout`. Самоинъектит свой CSS при импорте (через `vite-plugin-css-injected-by-js`), отдельный SCSS-импорт в `main.ts` НЕ нужен — проверил `node_modules/grid-layout-plus/es/index.mjs` (первая строка — `(function(){...document.createElement('style')...})()`).

**Фактические изменения:**

| Файл | Что сделано |
|---|---|
| `front/package.json` | Добавлена зависимость `"grid-layout-plus": "^1.1.1"` (в `dependencies`, рядом с `decimal.js`). |
| `front/src/api/types/reports.ts` | Новые DTO: `DashboardWidgetChartType` (bar/line/pie/doughnut), `DashboardWidgetAggregation` (sum/count/avg/max/min), `DashboardWidgetDto` (id, title, chart_type, value_field, label_field, aggregation, widget_group?), `DashboardDataMetaDto` (dashboard_limited?, dashboard_limit?, dashboard_total_estimate?), `DashboardDataResponseDto` ({columns, rows, meta, filters_applied}), `FetchDashboardDataOptions` ({filters?}). `ReportConfigDto.dashboard_widgets?: DashboardWidgetDto[] \| null` (аддитивно, nullable). |
| `front/src/api/types/index.ts` | Реэкспорт всех новых DTO + `ReportTableCellDto`, `ReportTableRowDto` (были нужны во вьюшке, не были в барреле). |
| `front/src/api/reports.ts` | Извлёк хелпер `appendFiltersToParams(params, filters)` (одинаковое filter-encoding в трёх местах — fetchReport / fetchGroupRows / нового fetchDashboardData). Добавил метод `fetchDashboardData(reportId, options?)` → `GET /api/reports/{id}/dashboard-data?filters[...]=...`. |
| `front/src/services/ReportService.ts` | Метод `fetchDashboardData(id, options?)` → возвращает **сырой `DashboardDataResponseDto`** (а не `ReportItem`-проекцию). Обоснование: Dashboard view потребляет flat-shape напрямую — нет chart, totals, group_by. Маппер `mapReportDtoToReport` для пагинированного `GET /reports/{id}` неприменим. |
| `front/src/pages/ReportPage/composables/useReportViewMode.ts` | **Новый composable**: `mode: Ref<'table'\|'dashboard'>`, `isTable`, `isDashboard`, `setMode`. Persisted в `localStorage[vizion-report-view-{reportId}-{userId}]`. Дефолт `'table'`. На смену `reportId` — re-hydrate из новой записи. Защита от private-browsing (try/catch на read/write). |
| `front/src/pages/ReportPage/composables/useReportDashboard.ts` | **Новый composable**: `rows`, `columns`, `meta`, `isLoading`, `error`, `hasLoaded`, `refetch`. Trigger watch на `[reportId, filters, enabled]` — fetch только когда `enabled.value===true`. На смену `reportId` сбрасывает `data` (чтобы не утекали данные между отчётами). `useAsyncResource` для loading-state. |
| `front/src/pages/ReportPage/composables/useDashboardLayout.ts` | **Новый composable**: `layout`, `visibleLayout`, `visibleWidgets`, `hiddenWidgets`, `updateLayout`, `toggleWidgetVisibility`, `setVisibleWidgetIds`, `resetLayout`. Persist в `localStorage[vizion-dashboard-layout-{reportId}-{userId}]`. `autoLayoutFor(ids)` — дефолтный 2-up flow (6×4 на 12-col grid). `reconcileLayout(existing, ids)` — мёрджит новые виджеты снизу, прунит удалённые, сохраняет пользовательские позиции. Прунит `hiddenWidgets` от устаревших id. |
| `front/src/pages/ReportPage/composables/useDashboardWidgetData.ts` | **Новый utility**: `aggregateWidgetData(rows, widget): {labels, values}`. Tolerant `toNumber` для строк типа `"1500000.00"` (postgres NUMERIC), `toLabel` для NULL/object/scalar. Aggregations: sum/count/avg/max/min. |
| `front/src/pages/ReportPage/components/ReportDashboardWidget.vue` | **Новый компонент**: `<Card>` с `<Chart>` (PrimeVue → Chart.js 4). Inline-палитра 10 цветов. Categorical (pie/doughnut) — colors per slice + legend bottom. bar/line — single colour, x/y scales включены. Header с opacity-on-hover кнопкой «скрыть» (только если `canHide`). Empty-state «нет данных» с `pi-chart-bar`. |
| `front/src/pages/ReportPage/components/ReportDashboardView.vue` | **Новый компонент**: оборачивает GridLayout/GridItem из `grid-layout-plus`. MultiSelect виджетов + кнопка «Сбросить» (только для `canCustomize`). `<Message severity="warn">`-banner если `meta.dashboard_limited`. 4 state'а body: error (с retry) / no-widgets / loading / all-hidden / grid. `drag-ignore-from` на `.p-button, .p-chart, canvas, .dashboard-widget__actions`. |
| `front/src/pages/ReportPage/index.vue` | Импорт `SelectButton`, `useUserStore`, `canManageDashboardLayout`, новых composables, `ReportDashboardView`, `DashboardWidgetDto`. SelectButton добавлен в `header-right` перед `TabList`. `TabList` обёрнут в `v-if="!isDashboard"`. `Paginator` показывается только в Table mode. `TabPanels` обёрнут в `v-if="!isDashboard"`, рядом — `<div v-else class="dashboard-section">` с `<ReportDashboardView>`. SCSS — добавил `.report-view-toggle`, `.dashboard-section` (mirrors `.data-section`). |
| `front/src/pages/ReportPage/locale/{ru,en}.json` | Новые ключи: `viewMode.{tableLabel, dashboardLabel, ariaLabel}`, `dashboard.toolbar.{widgetsPlaceholder, widgetsSelected, widgetsHeader, reset, resetTooltip}`, `dashboard.banner.{limited, limitedWithTotal}`, `dashboard.body.{loading, error, retry, noWidgets, noWidgetsHint, allHidden}`, `dashboard.widget.{hide, empty}`. Симметрия RU↔EN. |
| `front/src/shared/auth/capabilities.ts` | Новая capability `canManageDashboardLayout(role)` — analyst+. |

**Реактивность — критические проверки:**

1. **Переключение режима table → dashboard:** `viewMode.value = 'dashboard'` → `isDashboard.value = true` → watcher в `useReportDashboard` срабатывает на `enabled` смену → `refetch()` → grid рендерится. При обратном table → dashboard — fetch НЕ дублируется (watcher проверяет `oldId !== id` для reset; в одной reportId — переиспользует кэш если был).
2. **Смена фильтра в Dashboard mode:** `applyFilters()` в `useReportPageActions` пишет в `currentFilters` → `useReportDashboard.watch([reportId, filters, enabled])` срабатывает (`deep: true`) → refetch. Стейт layout не сбрасывается — это правильно: фильтр меняет данные внутри виджетов, не их состав.
3. **Смена `reportId` (нав в другой отчёт без unmount):** `route.params.id` меняется в `useReportPageData` → `report.value` обновляется → `useReportViewMode.storageKey` пересчитывается → mode re-hydrated. `useDashboardLayout.storageKey` пересчитывается → layout/hidden hydrated из нового ключа. `useReportDashboard.reset(null)` + refetch если `isDashboard`.
4. **AI добавил новый виджет:** пользователь возвращается на страницу отчёта → fetchReport → `report.config.dashboard_widgets[]` обновляется → `dashboardWidgets` computed → `useDashboardLayout.watch(widgetIds)` → `reconcileLayout`: новый виджет добавляется в layout снизу, ничего из существующего не двигается. Пользовательский layout сохранён.
5. **Drag widget:** `<GridLayout @update:layout="onLayoutUpdate">` → `dashboardLayout.updateLayout(cleaned)` → ref `layout` обновляется → watcher persist → localStorage write. После reload — layout восстанавливается из ключа `vizion-dashboard-layout-{id}-{userId}`.

**Контракт с эталонами (CLAUDE.md «эталоны»):**

- Page-фолдер `pages/ReportPage/` уже имеет `composables/` и `components/` папки — новые файлы вписываются в существующую парадигму (использовано как эталон).
- Composables Single-Responsibility: `useReportViewMode` (toggle persist), `useReportDashboard` (data fetching), `useDashboardLayout` (layout state), `useDashboardWidgetData` (pure aggregation utility). Не свёрнуты в один большой — каждый можно переиспользовать отдельно.
- DTO-first: новые типы в `api/types/reports.ts`, реэкспорт через barrel `api/types/index.ts`.

**Проверки:**

- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91:52` — не наш файл, известно).
- `npm run build` — успешно (только sass-warning на `$shadow-sm` фиксил → переключил на `$shadow-md`, других косяков нет). `dist/assets/primevue-D3Ual5f6.js` 948 KB, `dist/assets/charts-CquwA8NN.js` 207 KB — chunk-warning'и existing, не от этой итерации.
- i18n RU↔EN — симметрия ключей соблюдена, проверил вручную.
- Никаких упоминаний AI / Claude / 🤖 в коде и комментариях.
- Контракт `chart.js` 4 + PrimeVue `<Chart>` — без миграции, без vue-echarts/ApexCharts.

**Новые localStorage ключи:**

- `vizion-report-view-{reportId}-{userId}` — значения `'table' \| 'dashboard'`. Дефолт `'table'`.
- `vizion-dashboard-layout-{reportId}-{userId}` — JSON `{ layout: [{i, x, y, w, h}], hiddenWidgets: string[] }`. Дефолт — auto-layout 2-up.

**Edge cases / потенциальные риски:**

1. **`config.dashboard_widgets` с дублированными `id`** — backend гарантирует unique, но AI может ошибиться. У нас `Map` в `widgetById` — дубль перетрёт первый. Если станет проблемой, можно добавить warning в console при reconcile.
2. **Виджет с несуществующим `value_field` / `label_field`** — `aggregateWidgetData` пропустит NULL values, label станет `'—'`. Покажет «пустой» виджет с empty-state. Это OK fail-soft, но в QA надо проверить чтобы AI не генерировал виджеты с полями, которых нет в `columns[]`.
3. **GridLayout + scroll-контейнер** — `.report-dashboard__body` имеет `overflow-y: auto`. Drag-touch может конфликтовать с scroll на мобильном. Не критично сейчас, но в QA на мобиле проверить.
4. **Reset layout пока виджеты скрыты** — `resetLayout()` обнуляет `hiddenWidgets`. Это правильное поведение (reset = всё видно), но пользователь может потерять «скрыл специально для долгого использования». В QA — убедиться что текст кнопки «Сбросить вид» (RU: «Сбросить вид») достаточно ясный.
5. **Большой `report.config` (Capital Invest имеет ~50+ полей)** — `report.config` сохраняется в `localStorage`-ничего, виджеты живут в `localStorage` только по id. Это нормально, не разраздуется.
6. **MultiSelect когда виджетов 0** — компонент скрыт через `v-if="canCustomize && hasWidgets"`. OK.
7. **Реактивность viewMode на новом userId (logout/login other user без reload)** — `storageKey` через `userStore.currentUser?.id`. После login другим юзером — storageKey пересчитывается → mode re-hydrated. Тестировать в QA.

**Acceptance критерии для qa-tester (зеркалит §1.6 плана):**

Happy path:
1. Залогиниться superadmin / analyst → открыть `/reports/{id}` → в шапке справа виден SelectButton `[Таблица|Дашборд]`.
2. Default = Table → данные таблицы как раньше, ничего не сломалось (DataTable + filters + pagination + chart-tab).
3. Клик на «Дашборд» → tabs+pagination скрываются → если у отчёта нет `dashboard_widgets` (как у всех 6 системных) — empty-state «На этом отчёте пока нет виджетов» + иконка `pi-comments`.
4. Через AI-чат: добавить виджет в отчёт (например «добавь bar-виджет по deal_status для finances»). Перейти на отчёт → переключить в Dashboard → виджет появился. После reload страницы → виджет на том же месте.
5. Drag widget мышью → отпустить → reload → позиция сохранилась.
6. Открыть MultiSelect «Виджеты» → снять одну галку → виджет скрылся → reload → виджет всё ещё скрыт.
7. Клик «Сбросить вид» → layout вернулся к 2-up flow, все виджеты видны.
8. Сменить фильтр (например `deal_status=150`) → виджеты пересчитались (новый API-запрос → новые цифры).
9. Toggle обратно «Таблица» → table view как раньше. Reload → toggle остался на Table.
10. Залогиниться **viewer** → toggle виден, можно переключиться в Dashboard. НО: нет MultiSelect виджетов, нет «Сбросить», нет drag (тайлы не двигаются), нет кнопки «Скрыть виджет» на hover. Виджеты отображаются read-only.

Edge cases:
- Отчёт с >5000 строк → виден `<Message warn>` баннер «Показаны первые 5000 строк…».
- Backend упал на `/dashboard-data` → error-state «Не удалось загрузить» + кнопка «Повторить».
- Свич локали RU↔EN → лейблы виджетов, кнопок, баннеров перевелись.
- Свич компании (analyst переключился) → если новой компании у юзера тоже есть тот же отчёт по id (теоретически — system-отчёт) → новый layout-ключ → дефолтный auto-layout. Не критично, просто другой контур.


## 2026-05-20 — Группировка колонок и виджетов (пункт 2 DEVELOPMENT_PLAN_CAPITALDATA §2.3)

### Контекст
Backend готов (AI принимает `columns[].column_group` и `dashboard_widgets[].widget_group`, обе jsonb pass-through, `column_group` несовместим с `group_by` — backend rejects). Задача — реализовать на фронте collapsible группировку колонок таблицы + виджетов дашборда + кнопки управления, и попутно починить P1 UX-баг с Toolbox, который перехватывал нативные клики по SelectButton.

### Новые / изменённые файлы
- `front/src/api/types/reports.ts` — добавлено опциональное поле `ReportColumnDto.column_group?: string | null` с JSDoc о mutual exclusivity с `group_by`.
- `front/src/pages/ReportPage/composables/useColumnGroups.ts` *(новый)* — composable: builds groups (contiguous runs by `column_group`) + `ungrouped` + `visibleColumns` computed, persists `hiddenGroups: Set<string>` в `localStorage` под ключом `vizion-column-groups-{reportId}-{userId}`. Fail-safe для grouped (master/detail) отчётов: `hasGroups=false` если `isGroupedReport=true`. Prune stale labels watcher — если AI перегенерил конфиг и какой-то group label исчез, чистится из persisted set.
- `front/src/pages/ReportPage/composables/useWidgetGroups.ts` *(новый)* — аналогично для виджетов: `hiddenGroups` в `vizion-widget-groups-{reportId}-{userId}`. В отличие от column groups, non-contiguous occurrences одной метки склеиваются в одну bucket (виджеты не имеют visual-constraint contiguous как PrimeVue ColumnGroup).
- `front/src/pages/ReportPage/components/ColumnGroupsToggle.vue` *(новый)* — попап с чекбоксами в шапке отчёта. PrimeVue `<Popover>` (toggle-метод), кнопка-anchor с иконкой `pi-th-large` (для колонок) или `pi-objects-column` (для виджетов через prop `icon-class`). Внутри — список меток с PrimeVue Checkbox (binary) + кнопка «Сбросить» если есть скрытые. Эмиттит `toggle(label, visible)` + `reset`.
- `front/src/pages/ReportPage/index.vue` — подключение обоих composables, отдельная кнопка `ColumnGroupsToggle` в `header-right` слева от `filter-toggle-wrap` (видна только в `tableTab` режиме при `columnGroups.hasGroups`). В DataTable flat-режима добавлен двухуровневый header через PrimeVue `<ColumnGroup type="header">` + `<Row>` + `<Column>` с `:colspan` / `:rowspan=2`. Data-Column'ы `v-for` теперь по `columnGroups.visibleColumns.value` (полная стабильность keys через mapping `originalColIndexByField` для `linkRefs`). Sortable на data-Column отключается когда groups активны (sort переезжает на header-Column в ColumnGroup row 2 для grouped columns; ungrouped Column в row 1 с `:rowspan=2` остаётся sortable). Footer `visibleFooterCells` фильтруется аналогично, с переносом флага `isTotalsLabel` на первую видимую ячейку если оригинальная первая ячейка ушла.
- `front/src/pages/ReportPage/components/ReportDashboardView.vue` — добавлены props `widgetGroups` / `ungroupedWidgets` / `hiddenWidgetGroups`. Если `widgetGroups.length > 0` — рендерится новый блок `__groups` с `<section>`-ами вместо GridLayout (drag-and-drop отключён в grouped-режиме, потому что секции — visual authority; widgets в секции flow в config-порядке через `display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));`). Если групп нет — поведение прежнее (GridLayout с drag). Шапка дашборда получила второй `<ColumnGroupsToggle>` (повторно используем компонент, через `icon-class="pi pi-objects-column"`). Сводный CSS для секций: padding, header-кнопка с chevron, grid 2-up, mobile 1-up.
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `PresentationColumn` теперь экспортируется + добавлено опциональное поле `column_group?: string | null`, маппинг `tableColumns` пробрасывает его из `col.column_group ?? null`.
- `front/src/pages/ReportPage/locale/{en,ru}.json` — новые i18n-секции `columnGroups` (tooltip / header / reset) + `widgetGroups` (tooltip / header / reset / ungroupedLabel / collapse / expand). RU↔EN симметрично.

### Решение по PrimeVue ColumnGroup рендеру (важная UX-деталь)
PrimeVue 4 DataTable header — двухуровневый только через дочерний `<ColumnGroup type="header">` с `<Row>` блоками. Data-Column'ы рендерятся как раньше (привязка по `field`), но их `header` атрибут игнорируется визуально — header берётся из ColumnGroup. Sortable должен быть на header-Column'ах внутри ColumnGroup (это места, по которым пользователь кликает). Чтобы не дублировать sortable-логику, я выключаю sortable на data-Column'ах когда `hasGroups=true` (`v-bind="col.sortable && !columnGroups.hasGroups.value ? { sortable: true } : {}"`) и переношу `sortable` на header-Column'ы в ColumnGroup `<Row>`. Ungrouped колонки рендерятся как `:rowspan=2` Column в первом Row — занимают обе строки header'а — и сохраняют свою sortable там же.

Computed `groupHeaderCells` строит порядок ячеек первого Row: разбирает `visibleColumns` последовательно, склеивая contiguous runs одинакового `column_group` в `{kind: 'group', label, colspan: N}`, и пробрасывая ungrouped как `{kind: 'ungrouped', field, header, sortable, rowspan=2}`. `groupedSubHeaderCells` строит второй Row только из grouped columns. PrimeVue не требует ColumnGroup`<Row>`ы синхронизировать по `field` с data-Column'ами — связь по позиции.

### Fix Toolbox z-index / pointer-events (P1)
**Root cause:** `.toolbox` корневой div имел `pointer-events: auto`. При `display: flex; gap: 0.5rem` его bounding box включал не только видимый ToolboxToggle (~32-40px), но и absolute-позиционированный `.toolbox-panel`, который при `position: absolute; right: calc(100% - 0.5rem)` визуально торчит за пределы `.toolbox` влево на ~22rem (примерно ширина SelectButton + filter + всё что справа в шапке). При `.toolbox-panel.is-collapsed` сам panel получает `pointer-events: none` (это уже было сделано), но **flex-контейнер `.toolbox` всё равно резервирует hit-area** на пустой gap между toggle и невидимым panel — и эта area при viewport <1280px накладывается на header контролы (SelectButton). Native click hit-test находил `.toolbox` сверху → не доходил до SelectButton. JS-клик (`element.click()`) обходит hit-test → работал.

**Fix:** в `front/src/components/Toolbox/Toolbox.vue` поменял корневой `.toolbox` на `pointer-events: none` + `> * { pointer-events: auto }`. Теперь:
- Сам `.toolbox` div прозрачен для событий (pass-through контейнер).
- Дочерние ToolboxToggle и ToolboxPanel ловят события для своей реальной геометрии.
- ToolboxPanel при `is-collapsed` остаётся pointer-events: none (через свой собственный rule — уже было).
- Drag не сломан: `ToolboxToggle.vue__grip` эмитит `start-drag` через `emit()` (не bubbling), Toolbox.vue ловит `@start-drag="startDrag"`. На сам `.toolbox` корень `@pointerdown="startDrag"` остаётся (на случай прямого pointerdown на корень), но из-за pointer-events: none он теперь сработает только через bubbling от детей с pointer-events: auto — что валидно (event.target будет ToolboxToggle, bubbling до .toolbox произойдёт).

В коде комментарий с длинным объяснением root cause и почему drag не ломается.

### Совместимость
- Все 6 системных отчётов из `ReportSeeder.php` не имеют `column_group` ни в одной колонке → `columnGroups.hasGroups.value = false` → DataTable рендерится как раньше (без ColumnGroup, со старым flat header, sortable на data-Column'ах). Кнопка `ColumnGroupsToggle` не показывается.
- Grouped (master/detail) отчёты: `isGrouped=true` → composable возвращает `hasGroups=false` независимо от наличия `column_group` в колонках → фронт fail-safe рендерится flat (соответствует backend-policy «column_group несовместим с group_by»).
- Дашборд без `widget_group` → старый GridLayout с drag-and-drop, как было. Кнопка widget groups не показывается.
- localStorage ключи новые — старые ключи (`vizion-report-view-*`, `vizion-dashboard-layout-*`) не задеты.

### Viewer UX-решение
Viewer (`canManageDashboardLayout=false`) **видит группы и может их сворачивать через ColumnGroupsToggle**. Persisted в localStorage его собственного устройства, никаких изменений конфига отчёта. Это локальный UI-фильтр; viewer его теряет при login на другом устройстве. Если бизнес позже захочет «viewer не может скрывать» — добавить `:disabled` на ColumnGroupsToggle через capability check; пока не блокируем.

### Ключи localStorage
- `vizion-column-groups-{reportId}-{userId}` — `{ hiddenGroups: string[] }` для column groups.
- `vizion-widget-groups-{reportId}-{userId}` — `{ hiddenGroups: string[] }` для widget groups.

### TypeScript / Lint / Build
- `npm run type-check` — чисто (vue-tsc --build, 0 errors).
- `npm run lint` — 0 errors, 1 warning (pre-existing в `front/src/plugins/persist.ts:91` — comma-dangle, не моих изменений).
- `npm run build` — 8.99s, проходит.

### Что осталось без изменений
- Backend (jsonb pass-through), AI prompt (separately by chat-ai-engineer), `ReportSeeder.php` (не трогали системные отчёты).
- Сам PrimeVue (4.5.4 уже подключен, ColumnGroup / Row / Column доступны).
- Существующие composables `useReportPresentation`, `useDashboardLayout` — только PresentationColumn расширили опциональным полем.

### Эталонная цепочка
- Группы колонок видны: AI возвращает `columns[].column_group: "Финансы"` для группы из 3+ колонок → header сразу двухуровневый, кнопка `pi-th-large` появляется в шапке.
- Скрытие группы: клик на checkbox в попапе → колонки группы пропадают из таблицы + footer пересчитывается, header перерисовывается без этой группы.
- Reload: открываем отчёт заново — скрытые группы остаются скрытыми (`localStorage` восстановлен).
- Reset: кнопка «Сбросить» в попапе → все группы снова видны, localStorage очищен.
- Виджеты с widget_group: дашборд рендерится секциями (без drag), каждая секция collapsible через клик по header'у. «Прочее» секция для виджетов без widget_group.
- Toolbox: SelectButton переключателя Table/Dashboard на 1280px теперь кликается нативно.


## 2026-05-20 — Toolbox click intercept v2 fix (повторный P1)

### Контекст
QA после первого фикса (см. выше — `pointer-events: none` на `.toolbox` root + `> * { pointer-events: auto }`) повторно зафиксировал, что **native click на SelectButton (Дашборд) и кнопку «Фильтры»** в шапке ReportPage при viewport 1280px по-прежнему перехватывается. Конкретно — субдерево `.toolbox.toolbox--top` всё ещё попадает в hit-test чек поверх SelectButton.

### Root cause (правильный, не тот что я писал в первой итерации)
Это **не pointer-events bubbling и не bounding-box flex-gap**. Это **физическое геометрическое перекрытие** видимого ToolboxToggle и правого края `.report-header`:

**Координаты при 1280px viewport:**
- `.toolbox.toolbox--top` сидит `position: fixed; top: 1rem; right: 1rem`. Toggle (ToolboxToggle): width = `$toolbox-control-size` = 2.75rem (44px), height ≈ 75px (grip 16 + divider + button 32 + divider + grip 16 + padding).
  - Toggle x-range: **[viewport-60px, viewport-16px]** (right edge 16px, ширина 44px).
  - Toggle y-range: **[16px, ~91px]**.
- `.report-card` — внутри `.report-detail-page` (padding 0.75rem) + own `padding: 1rem`. Так что rightmost edge `.header-right` controls (SelectButton, фильтр-кнопка) при `justify-content: flex-end` = **x ≈ viewport - 28px** (12 + 16 = 28).
- `.report-header` y-range при стандартной flex-row layout: **[~28px, ~70-90px]** (page padding 12 + card padding 16 + content).

**Overlap:** SelectButton/Filter rightmost edge на x ≈ viewport-28px лежит **внутри** toggle x-range `[viewport-60, viewport-16]`. По y-оси шапки и toggle полностью перекрываются. Toolbox z-index = 1200 (`zIndex.toolbox`), report-header z-index = auto (≈0). Поэтому browser hit-test резолвит координату клика SelectButton (его правые ~32px) в `.toolbox-toggle` (выше по z), а не в SelectButton.

**Почему первый fix (pointer-events: none на root) не сработал:**
- `pointer-events: none` на `.toolbox` root корректно проксирует hit-test сквозь *root div*. Но `> * { pointer-events: auto }` возвращает auto на ToolboxToggle (видимый и обязан принимать клик для drag+collapse).
- ToolboxToggle сам по себе **визуально занимает** ту область, где сидит правый край header. Hit-test на координате SelectButton-right-edge всё равно возвращает `.toolbox-toggle` (теперь не root, а сам toggle), потому что **физически он там есть и кликабелен**.
- pointer-events нельзя выключить на видимом toggle — это сломает UX (нельзя кликнуть на collapse-кнопку и dragнуть). Поэтому проблема **в layout**, не в hit-test plumbing.

### Что я измерил (без браузера, через CSS-каскад + tokens)
- `_tokens.scss`: `$toolbox-control-size: 2.75rem`, `$toolbox-grip-size: 1rem`, `$toolbox-toggle-main-size: 2rem`.
- `Toolbox.vue` `.toolbox--top`: `top: 1rem; right: 1rem; flex-direction: row-reverse`.
- `ToolboxPanel.vue` `.toolbox-panel`: `position: absolute` (исключён из flex-layout родителя, **не** влияет на bounding box `.toolbox`).
- `ReportPage/index.vue` `.report-detail-page` padding 0.75rem; `.report-card` padding 1rem; `.report-header` без right-padding; `.header-right` justify-content: flex-end.
- Overlap по x: 60-28 = **32px правой границы header-right лежит под toggle**.

### Выбранный вариант — D (layout-side reserve), главное; B (pointer-events trap on root), вторичное
**Главный fix:** добавлен `padding-inline-end: 4rem` на `.report-header` в `front/src/pages/ReportPage/index.vue`. Это резервирует 4rem = 64px справа в шапке, что отжимает SelectButton и Filter-button влево от toolbox-toggle на ≥20px зазора. Применяется безусловно — при `.toolbox--left` placement (toggle в bottom-left) лишний padding-right в шапке косметически не вреден (просто шапка сдвигается влево внутри карточки, контент остаётся читаемым).

**Расчёт:** Toggle right-edge зона = 1rem (top/right inset) + 2.75rem (control width) = 3.75rem от viewport edge. С учётом `.report-card` уже даёт `padding: 1rem` (1rem от viewport через page-padding 0.75rem + card-padding 1rem... wait — actually total 1.75rem). После моего `padding-inline-end: 4rem` на `.report-header`, rightmost header-right element = viewport - (0.75 + 1 + 4) rem = viewport - 5.75rem = viewport-92px. Toggle left edge = viewport-60px. Зазор header→toggle = 32px. Достаточно.

**Вторичный fix (оставлен из предыдущей итерации):** `pointer-events: none` на `.toolbox` root + `> * { pointer-events: auto }`. Это safety layer на случай если другие страницы в будущем забудут добавить аналогичный padding или появится новый floating-style overlap. Комментарий в `Toolbox.vue` обновлён — теперь явно говорит что main mitigation на page-side, а этот rule — secondary defense.

**Drag не сломан:** `.toolbox-toggle__grip` `@pointerdown.stop="emit('start-drag', $event)"` — стандартный grip. Pointer-events на grip = auto (наследуется через `.toolbox > * { pointer-events: auto }` → applied to .toolbox-toggle root → applies to its descendants by default). Никаких изменений в drag-логике.

### Изменённые файлы
- `front/src/pages/ReportPage/index.vue` — `.report-header { padding-inline-end: 4rem; }` + длинный объясняющий комментарий.
- `front/src/components/Toolbox/Toolbox.vue` — комментарий к `pointer-events: none` rule обновлён (роль downgraded до safety layer; правило не изменилось).

### Альтернативы которые рассматривал и отверг
- **A (уменьшить hit-area grip-а):** не помогает — главный hit-блокер не grip-dots, а сам toggle button + flex bounding. Grip-pad занимает ~16px высоты — мизер. Отвергнуто.
- **B (переместить toolbox в правый край-правее):** Toolbox уже сидит на `right: 1rem`. Дальше — за viewport. Отвергнуто.
- **C (pointer-events: none на toolbox-toggle__grip):** не помогает, grip — не главный блокер. Drag всё равно работает через `pointerdown.stop` так что pointer-events можно убрать с grip — но не решит проблему physical overlap toggle button. Отвергнуто.
- **D-альтернатива (поднять z-index report-header выше Toolbox):** ломает визуальную иерархию — header будет выезжать поверх floating toolbox panel когда тот раскрыт, UX-катастрофа. Отвергнуто.
- **Динамический padding-right через CSS variable от Toolbox placement:** elegant, но over-engineering — placement переключается редко, и `padding-right: 4rem` при `--left` не вреден. Отвергнуто.

### Верификация
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle`, не моя зона).
- **Live verification через `document.elementFromPoint`** — выполнит qa-tester после моих изменений. Я не могу запустить Playwright из этого агента (это зона qa-tester). Ожидаемый результат: `elementFromPoint` на координатах SelectButton возвращает один из элементов `.report-view-toggle .p-selectbutton` (или его inner button), **не** `.toolbox-toggle`.

### Что qa-tester должен проверить
1. Viewport 1280×800, ReportPage любого отчёта (Capital Invest «Финансы по сделкам» подходит).
2. `document.elementFromPoint(<x SelectButton>, <y SelectButton>)` → должен быть SelectButton / его inner button, не `.toolbox`.
3. Native click на SelectButton «Дашборд» → переключается в dashboard view.
4. Native click на кнопку «Фильтры» → раскрывается панель фильтров.
5. Toolbox toggle сам по себе остаётся кликабельным (collapse Toolbox, drag за grip).
6. На viewport 1920×1080 — никаких регрессий, SelectButton по-прежнему работает.
7. При `.toolbox--left` placement (Profile → переключить через placement-toggle на «Слева внизу») — поведение шапки не сломано (padding-right остался, но визуально это OK).


---

## Toolbox v3 fix — Toolbox dropped below page-header strip (2026-05-20)

### Проблема (после v1+v2)
QA сообщает: `document.elementFromPoint(921, 59.5) = .toolbox-panel__button--placement-toggle`. SelectButton «Дашборд» (`x=873..969, y=43..76`) перекрыт panel'овской кнопкой `placement-toggle` (`x=893..932, y=24..62`). Предыдущие 2 попытки (v1: `pointer-events:none` на `.toolbox` root; v2: `padding-inline-end:4rem` на `.report-header`) не решили проблему.

### Root cause (новый анализ)
`stores/layout.ts: toolboxCollapsed: false` — panel **expanded по умолчанию**. Это меняет картину: panel вместе со всеми его кнопками (placement-toggle, 2-3 nav, 3 action overlays) физически живёт в верхнем правом углу шириной ~280-310px. `padding-inline-end:4rem` (=64px) на `.report-header` сдвигает header-right всего на 64px влево — этого мало, чтобы уйти из-под panel'а. Плюс `.report-header { flex-wrap: wrap }`: при сжатой ширине header-right может wrap'нуться, и тогда SelectButton окажется ниже header-left в произвольной X-позиции — она и совпадает с panel-toggle.

ТЗ-вариант A («скрыть placement-toggle при collapsed») — **не решит**: panel не collapsed, и проблема не в одной placement-toggle кнопке, а во всех 7+ кнопках panel'а, физически лежащих в области header'а.

### Выбранный вариант: B (ТЗ) — вертикально сдвинуть Toolbox под page-header
- `.toolbox--top { top: 1rem → 5rem }` (80px). Toolbox toggle теперь сидит ниже строки шапки страницы (~3rem header + 0.75-1rem page padding = ~4rem нижний край шапки).
- Panel при `data-placement='top'` имеет `top: 50%; translateY(-50%)` относительно `.toolbox` (высота ~50px). Panel center в y ≈ 80+25 = 105px → panel занимает y=80..130px. Не пересекается с header'ом по вертикали.
- Откатил v1 (`pointer-events:none` на `.toolbox` root + `> * {pointer-events:auto}`) — он не давал реальной защиты при expanded panel (interactive children opt back in), и его комментарий был основан на ошибочной mental model. Сохранены только нужные `touch-action: none; user-select: none; cursor: grab`.
- Откатил v2 (`padding-inline-end: 4rem` + комментарий на `.report-header`) — не нужен после v3, и более того, он мог триггерить flex-wrap и пускать header-right ниже под Toolbox.
- В `@media (max-width: 767px)` тоже `top: 5rem` (было `0.5rem`) — консистентность; на мобиле всё ещё видимый верх viewport.

### Почему выбрал B, а не A
- A заявленный QA-ом «скрыть placement-toggle в collapsed state» неверен по предпосылке — panel **не collapsed** по дефолту (`stores/layout.ts:7: toolboxCollapsed: false`).
- A решает только узкую часть hitbox'а (placement-toggle, 39×38px), но nav-buttons и action-overlays panel'а ВСЕ в той же высокой y-strip и тоже могут перехватывать клики на других сценариях (например, если у page-header чуть длиннее content и SelectButton ушёл на 1rem левее).
- B даёт системную развязку: Toolbox физически выше / ниже шапки страницы, без overlap по Y → никакой перехват клика по X в этой strip невозможен.

### Что НЕ ломается
- Drag: `.toolbox-toggle__grip @pointerdown.stop="emit('start-drag')"` остался как был. `touch-action: none` на root сохранён.
- Overlay menus (Company switcher, Profile, MiniChat): остаются в panel'е, теперь раскрываются ниже Toolbox (на y~130px и далее вниз) — функционально OK.
- Placement toggle (top ↔ left): работает; в left-state Toolbox остаётся `bottom: 1rem; left: 1rem` (не тронут).
- Drag persistence: `layoutStore.toolboxPositions` сохраняет позицию, но default state теперь стартует ниже. Existing users с сохранённой кастомной позицией — увидят её как было; кому при первой загрузке Toolbox оказался в верхнем-правом по дефолту — теперь на 4rem ниже.

### Изменённые файлы
- `front/src/components/Toolbox/Toolbox.vue` — `.toolbox--top { top: 1rem → 5rem }`, `@media (max-width:767px) .toolbox--top { top: 0.5rem → 5rem }`, удалён prior pointer-events safety-layer (заменён на длинный комментарий с историей попыток v1/v2/v3).
- `front/src/pages/ReportPage/index.vue` — удалён `.report-header { padding-inline-end: 4rem }` и связанный длинный комментарий (v2 откатан).

### Верификация
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle` — не моя зона, см. memory `lint_preexisting`).
- **Live verification** — выполнит qa-tester после rebuild стека. Ожидаемый результат: `document.elementFromPoint(<centerX SelectButton>, <centerY SelectButton>)` возвращает SelectButton (или его inner `.p-button`), **не** `.toolbox-panel__button--placement-toggle`.

### Что qa-tester должен проверить
1. Viewport 1280×800, ReportPage Capital Invest «Финансы по сделкам».
2. Скрипт из ТЗ:
   ```js
   const sb = [...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Дашборд');
   const rect = sb.getBoundingClientRect();
   const at = document.elementFromPoint(rect.left + rect.width/2, rect.top + rect.height/2);
   ```
   Должен вернуть `at_is_sb: true`.
3. Native click на SelectButton «Дашборд» / «Таблица» — переключаются.
4. Native click на «Фильтры» — раскрывается панель фильтров.
5. Toolbox toggle (бургер) кликается, panel раскрывается / сворачивается.
6. Drag Toolbox за grip-dots работает.
7. На viewport 1920×1080 — Toolbox видим в верхнем-правом (просто на 4rem ниже чем раньше), функционально OK.
8. Placement toggle: переключение top ↔ left — Toolbox корректно перемещается в bottom-left и обратно.

## P2 cleanup post PM-review (2026-05-21)

### Контекст
PM-review по П.2 фиксировала 4 мелких regression / refactor задачи после fix v3 Toolbox-pointer-events:
1. `top: 5rem` глобально на Toolbox — регрессия для страниц БЕЗ header (AiReportsPage, ReportsPage, UserSettings) — Toolbox слишком низко.
2. `ColumnGroupsToggle.vue` — prop/computed shadowing (`iconClass` объявлен и как prop, и как computed).
3. `ReportPage/index.vue` — два отдельных `onBeforeUnmount` блока (ResizeObserver + reportContextStore).
4. `ColumnGroupsToggle.vue` — i18n ключи захардкожены на `columnGroups.*` даже когда компонент переиспользуется для widget groups в ReportDashboardView.

### 1. Toolbox top-offset → CSS Custom Property + JS lifecycle override
**Проблема:** scoped SCSS правило `.report-detail-page { --toolbox-top-offset: 5rem }` НЕ работает: Toolbox это sibling-компонент `<main>` в `DefaultLayout`, custom properties каскадируются по DOM tree, и Toolbox находится **вне** subtree ReportPage.

**Решение:** двухуровневое:
- Toolbox.vue читает `top: var(--toolbox-top-offset, 1rem)` (mobile: `var(--toolbox-top-offset-mobile, 0.5rem)`). Дефолты — старые pre-fix-v3 значения.
- ReportPage/index.vue в `onMounted` ставит `document.documentElement.style.setProperty('--toolbox-top-offset', '5rem')` (+ mobile), в `onBeforeUnmount` снимает через `removeProperty`. Scope override = lifetime ReportPage.

**Эффект:** на AiReportsPage / ReportsPage / прочих страницах без header — Toolbox в top: 1rem (как было до fix v3). На ReportPage — top: 5rem (где есть .report-header), как требовала fix v3.

### 2. ColumnGroupsToggle.vue — убран shadowing
Удалён `const iconClass = computed(() => props.iconClass)`. Vue 3.5 `<script setup>` авто-экспортит props в template scope по имени, ничего оборачивать в computed не нужно. Также удалён неиспользуемый `import { computed }` и неиспользуемая локальная переменная `props` (заменено на `withDefaults(defineProps<Props>(), {...})` без захвата результата).

### 3. ReportPage/index.vue — объединён `onBeforeUnmount`
Один блок: `paginationResizeObserver?.disconnect() + paginationResizeObserver = null + reportContextStore.clear() + resetToolboxOffset()`. Логичнее читается — все teardown-шаги в одном месте.

### 4. ColumnGroupsToggle.vue — prop'ы для i18n ключей
Добавлены `tooltipKey?: string`, `headerKey?: string`, `resetKey?: string` (default'ы — `columnGroups.tooltip` / `.header` / `.reset`). На вызове из ReportDashboardView.vue для widget groups передаём `tooltip-key="widgetGroups.tooltip"` + `header-key="widgetGroups.header"` + `reset-key="widgetGroups.reset"`.

Ключи `widgetGroups.tooltip`, `widgetGroups.header`, `widgetGroups.reset` уже существовали в `ReportPage/locale/{ru,en}.json` (от прошлой итерации widget-groups) — симметричны.

### NOT TODO в этом раунде (соблюдено)
- ❌ `AiRetryService` / streaming / MiniChat — не тронут.
- ❌ PrimeVue ColumnGroup рендер — не тронут.

### Изменённые файлы
- `front/src/components/Toolbox/Toolbox.vue` — `top: var(--toolbox-top-offset, 1rem)` + mobile `var(--toolbox-top-offset-mobile, 0.5rem)`. Расширен исторический комментарий (v1 / v2 / v3 / P2-cleanup).
- `front/src/pages/ReportPage/index.vue` — `applyToolboxOffsetForReportPage()` + `resetToolboxOffset()` хелперы, вызов в `onMounted` / в объединённом `onBeforeUnmount`. Удалён второй `onBeforeUnmount` блок.
- `front/src/pages/ReportPage/components/ColumnGroupsToggle.vue` — убран `computed(iconClass)` shadowing + неиспользуемые импорты; добавлены `tooltipKey` / `headerKey` / `resetKey` props с дефолтами `columnGroups.*`.
- `front/src/pages/ReportPage/components/ReportDashboardView.vue` — на `<ColumnGroupsToggle>` для widget-groups переданы `tooltip-key="widgetGroups.tooltip"` + аналогичные для header / reset.

### Верификация
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle` — не наша зона, см. memory `lint_preexisting`).
- `npm run build` — успешно (8.94s, dist собран).
- Live verification — выполнит qa-tester после локального rebuild: (a) на AiReportsPage / ReportsPage Toolbox в верхнем-правом, top ≈ 1rem; (b) на ReportPage Toolbox по-прежнему ниже header'а; (c) widget-groups popover в Dashboard mode отображает строки на правильном языке (виджеты / Widget groups / Reset).

---

## P5 tooltip column description (2026-05-21)

### Контекст
ТЗ `DEVELOPMENT_PLAN_CAPITALDATA.md` §5. Backend (отдельная зона) расширяет конфиг колонки опциональным полем `description?: LocalizedText | null` (pass-through, без валидации). Фронт обязан рендерить рядом с заголовком столбца иконку `?`, по hover-у/click-у — tooltip с локализованным текстом. Иконка показывается **только** если у колонки есть непустой `description`; иначе header выглядит как раньше (zero visual regression для всех существующих системных и пользовательских отчётов).

### Контракт (frontend-side)
Тип `ReportColumnDto.description?: LocalizedText | null`, где `LocalizedText = string | Record<string, string>` (уже существующий проектный type в `shared/types/localization.ts`). Backend может прислать либо `null/undefined` (нет описания — стандартный кейс), либо строку, либо объект `{ru, en}`. Other locales допустимы — `getLocalizedText` упадёт в fallback chain `locale → 'en' → first value → ''`.

### Подход
1. **Тип в API-слое** — `front/src/api/types/reports.ts::ReportColumnDto.description?: LocalizedText | null`. Tagged комментарий со ссылкой на план.
2. **Резолв на presentation-слое** — в `useReportPresentation::tableColumns` после `getLocalizedText` нормализую к `string | null`: пустую строку (например, объект без активной локали и без fallback'ов) трактую как «нет описания», чтобы template'у хватило одной truthiness проверки `v-if="col.description"`. Тип `PresentationColumn.description?: string | null`.
3. **Helper** — отдельный composable не нужен, существующего `getLocalizedText` (utils/localization.ts) достаточно. Логика fallback'ов идентична поведению `header`.
4. **Рендер** — PrimeVue `<Column #header>` slot с двумя элементами: `<span class="column-header-label">{{ col.header }}</span>` + `<i v-if="col.description" v-tooltip.top="col.description" class="pi pi-question-circle column-header-tooltip-icon" @click.stop aria-hidden="true" />`. `@click.stop` гарантирует, что клик по иконке не триггерит sort колонки (PrimeVue вешает sort-handler на весь `<th>`). `v-tooltip` directive — уже подключённая локально через `const vTooltip = Tooltip` в `index.vue` (паттерн как у label_lines / truncate first_word). Глобальной регистрации не требуется.
5. **Парент-Column в grouped header** (label группы `column_group`) — **БЕЗ** иконки. Спецификация ТЗ: `column_group` это layout-метка, не описание колонки.
6. **Три места рендеринга** (все обновлены):
   - **Flat-table data Column** (вне ColumnGroup, простые отчёты без `column_group`) — slot `#header` через guard `v-if="!columnGroups.hasGroups.value"` (когда ColumnGroup активен, headerless data-Column'ы берут header из под-строки ColumnGroup).
   - **Ungrouped Column в верхнем ряду ColumnGroup** (`rowspan=2`) — slot `#header`.
   - **Grouped sub-header Column** (нижний ряд ColumnGroup) — slot `#header`.
   - **Master/detail children Column** (вложенный DataTable в `#expansion`) — slot `#header` (для консистентности; backend может в будущем послать `description` на drill-down колонках).

### Совместимость
- Все системные отчёты в `ReportSeeder.php` не имеют `description` (или report-author пропустил часть колонок) → `col.description = null` → иконка не рендерится → header выглядит как раньше. Zero visual regression.
- Sort продолжает работать: PrimeVue sort-handler на `<th>`; клик по `<span class="column-header-label">` и по иконке (`@click.stop`) корректно различаются — клик по label триггерит sort, клик по иконке — только показывает tooltip.
- Tooltip RU/EN: `tableColumns` computed реагирует на `locale`, `getLocalizedText` пересчитывает текст при переключении языка → tooltip обновляется без полной перерисовки таблицы.

### SCSS
В `.report-detail-page .report-card .report-tabs .data-section :deep(.p-datatable)`:
- `.column-header-tooltip-icon { margin-left: 0.35rem; font-size: 0.85rem; color: $surface-500; cursor: help; transition: color 0.15s ease; vertical-align: middle; &:hover, &:focus { color: $surface-700; } }`
- `.column-header-label {}` — пустой hook на будущее (если потребуется reflow при узких viewport'ах).
- Используется `:deep()` потому что слот рендерится внутри PrimeVue-обёртки `.p-datatable-column-header-content`, и Vue scoped CSS не всегда корректно атрибутирует селекторы внутри slot-payload'а после rendering.
- Цвета — токены `$surface-500` (`muted gray`) → `$surface-700` (`darker on hover`). Размер иконки на ~15% меньше label'а — заметно, но не доминирует. `vertical-align: middle` ровняет с базовой линией label'а.

### Изменённые файлы

| Файл | Изменение |
|---|---|
| `front/src/api/types/reports.ts` | + `ReportColumnDto.description?: LocalizedText \| null` с комментарием-ссылкой на план §5 |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | + `PresentationColumn.description?: string \| null`; в `tableColumns.map` резолв `getLocalizedText(col.description, locale.value)` с нормализацией пустой строки в `null` |
| `front/src/pages/ReportPage/index.vue` | (1) `GroupHeaderCell.kind:'ungrouped'` + `groupedSubHeaderCells` — поле `description: string \| null`; (2) три slot'а `#header` (flat fallback, ungrouped-rowspan, grouped sub-header, child-table) — `<span>` + опциональная `<i>` иконка с `v-tooltip.top` и `@click.stop`; (3) SCSS: `.column-header-tooltip-icon` (size/color/hover) в `:deep(.p-datatable)` |

### Что НЕ менял
- Backend / контроллеры / валидаторы — это отдельная зона.
- ReportSeeder — это `report-author`'a.
- `getLocalizedText` — не трогал (он уже умеет всё что нужно).
- i18n locale-файлы — новых ключей нет (tooltip-текст приходит из backend `description`, не из vue-i18n).
- Глобальную регистрацию `Tooltip` директивы (она и так локально подключена в index.vue, паттерн из `truncate: 'first_word'`).

### Верификация
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle` — не моя зона).
- `npm run build` — успешно (8.76s, dist собран).
- RU↔EN locale parity на ReportPage — без изменений (новых ключей нет; tooltip-текст приходит с бэка).

---

## 2026-05-21 — П.6 MiniChatWidget: фиксы после ручного тестирования

**Контекст:** параллельная сессия закрыла основу П.6 (`MiniChatWidget.vue`, `reportContext` Pinia store, `Toolbox` интеграция, `capabilities.canUseMiniChat`, `useFormatter` currency). Владелец прокликал и нашёл три проблемы.

### Проблема 1 — type-check fail в mocks/data.ts (currency_code/timezone)

**Диагноз:** К моменту фикса type-check уже проходил чисто (`npm run type-check` → 0 errors). `mockCompany` в `front/src/mocks/data.ts` уже содержит `currency_code: 'RUB'` и `timezone: 'Europe/Moscow'` — параллельная сессия успела поправить до моей передачи. Других объектов типа `CompanyDto` в моках нет (`active_company` использует `mockCompany`, не отдельный литерал). Изменения не требуются.

### Проблема 2 — старая зашитая ссылка на чат в Toolbox

**Диагноз:** До добавления `MiniChatWidget` в Toolbox существовал nav-item с ключом `'ai-chat'` (иконка `pi-comments`, путь `/ai-chat`), который рендерился в `ToolboxPanel` через `navItems` computed под условием `canUseAi`. Это дублировало новую MiniChat-toggle в `#actions` slot — обе иконки на одну и ту же визуальную сущность (AI-чат). git diff против HEAD подтверждает: nav-item существовал до итерации, не добавлен новым кодом.

**Изменения в `front/src/components/Toolbox/Toolbox.vue`:**
- Удалён nav-item `ai-chat` из `navItems` computed.
- Удалены `canUseAi as hasAiCapability` import и `canUseAi` computed (больше нигде не используются).
- Локали `aiChat` и `aiReports` в `Toolbox/locale/{ru,en}.json` удалены (не используются после правки; ключ `miniChat` остаётся — он на новой иконке).

### Проблема 3 — Expand из MiniChat открывает не тот чат

**Диагноз (root cause):** В `useAiReportsPage.ts` стоял обходной паттерн — синхронно в setup читать `?activate=N` query-param и записывать `activateChatId` в `window.history.state` через `replaceState`, чтобы `useChatPage.initScope` (из `onMounted`) его подобрал. Параллельно `void router.replace({ query: rest })` стирал query-param из URL. Vue Router 4 при `router.replace` вызывает свой собственный `window.history.replaceState`, который перезаписывает state и СНОСИТ только что записанный `activateChatId`. К моменту mount → `initScope` читает `history.state.activateChatId` → уже `null` → fallback на `activeChat.value` → дефолтная страница «генератора отчётов». Это и был симптом владельца.

**Изменения:**
- `front/src/pages/shared/useChatPage.ts` — расширил `UseChatPageOptions` опциональным `pendingActivateChatId?: Ref<number | null>`. В `initScope` читаю ref ДО `consumeActivateChatIdFromRouterState()` (приоритет cross-tab над same-tab), nullью ref после чтения (one-shot), сверяю кандидата по `chats.value` и `candidate.type === options.type` — defensive против stale state. Старый `history.state` handoff оставлен для backward-compat с same-tab CTA (`/reports` action-marker tile).
- `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts` — `consumeActivateQueryParam()` теперь не пишет в `history.state` вообще. Создаёт локальный `ref<number | null>(null)`, кладёт туда id из query при наличии, стрипает `?activate=` через `router.replace` (fire-and-forget — clobber state теперь не страшен) и возвращает ref в `useChatPage` через новый опционный параметр. Ref локален к новой вкладке, поэтому при `window.open(_blank)` в новой tab свежий ref читается до того как mount-цикл вообще доходит до `router.replace`.

**Почему ref, а не sessionStorage / localStorage:** оба варианта переживают reload; ref — нет. Reload вкладки с уже стрипнутым URL `/ai-reports` (без `?activate=`) не должен повторно активировать тот же чат. Ref-подход гасит handoff после одного успешного применения, sessionStorage пришлось бы вручную чистить (и его клиренс — отдельный race с другими табами).

### Изменённые файлы

| Файл | Изменение |
|---|---|
| `front/src/components/Toolbox/Toolbox.vue` | Удалён nav-item `'ai-chat'`, import `canUseAi`, computed `canUseAi` |
| `front/src/components/Toolbox/locale/ru.json` | Удалены ключи `aiChat`, `aiReports` |
| `front/src/components/Toolbox/locale/en.json` | Удалены ключи `aiChat`, `aiReports` |
| `front/src/pages/shared/useChatPage.ts` | + `pendingActivateChatId?: Ref<number \| null>` опция; `initScope` приоритезирует ref над `history.state` |
| `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts` | `consumeActivateQueryParam` пишет в локальный ref вместо `history.state`, ref передаётся в `useChatPage` |

### Верификация
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle` — не моя зона, см. memory `feedback_lint_preexisting`).
- i18n RU↔EN parity в Toolbox locale — симметрия сохранена (удалены одни и те же ключи в обоих файлах).

### Что НЕ менял
- `MiniChatWidget.vue` — `openInFullScreen` уже корректно делает `window.open('/ai-reports?activate=' + id, '_blank', 'noopener,noreferrer')`.
- Backend / API — это UI-only фикс.
- `reportContext` Pinia store, `canUseMiniChat` capability, `useFormatter` currency — параллельная сессия уже доделала.
- Live verification — выполнит qa-tester после rebuild стека: (a) для колонки с `description` рядом с label виден `?` иконка muted-серого; (b) hover на иконку → tooltip с текстом на текущей локали; (c) переключение языка → tooltip-текст обновляется без F5; (d) клик по иконке НЕ триггерит sort колонки; (e) клик по label-тексту триггерит sort (если колонка sortable); (f) на колонках без `description` иконки нет, header идентичен прежнему.

---

## 2026-05-21 — feat: timezone + currency_code в форме настроек компании (admin RBAC gap)

**Контекст:** backend в этой же итерации расширил `PUT /api/companies/{id}` на admin'а с whitelist'ом `crm_url` / `currency_code` / `timezone` (validate: `currency_code` regex `/^[A-Z]{3}$/`, `timezone` — `DateTimeZone::listIdentifiers()`). Owner QA: «таймзоны не выведены в настройки компании». До этой правки админ не видел эти поля в UI вообще.

### Что добавлено

1. **`front/src/components/Company/constants.ts` (NEW)** — реестр выборов:
   - `CURRENCY_CODE_PATTERN` = `/^[A-Z]{3}$/` (одно правило-источник для client guard + UI mask)
   - `COMMON_CURRENCIES` — 8 валют (RUB, KZT, UZS, AED, USD, EUR, TRY, CNY) с label `"<CODE> · <symbol> <name>"`, ordered by relevance
   - `listTimezoneOptions()` — `Intl.supportedValuesOf('timeZone')` (Chrome 99+/Safari 15.4+/Node 18+, ~430 IANA-зон) с fallback на curated-список ~50 зон, отсортировано alphabetically
2. **`CompanyFormData` / `CompanyFormErrors`** — `currency_code: string`, `timezone: string` (пустая строка = "no preference" → backend `null`), и одноимённые слоты errors для inline-валидации
3. **`CompanyFormModal.vue`** — в одной строке два Select'а:
   - **Currency** — Select с `:show-clear="true"`, список `COMMON_CURRENCIES + "Other…"`. При выборе "Other" разворачивается `InputText` с live-маской (uppercase + only-letters + slice(3)) — пишет напрямую в `formData.currency_code`. При re-hydrate из стора с unknown-кодом — Select сам встаёт в "Other"-режим и пре-заполняет input
   - **Timezone** — Select с `:filter="true"` + `:auto-filter-focus="true"`, options из `listTimezoneOptions()` (computed, stable per call). Поиск по подстроке IANA-имени
   - Help-текст под каждым полем (`currencyHelp`/`timezoneHelp`) + inline `p-error` slot
4. **`CompanyFormModal` RBAC-guidance** — новый prop `canEditAllFields?: boolean = true`. При `false` (admin):
   - `name` рендерится `:disabled` + поясняющий help `readOnlyForAdmin`
   - вся `MacroData (опционально)` секция скрыта `v-if="canEditAllFields"`
   - Backend — single source of truth для RBAC, фронт-флаг только UX-подсказка
5. **`useCompanySettingsActions.ts`**:
   - `toCompanyUpdatePayload(formData, role)` — payload split по роли. Admin отправляет ровно whitelist (`crm_url`, `currency_code`, `timezone`), superadmin — всё включая MacroData. Это убирает реальные 422-round-trip'ы (раньше форма всегда слала `name`, который backend для admin не примет)
   - `submitCompanyForm()` — отдельные ветки на 403 (toast `forbiddenError`), 422 (inline-errors из `getApiValidationErrors`), default (error в `companyFormError`-баннере). Раньше всё схлопывалось в один баннер
   - `runClientValidation()` — client-side guard на `CURRENCY_CODE_PATTERN`, чтобы поймать `"RUBL"`/`"ru"` до бэка
   - `canEditAllFields` computed (role === 'superadmin') — экспортируется из composable, прокидывается в modal через page-vue
6. **`CompanySettingsSection.vue`** — две новые read-only строки: `settingsCurrency` / `settingsTimezone`, `—` placeholder когда null
7. **`useCompanyManagementModal.ts`** (superadmin only company-list modal) — `formData` / `resetFormData` / `openEditModal` / `buildPayload` дополнены `currency_code` + `timezone`. Иначе TS бил `Type missing properties` после расширения `CompanyFormData`
8. **i18n RU + EN** (в `components/Company/locale/` + `pages/CompanyPage/locale/`, парность 0 unmatched):
   - settings labels: `settingsCurrency`, `settingsTimezone`
   - form labels + placeholders + help: `currencyLabel`, `currencyPlaceholder`, `currencyHelp`, `currencyCustomOption`, `currencyCustomPlaceholder`, `currencyInvalid`, `timezoneLabel`, `timezonePlaceholder`, `timezoneFilterPlaceholder`, `timezoneHelp`
   - errors / guidance: `forbiddenError`, `readOnlyForAdmin`
   - дубликат `currencyInvalid` + `forbiddenError` в page-locale (это сообщения, прокинутые в composable через `CompanyPageMessages`)

### Reactive update в открытых отчётах

`useFormatter` уже читает `companiesStore.getCurrentCompany.currency_code` / `timezone` через geter (reactive). Submit делает `companiesStore.upsertCompany(updated)` → store re-emit'ит реактивно → `formatCurrency` / `formatDate` пересчитываются на следующем tick. Дополнительно `sync: 'company'` в `useSessionMutation` триггерит `refreshScopedData()` → re-fetch active company + users, чтобы CompanySwitcher label и server-derived поля тоже синхронизировались. Никаких manual `forceUpdate` или window-reload — стандартный Pinia reactive path.

### Контракт PUT (как ожидает backend)

```jsonc
// admin payload
{ "crm_url": "https://...", "currency_code": "KZT", "timezone": "Asia/Almaty" }
// или nulls для "очистить"
{ "crm_url": null, "currency_code": null, "timezone": null }

// superadmin payload (расширение admin)
{ "name": "...", "crm_url": "...", "currency_code": "...", "timezone": "...",
  "macrodata_host": "...", "macrodata_port": 3306, /* ... */ }
```

### Изменённые / новые файлы

| Файл | Изменение |
|---|---|
| `front/src/components/Company/constants.ts` | **NEW** — currencies + IANA timezones registry |
| `front/src/components/Company/types.ts` | Added `currency_code` + `timezone` to `CompanyFormData`/`CompanyFormErrors` |
| `front/src/components/Company/modals/CompanyFormModal.vue` | New Currency + Timezone selects, RBAC-aware disabled state, custom-currency input |
| `front/src/components/Company/modals/ManagementModal/useCompanyManagementModal.ts` | Reset/open/build payload синхронизированы с новой `CompanyFormData` shape |
| `front/src/components/Company/CompanySettingsSection.vue` | Read-only вывод currency + timezone |
| `front/src/components/Company/locale/{ru,en}.json` | i18n keys для labels / help / errors |
| `front/src/pages/CompanyPage/index.vue` | Прокинут `canEditAllFields` в modal, новые messages в `useCompanyPage` |
| `front/src/pages/CompanyPage/composables/useCompanyPageData.ts` | `CompanyPageMessages` расширен `currencyInvalid` + `forbiddenError` |
| `front/src/pages/CompanyPage/composables/useCompanySettingsActions.ts` | Role-aware payload, 403/422 branching, client-side currency regex guard, экспорт `canEditAllFields` |
| `front/src/pages/CompanyPage/locale/{ru,en}.json` | `currencyInvalid`, `forbiddenError` |

### Верификация

- `npm run type-check` — 0 errors
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle`, не наша зона — memory `feedback_lint_preexisting`)
- i18n RU↔EN parity — 0 unmatched keys (проверено `flat()`-diff'ом)
- QA / deploy НЕ запускал (по задаче)

### Backlog / not in scope

- A11Y: tooltip на custom-currency input не озвучен скринридером явно — полагаемся на label association
- Read-only view для viewer/analyst — route уже блокирует их (`/company` — admin+superadmin only), отдельной защиты в форме не делал
- `Intl.supportedValuesOf` fallback: curated-список из ~50 зон может быть устаревшим (IANA обновляет дважды в год), но это lazy-fallback для очень старых браузеров

## 2026-05-21 — refactor: `createGroupsComposable<T>` + A11Y tooltip-icon → button

**Контекст:** frontend tech debt из `PROGRESS_CAPITALDATA.md` § «Tech debt». Две независимые задачи, обе закрыты в одной итерации.

### 1. DRY: `createGroupsComposable<T>`

**До:** `useColumnGroups.ts` и `useWidgetGroups.ts` — почти полные копии друг друга (~210 LOC каждый). Совпадают: localStorage I/O (persist envelope `{hiddenGroups: string[]}`, namespace-prefix + reportId + userId), watch-rehydrate на смену reportId/user, pruning stale labels при смене config, toggle/setHidden/showAll, hasGroups-flag, ungrouped/visibleItems computeds. Отличаются: namespace (`vizion-column-groups` vs `vizion-widget-groups`), accessor (`column_group` vs `widget_group`), bucketing-стратегия (contiguous-runs vs Map), kill-switch (`isGroupedReport`).

**После:** generic factory `createGroupsComposable<TItem>(reportId, items, opts)` + два thin wrapper'а. Опции: `storageNamespace`, `getLabel`, `bucketise`, `disableWhen?`. Экспортируются две bucketing-helper'ы: `contiguousBuckets` (column-headers, требует contiguity для PrimeVue colspan) и `orderedMapBuckets` (widgets, non-contiguous duplicates сливаются). TS generic сохраняет inference в use-site — `useColumnGroups` возвращает `ComputedRef<PresentationColumn[]>` для `visibleColumns`, `useWidgetGroups` — `ComputedRef<DashboardWidgetDto[]>` для `visibleWidgets`, без `unknown`/`as`.

**DRY-эффект:** общая часть (~140 LOC: persistence + reactive plumbing + prune + toggle/show/hide) переехала в factory; wrapper'ы по ~90 LOC, из них половина — JSDoc + интерфейс-rename. По сути дубликат-кода устранён на ~80%. Wrapper'ы переименовывают generic `items` → `columns`/`widgets` через computed-маппинг (не алиас Ref), чтобы сохранить call-site API без диффа в `index.vue`.

**Departure:** `useWidgetGroups.ungrouped` возвращает ВСЕ widget'ы когда `hasGroups=false` (legacy «Other»-section fallback), а generic возвращает `[]`. Override — отдельный computed в wrapper'е, задокументировано в JSDoc.

**Tests:** unit-тестов для этих composables не было — новых не создавал (по существующему стилю проекта).

### 2. A11Y: tooltip-icon `<i>` → `<button aria-label>`

**Проблема:** иконка `?` (`pi pi-question-circle`) рядом с column header была `<i v-tooltip>`, недоступна с клавиатуры (фокус невозможен на `<i>`).

**Замена:** `<button type="button" :aria-label="col.description" v-tooltip.top="col.description" @click.stop><i class="pi pi-question-circle" aria-hidden="true" /></button>`. PrimeVue Tooltip-директива умеет показывать hint при focus, так что Tab-обход теперь видит тот же текст. SCSS-правило `.column-header-tooltip-icon` обновлено: snipped native button chrome (`background: transparent`, `border: 0`, `padding: 0`), сохранён прежний muted-gray look (`color: $surface-500` → `$surface-700` на hover/focus), добавлен `:focus-visible` outline `var(--app-action-primary-bg)` 2px для keyboard-юзеров. `type="button"` блокирует случайный submit (на странице нет form, но defensive). `@click.stop` сохранён — клик по иконке не триггерит sort колонки. Inner `<i>` помечен `aria-hidden="true"` — иначе screen-reader озвучивал бы и aria-label кнопки, и pseudo-имя иконки.

**4 render sites переделаны** — все в `front/src/pages/ReportPage/index.vue`:
1. Master/detail children header (строка ~196)
2. Two-level ColumnGroup top-row (строка ~313)
3. Two-level ColumnGroup sub-header bottom-row (строка ~333)
4. Flat Column #header (строка ~361)

i18n не затронут — текст tooltip уже идёт из `col.description` (резолвится через `getLocalizedText` в `useReportPresentation`).

### Изменённые / новые файлы

| Файл | Изменение |
|---|---|
| `front/src/pages/ReportPage/composables/createGroupsComposable.ts` | **NEW** — generic factory + 2 bucketing-helper'а |
| `front/src/pages/ReportPage/composables/useColumnGroups.ts` | Rewrite — thin wrapper над factory (~90 LOC vs ~243 до) |
| `front/src/pages/ReportPage/composables/useWidgetGroups.ts` | Rewrite — thin wrapper над factory (~90 LOC vs ~205 до) |
| `front/src/pages/ReportPage/index.vue` | 4× `<i>` → `<button>` в #header слотах; SCSS `.column-header-tooltip-icon` обновлён |

### Верификация

- `npm run type-check` — 0 errors. TS generic preserves inference в wrapper'ах (`visibleColumns: ComputedRef<PresentationColumn[]>`, `visibleWidgets: ComputedRef<DashboardWidgetDto[]>`).
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle`, не наша зона — memory `feedback_lint_preexisting`).
- i18n RU↔EN parity — не трогал (ничего не добавлял в locale).
- QA/deploy НЕ запускал — по инструкции из задачи.

### Backlog / not in scope

- Timezone UI form — пропущена осознанно, ждёт backend endpoint (другой агент).
- A11Y tooltip-доступность с клавиатуры формально решена, но визуальная проверка focus-ring через Tab — на qa-tester.

---

## 2026-05-21 — QA fixes: tooltip-icon duplication (false alarm) + "Other currency" empty submit

### Баг 1 (минорный, A11Y): дублирование `<button>` и `<i>` в `<th>` — false alarm

QA отчитался, что `document.querySelectorAll('th .pi-question-circle, th .column-header-tooltip-icon')` → 2 элемента в одном `<th>`, что трактовалось как «старый `<i>` остался рядом с новой `<button>`». 

**Проверка показала: исходник чист.** Все 4 render sites в `front/src/pages/ReportPage/index.vue` (строки 196-208, 316-328, 339-351, 370-382) содержат ровно одну иконку, обёрнутую в `<button class="column-header-tooltip-icon">` с inner `<i class="pi pi-question-circle" aria-hidden="true">`. `grep -c "pi-question-circle"` → 4 (по числу render sites). Other ReportPage / Component-файлы — 0 совпадений.

**Откуда «2 элемента»:** QA-селектор через запятую считает И `<button>` (`.column-header-tooltip-icon`) И его child-`<i>` (`.pi-question-circle`) — это разные элементы, но это требуемая A11Y-разметка (button-обёртка + aria-hidden иконка внутри неё). Корректный verify-селектор был бы `th > .pi-question-circle:not(button > i)` или просто `th .pi-question-circle`. Передаю в qa-tester для исправления чек-листа.

**Изменений в коде: нет.** Состояние корректно с предыдущей итерации (A11Y-кнопка). 

### Баг 2 (минорный, UX): «Другая валюта…» с пустым input сохранял `null`

Симптом: на `/company` → редактирование настроек → выбор «Другая валюта…» → пустой text-input → «Сохранить» → форма закрывается, `currency_code` в БД становится `null`. Backend by-design принимает `null` (clear-значение), но семантически «Other…» = намерение ввести custom-код, а не очистить.

**Файл:** `front/src/components/Company/modals/CompanyFormModal.vue`

**Что сделал:**

1. **`handleSubmit` guard переписан.** Старая проверка `formData.currency_code && !PATTERN.test(...)` пропускала пустую строку. Новая: `isCustomCurrency.value && !PATTERN.test(formData.currency_code)` — пустая или partial-строка в custom-mode тригерит `props.errors.currency_code = t('currencyInvalid')` и блокирует submit. На non-custom mode (юзер выбрал известную валюту или сделал show-clear) — никаких ограничений, можно сохранить `null`.

2. **Inline error через `props.errors.currency_code`.** Реактивный `reactive<CompanyFormErrors>` объект из parent (`useCompanySettingsActions.ts`) — паттерн уже устоявшийся (через `applyValidationErrors` на 422). Реюзаю тот же `currencyInvalid` ключ — «Введите три заглавных латинских буквы (ISO 4217)» / «Enter three uppercase Latin letters (ISO 4217).» — сообщение покрывает оба случая (empty и partial).

3. **`onCustomCurrencyInput` чистит ошибку при валидном вводе.** Как только `normalized` match'ит pattern → `props.errors.currency_code = undefined`. UX: красная рамка / `<small class="p-error">` гаснут сразу, без необходимости повторного submit.

4. **`onCurrencySelectChange` чистит ошибку при выходе из custom-mode.** Если юзер передумал и выбрал RUB / clear, ранее установленный «invalid» error не должен висеть на disabled-input'е. Сбрасывается в обеих ветках (`null` clear + known-currency-pick).

i18n не трогал — переиспользовал существующий `currencyInvalid` (RU+EN parity ✅, уже было).

### Изменённые файлы

| Файл | Изменение |
|---|---|
| `front/src/components/Company/modals/CompanyFormModal.vue` | `handleSubmit` правильно ловит empty/partial в custom-mode; `onCustomCurrencyInput` + `onCurrencySelectChange` управляют error-state |

### Верификация

- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91 comma-dangle`, memory `feedback_lint_preexisting`, не наш файл).
- Backend поведение НЕ меняется — `currency_code: null` всё ещё валиден на PUT (см. CompanyController validation rules).
- QA / deploy НЕ запускал — по инструкции из задачи.

---

## 2026-05-21 — Preferences sync (backend → API)

### Задача

UI preferences для отчётов раньше жили только в `localStorage` одного браузера. Бэкенд завёл endpoint `GET / PUT /api/reports/{report}/preferences` (partial upsert), фронт должен начать синхронизироваться через него, оставив localStorage как first-paint кэш.

Фронт-effort: 4 существующих composables (`useReportViewMode`, `useDashboardLayout`, `useColumnGroups`, `useWidgetGroups`) переключить на сетевой sync, не меняя их call-site API в `ReportPage/index.vue`.

### Архитектура

Единый shared store `useReportPreferences(reportId, userId)`:

- **Module-level singleton** по `reportId` (Map + refcount через `onScopeDispose`). 4 wrapper'а получают **один и тот же** инстанс preferences для одного отчёта — иначе они бы конкурировали за GET/PUT.
- `state: Ref<ReportPreferences>` — реактивный снимок (view_mode / dashboard_layout / hidden_column_groups / hidden_widget_groups).
- `update(patch)` — patcher, который мутирует state синхронно (оптимистично), сразу пишет в localStorage и **дебаунсит** PUT.
- `flush()` — форсированный flush ожидающего debounce при unmount (refcount = 0).

Wrapper'ы стали thin: каждый достаёт нужный кусок state через `prefs.state.value.X` и вызывает `prefs.update({X: ...})` на изменение. Call-site API в `ReportPage/index.vue` не поменялся (`useReportViewMode`, `useColumnGroups`, `useWidgetGroups`, `useDashboardLayout` — те же сигнатуры).

### Debounce strategy

- **600 ms** дебаунс на PUT (`PUT_DEBOUNCE_MS`). Покрывает дрэг-бёрсты `grid-layout-plus` (обычно 1 событие `update:layout` на drop, но мульти-toggle групп / view mode + layout одним движением могут породить кластер).
- Coalesced patch: каждый `update(patch)` мерджит payload в общий `pendingPatch`. Несколько изменений в окне = один PUT с одним merged-патчем (last-write-wins per field).
- Cross-field independence: благодаря partial-upsert на бэке, любая комбинация полей в patch'е безопасна.
- На unmount (`onScopeDispose` → refcount=0) — `flush()` отправляет pending PUT синхронно. Запрос продолжит лететь под следующим роутом (нет cancellation).

### Fallback strategy (offline / 5xx)

- **GET fail (mount):** state остаётся на seed-значении из localStorage; `loaded` всё равно становится `true` (один сигнал готовности); `console.warn` (без toast — preferences не критичны).
- **PUT fail:** локальный state и localStorage **не откатываются** (intent юзера сохраняется); `console.warn` со status и patch. Следующий успешный PUT (или reload) ресинхронизирует.
- **Optimistic apply (mount race):** если юзер изменил state, пока GET летел, на ответе GET'а мы НЕ затираем pendingPatch — server-значения подкладываются под user-edits, edits следующим PUT уезжают наверх.
- **Cross-tab sync:** не реализован в этой итерации (опционально через `storage` event, оставлено в backlog).

### Семантика localStorage кэша

Сохранены **легаси-ключи и envelope'ы**, чтобы:
1. Cold-reload до ответа GET красит UI мгновенно из кэша (instant first paint).
2. Откат фичи (если понадобится) находит рабочие данные без миграции.
3. `useDashboardLayout` всё ещё хранит `hiddenWidgets` (per-widget visibility — НЕ часть API contract) в том же envelope `{layout, hiddenWidgets}`. `layout` теперь зеркалится из API; `hiddenWidgets` остаётся локальным.

Ключи (без изменений):
- `vizion-report-view-{reportId}-{userId}` → string `"table"|"dashboard"`
- `vizion-dashboard-layout-{reportId}-{userId}` → `{layout: LayoutItem[], hiddenWidgets: string[]}`
- `vizion-column-groups-{reportId}-{userId}` → `{hiddenGroups: string[]}`
- `vizion-widget-groups-{reportId}-{userId}` → `{hiddenGroups: string[]}`

### Тонкие моменты

- **`hidden_widgets` нет в API contract** — намеренно. Per-widget show/hide — эфемерный «временно скрыть карточку», collection-level concern (`hidden_widget_groups`) уже синкается. Если позже нужно — добавляем поле в backend и сюда.
- **Loop guard в `createGroupsComposable`** — на server→local sync поднимаем флаг `applyingFromServer`, чтобы writeback watcher не запустил обратный PUT с только что полученным значением. Сбрасывается через `queueMicrotask`.
- **`useDashboardLayout` writeback throttle** — `sameLayout()` сравнивает reconciled vs current до вызова `prefs.update`, чтобы reconcile-watcher не плодил пустых PUT'ов на каждом re-render.
- **Default view_mode** — `'table'` (так же как в старой реализации; API возвращает `null` если ничего не сохранено).
- **`createGroupsComposable.ts`**: сменил `storageNamespace: string` → `preferenceField: 'hidden_column_groups' | 'hidden_widget_groups'`. Локальная `localStorage`-логика убрана (она теперь в `useReportPreferences`).

### Файлы

**Созданные:**

| Файл | Что |
|---|---|
| `front/src/api/types/reportPreferences.ts` | `ReportPreferences`, `ReportPreferenceLayoutItem`, `ReportPreferencesPatch` |
| `front/src/api/reportPreferences.ts` | `reportPreferencesApi.get/update` |
| `front/src/pages/ReportPage/composables/useReportPreferences.ts` | Shared store + singleton plumbing + debounce/flush |

**Изменённые:**

| Файл | Что |
|---|---|
| `front/src/api/index.ts` | Re-export `reportPreferencesApi` + типов |
| `front/src/api/types/index.ts` | Re-export типов |
| `front/src/pages/ReportPage/composables/useReportViewMode.ts` | Thin wrapper над `useReportPreferences` (WritableComputedRef для backward-compat `viewMode.value = …`) |
| `front/src/pages/ReportPage/composables/useDashboardLayout.ts` | `layout` теперь из synced state; `hiddenWidgets` остаётся local-only; reconcile push'ит через `prefs.update`; добавлен `sameLayout` guard |
| `front/src/pages/ReportPage/composables/createGroupsComposable.ts` | `storageNamespace` → `preferenceField`; localStorage-плумбинг убран; sync через `useReportPreferences` |
| `front/src/pages/ReportPage/composables/useColumnGroups.ts` | Опция `preferenceField: 'hidden_column_groups'` + обновлён JSDoc |
| `front/src/pages/ReportPage/composables/useWidgetGroups.ts` | Опция `preferenceField: 'hidden_widget_groups'` + обновлён JSDoc |

**Не тронутые:**

- `ReportPage/index.vue` — call-site API wrappers сохранён, нулевая правка.
- `ReportDashboardView.vue` — `DashboardLayoutItem` type-export не поменялся.
- `ColumnGroupsToggle.vue` — `UseColumnGroupsReturn` интерфейс не поменялся.

### Верификация

- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91`, memory `feedback_lint_preexisting`).
- QA / deploy НЕ запускал — по инструкции.

---

## QA fix-pass — prefs-sync 3 critical bugs (2026-05-21, после QA P11)

### Bug 1+2: Dashboard view не рендерится + `GET /api/reports/0/preferences` 404

**Root cause** — общий, два симптома:

`ReportPage/index.vue:619` объявляет `const reportId = computed(() => report.value ? report.value.id : 0)`. На первый paint `report.value === null`, поэтому `reportId.value === 0`. `useReportViewMode(reportId)` синхронно дёргает `useReportPreferences(reportId, userId)`, который **в setup-фазе** делал:

```ts
const instance = acquireReportPreferences(reportId.value, userId.value)  // instance.id=0
let current = instance
watch(reportId, (next) => { current.release(); current = acquireReportPreferences(next, ...) })
return { state: current.state, ... }  // ← snapshot OLD ref!
```

Две проблемы сразу:

1. `acquireReportPreferences(0, ...)` создавал instance с reportId=0 и сразу же триггерил `GET /api/reports/0/preferences` → 404 (это Bug 2 — наблюдаемое доказательство).
2. Возвращённый `state` биндился к ref instance'а-0. Когда watch потом swap'ал `current` на новый instance — `prefs.state` (и downstream `mode = computed(() => prefs.state.value.view_mode)`) по-прежнему смотрел на старый ref. localStorage писал `'dashboard'` корректно, PUT уходил, но `viewMode` в template оставался на DEFAULT_MODE='table' → `v-if="!isDashboard"` всегда true → `<ReportDashboardView>` ни разу не рендерился (Bug 1).

**Fix** в `front/src/pages/ReportPage/composables/useReportPreferences.ts`:

- Добавлен `buildFallbackPreferences()` — read-only skeleton ({view_mode:null, layout:null, hidden_*:[]}).
- `acquireReportPreferences` больше не вызывает `onScopeDispose` внутри (это делают композеры сами теперь).
- Public `useReportPreferences()` переписан:
  - `currentInstance = shallowRef<PreferenceInstance | null>(null)` — opaque holder, чтобы Vue не walk'ал внутренние refs.
  - `acquireForId(id)`: гард `if (id <= 0) return` (никаких GET по `/0/`), идемпотентность по `state.value.report_id === id`, release-перед-acquire.
  - `watch(reportId, acquireForId, { immediate: true })` — реактивно подхватывает 0 → realId.
  - `onScopeDispose` на верхнем уровне releas'ит текущий instance.
  - **`state` и `loaded` возвращаются как `computed`** — `computed<ReportPreferences>(() => currentInstance.value?.state.value ?? fallbackState.value)`. ComputedRef ⊂ Ref на read-path, downstream `prefs.state.value.X` в wrapper'ах работает прозрачно и **реактивно перенаправляется** на новый instance после acquire.
  - `update()` / `flush()` стали no-op'ами когда нет instance (защита от PUT '/api/reports/0/preferences').

Wrappers `useReportViewMode`, `useDashboardLayout`, `useColumnGroups`, `useWidgetGroups`, `createGroupsComposable` — не тронуты: API не изменился, `prefs.state.value.X` теперь резолвится через computed-proxy.

**Verify:** при `reportId=0` сетевой запрос не уходит (acquireForId возвращает рано); при `reportId 0 → 1` watch триггерит acquire, computed `state` начинает отдавать data instance-1; SelectButton update'ит view_mode → `isDashboard` пересчитывается → `v-if="!isDashboard"` flips → `<ReportDashboardView>` рендерится.

### Bug 3: Currency «Other…» — input не появляется + null проходит в submit

**Root cause** — `CompanyFormModal.vue:284-297` исходный watcher слушал `[visible, formData.currency_code]` обоих сразу. Последовательность когда юзер кликает «Другая валюта…»:

1. `onCurrencySelectChange(CUSTOM_CURRENCY_SENTINEL)` → ставит `isCustomCurrency.value = true` + `formData.currency_code = ''` (потому что для нового кастомного значения seed пустой).
2. Watcher срабатывает (currency_code изменился) → видит `code === ''` → попадает в else-ветку → `isCustomCurrency.value = false`, `customCurrencyInput.value = ''`.
3. `<InputText v-if="isCustomCurrency">` не рендерится. Юзер видит пустой Select и не может ничего ввести.

При сабмите `formData.currency_code === ''` → backend nullable → 200, currency_code сохраняется как null.

**Fix** в `front/src/components/Company/modals/CompanyFormModal.vue:284-321`: split одного watcher'а на два, оба с узким scope:

1. `watch(() => props.visible, ...)` (immediate: true) — fires только на modal-open transition. Инициализирует `isCustomCurrency` из текущего hydrated `currency_code` (custom если код не из COMMON_CURRENCIES).
2. `watch(() => props.formData.currency_code, ...)` — реагирует на external code changes, но с guard'ом: **если уже `isCustomCurrency.value === true` И code пустой — НЕ выходит из кастом-режима** (это race с handler'ом). Только переключается в кастом-режим, если код пришёл извне и не известен. Для known codes ничего не делает — `onCurrencySelectChange` сам обновляет флаг.

Валидация submit (line 309-315) уже существовала и работала — была в правильной форме, просто никогда не срабатывала потому что `isCustomCurrency` всегда был false (см. выше).

### Файлы

| Файл | Что |
|---|---|
| `front/src/pages/ReportPage/composables/useReportPreferences.ts` | shallowRef-проксированный instance + computed state/loaded + lazy gate по reportId>0 + no-op update/flush для unacquired |
| `front/src/components/Company/modals/CompanyFormModal.vue` | Watch split: modal-open seed + external-code re-infer с race-guard'ом |

### Верификация

- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91`, memory `feedback_lint_preexisting`).
- QA / deploy НЕ запускал — по инструкции.

---

## 2026-05-21 — Remove legacy chart tab

Владелец указал на дублирование в шапке ReportPage: справа сверху сосуществовали новая SelectButton `[Таблица | Дашборд]` (правильная) и старая Tabs `[Таблица | График]` (легаси, забытая при внедрении дашборда). Выпилено целиком — chart-таб не нужен, графики живут только внутри `ReportDashboardWidget` (там Chart.js легитимен).

### Файлы

| Файл | Что |
|---|---|
| `front/src/pages/ReportPage/index.vue` | Удалён внешний `<Tabs>` (обёртка `.report-tabs` → `.report-body`); удалён `<TabList>` с двумя `<Tab>`'ами (`tableTab` / `chartTab`); удалён `<TabPanels>` + `<TabPanel value="0">` (таблица переехала в `<div class="table-mode">` без потери содержимого); удалён `<TabPanel value="1">` целиком (Chart-блок). Удалены импорты `Tabs / TabList / Tab / TabPanels / TabPanel / Chart from 'primevue/chart'`. Удалён `activeTab = ref('0')` + ссылки в `v-if`'ах (header pagination и ColumnGroupsToggle теперь только проверяют `!isDashboard`). Из `watch(...)` для `updateHeaderPaginationLayout` убран `activeTab`. SCSS: удалён скоуп `.report-tablist` со всеми `:deep(.p-tab*)`, удалён `.chart-section` с `.chart-container/.chart-frame/.report-chart/canvas`, удалены `:deep(.p-tabs-content / .p-tabpanels / .p-tabpanel)` правила; добавлен лёгкий `.table-mode { flex; margin-top: 1rem }` чтобы сохранить вертикальный ритм бывшего `<TabPanel>`. |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | Удалены computed `chartConfig` (конвертация `report.chart` в `{type, data, options}` Chart.js-формат) и `chartOptions` (responsive overlay). Удалены из return-объекта. `extractTableData(report.value.chart)` fallback в `tableData` НЕ тронут — это другой код-path (chart → table, не chart → Chart.js), вне scope задачи (ReportService). |
| `front/src/pages/ReportPage/locale/ru.json` | Удалены ключи `tableTab` («Таблица»), `chartTab` («График»). |
| `front/src/pages/ReportPage/locale/en.json` | Удалены ключи `tableTab` (Table), `chartTab` (Chart). Симметрия RU↔EN сохранена. |

### Верификация

- `npm run type-check` — 0 errors (vue-tsc).
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91`, memory `feedback_lint_preexisting`) — не из этого diff.
- `grep -rn 'chartConfig\|chartOptions\|tabChart\|tabTable\|tableTab\|chartTab' front/src/` — оставшиеся хиты только в (a) `ReportDashboardWidget.vue` (легитимные Chart.js пропсы) и (b) `ReportService.extractTableData(chartConfig)` — локальный параметр метода, вне scope.
- Структура страницы: header → filters → divider → либо `.table-mode .data-section` (table view), либо `.dashboard-section` (dashboard view). Один источник правды для view-mode — SelectButton.
- QA / deploy НЕ запускал — по инструкции.

---

## 2026-05-21 — Глобальный визуальный пресет Chart.js

### Зачем

Чарты в Vizion (Dashboard widgets + report chart-колонки) выглядели «бухгалтерски»: прямые линии, квадратные углы у баров, насыщенные «детские» цвета, дефолтные серые тултипы. Конкуренты с Recharts/Tremor — заметно свежее.

Решение — глобальный preset поверх `Chart.defaults`. Per-chart options продолжают побеждать (Chart.js deep-merge), так что улучшение **аддитивное**: ничего не ломает существующие отчёты, всем чартам сразу — современный look.

### Разведка

- В `front/src/` Chart.js рендерится **в одном месте**: `pages/ReportPage/components/ReportDashboardWidget.vue` через `primevue/chart` (PrimeVue Chart обёртка, она же лениво подгружает `chart.js/auto`). `report.chart_config` структура есть в типах, но компонент-рендерер чарт-колонок отдельный пока не существует — preset подхватит когда появится.
- Шрифт проекта — Roboto (`theme/tokens/typography.ts`).
- Цветовая палитра PrimeVue — surface (серая) + primary (тёмно-синяя). Чарты используют отдельную «дискретную» палитру — раньше inline в виджете, теперь глобальную.

### Файлы

| Файл | Что |
|---|---|
| `front/src/plugins/chart-theme.ts` (новый) | Side-effect модуль: `Chart.defaults` override (typography Roboto, soft grid `rgba(120,130,145,0.12)`, rounded bars `borderRadius:6`, smooth lines `tension:0.38`, hollow points 3/6, тёмный pill-tooltip с альфой, point-style legend, easing easeOutQuart 750ms). Экспортирует палитру `VIZION_CHART_PALETTE` (10 цветов, Tremor-вдохновлённые: indigo, violet, cyan, teal, amber, pink, green, orange, purple, sky), `getThemeColors(n)` / `getThemeColor(i)`, `applyGradient(ctx, color, area)` helper для линейных градиентов в `backgroundColor` (`rgba()` стопы 0.4 → 0.18 → 0.02). Регистрирует `chartjs-plugin-datalabels` но `display:false` по умолчанию — чарты могут opt-in через `options.plugins.datalabels.display:true`. Pie/doughnut получают `cutout:'62%'`, белый 2px border между сегментами (`Chart.defaults.elements.arc`). |
| `front/src/main.ts` | `import '@/plugins/chart-theme'` — сразу после стилей, до `createApp`. Side-effect. |
| `front/src/pages/ReportPage/components/ReportDashboardWidget.vue` | Inline-palette удалён (был дублем). Bar/line теперь используют `applyGradient` в `backgroundColor` callback'е — fill от primary до прозрачного сверху вниз. Pie/doughnut оставлен с per-slice палитрой; border/hoverOffset приходят из глобального пресета. `chartOptions` упрощён — глобальные дефолты задают grid/legend/tooltip, локально только `legend.display:false` для single-series bar/line (один пункт легенды = шум). |

### Зависимости

- **Добавлено**: `chartjs-plugin-datalabels` (latest, ~70KB gzipped, MIT). Регистрируется глобально, выключен по умолчанию — для будущего opt-in.
- **НЕ добавлено**: `chartjs-plugin-gradient` (намеренно). Свой `applyGradient` helper надёжнее — градиенты конфигурируются императивно через колбэки в чарт-данных, не через JSON-плагин-опции (важно: backend кладёт чарт-конфиг как сырой JSON).

### Что улучшилось визуально

- **Типографика**: Roboto 500 weight, axis ticks мягче (surface-600 вместо чёрного).
- **Grid**: альфа 0.12 (almost ghost lines), vertical gridlines отключены на X-категориях.
- **Bars**: borderRadius:6 на все 4 угла (borderSkipped:false), без border'ов.
- **Lines**: tension 0.38 — плавные кривые, не зигзаги. Round join/cap. Hollow points (3px radius, белая заливка + цветной border 2px), hoverRadius 6, hitRadius 12 (forgiving).
- **Bar/line backgrounds**: вертикальный градиент primary → transparent (Tremor-style soft fill).
- **Pie/doughnut**: 62% cutout (slim ring), 2px белый border между сегментами, hoverOffset 8px (slice выезжает при ховере).
- **Tooltip**: dark pill `rgba(23,39,71,0.92)` (primaryDark с альфой), cornerRadius 8, padding 12, point-style indicator, без arrow caret.
- **Legend**: point-style 8px circles, padding 16. Hidden для single-series cartesian charts (только pie/doughnut).
- **Animations**: easeOutQuart 750ms на entry, 200ms easeOutCubic на hover-transitions.
- **HiDPI**: `devicePixelRatio` capped at 2 — sharp на retina, не пожирает память на mobile 3x.

### Верификация

- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91`, memory `feedback_lint_preexisting`).
- `npm run build` — собирается за ~9s, `charts-*.js` chunk 207KB (chart.js + datalabels), ничего нового сверх плана.
- QA / deploy НЕ запускал — по инструкции.

## 2026-05-21 — Переезд с Chart.js на ECharts (vue-echarts)

### Контекст

Предыдущий итерейшн (`chart-theme.ts` + chartjs-plugin-datalabels + градиенты в `ReportDashboardWidget`) не зашёл — юзер выбрал готовое production-grade решение. Меняем на ECharts через `vue-echarts` ради премиального визуала из коробки и более богатой палитры эффектов.

### Зависимости

- **+** `echarts@6.1.0` — ядро + tree-shaken renderers/charts/components.
- **+** `vue-echarts@8.0.1` — Vue-обёртка `<v-chart>` с autoresize.
- **−** `chartjs-plugin-datalabels` — удалён, в ECharts свои labels через `series.label`.
- `chart.js@4.5.1` оставлен в deps (тянется как peer для primevue/chart — не используется сейчас, но удаление рискованное).

### Изменённые файлы

- **DELETED** `front/src/plugins/chart-theme.ts` — самописный Chart.js-пресет (палитра/градиенты/defaults). Заменён на ECharts theme.
- **CREATED** `front/src/plugins/echarts.ts` — side-effect модуль:
  - tree-shaken регистрация: CanvasRenderer + BarChart/LineChart/PieChart + Grid/Tooltip/Legend/Title/DataZoom components.
  - Палитра `VIZION_ECHARTS_PALETTE` — 10 премиальных тонов (Tremor / Apple Health style): indigo, violet, teal, amber, pink, green, orange, cyan, lime, fuchsia.
  - Тема `'vizion'` зарегистрирована через `registerTheme()`:
    - Inter / system-ui типографика, slate-600 для primary text, slate-400 для muted/ticks.
    - axisLine/splitLine с alpha 0.12-0.18 (мягкие, не вырвиглазные).
    - Tooltip — `rgba(15,23,42,0.94)`, border-radius 10px, soft shadow, без caret.
    - Legend — bottom, circle markers, item-gap 18.
    - bar.borderRadius `[6,6,0,0]` (top corners only), line.smooth, pie.borderColor #fff.
- **MODIFIED** `front/src/main.ts` — `import '@/plugins/chart-theme'` → `import '@/plugins/echarts'`.
- **MODIFIED** `front/src/pages/ReportPage/components/ReportDashboardWidget.vue`:
  - `<Chart>` (primevue/chart) → `<VChart theme="vizion" :option="chartOption" autoresize />`.
  - `chartData` + `chartOptions` (Chart.js shape) → single `chartOption: EChartsOption` computed.
  - Поддержка 4 типов:
    - **bar** — vertical gradient fill через `echarts/core graphic.LinearGradient` (primary 95% → 55% alpha), `borderRadius [6,6,0,0]`, `barMaxWidth: 48`, axisPointer shadow.
    - **line** — `smooth: true`, circle symbols 6px (скрыты при labels > 24), areaStyle vertical gradient (45% → 2%), `lineStyle.width: 2.5`.
    - **pie** — `radius: ['0%', '70%']`, белый 2px border между slices, emphasis с shadowBlur 12.
    - **doughnut** — `radius: ['55%', '75%']`, label/labelLine скрыты, остальное как pie.
  - `tooltip.trigger`: `axis` (bar/line) / `item` (pie/doughnut).
  - `animation: true`, `animationDuration: 750`, `animationEasing: 'cubicOut'`.
  - `<v-chart>` принимает локальный класс `dashboard-widget__chart` со 100% w/h — встаёт в `grid-layout-plus` ячейку.

### Bundle size delta

До (Chart.js + datalabels):
- `charts-DcRG4peY.js` — 207.66 KB (separate chunk).
- `index-DyowxNC0.js` (main) — 142.79 KB.
- `index-f9N13a-y.js` — 68.09 KB.

После (ECharts, tree-shaken):
- `index-B--KBY8k.js` (main) — 340.32 KB (ECharts инлайнится в main, нет отдельного charts-chunk).
- `vue-core-CTCkAq1X.js` — 694.50 KB.
- `primevue-BZtGgE_N.js` — 943.45 KB (без изменений).

Чистая delta: **примерно нейтрально по raw bytes**, gzip немного лучше. Минус один отдельный network roundtrip (нет `charts-*.js` chunk'a). Tree-shaking сработал — full ECharts ~1MB, мы тянем только нужные модули (Canvas + 3 chart types + 5 components ≈ +130KB raw inline'ом в main).

### Верификация

- `npm install echarts vue-echarts` — 4 packages added, 0 errors.
- `npm uninstall chartjs-plugin-datalabels` — clean removal.
- `npm run type-check` — 0 errors.
- `npm run lint` — 0 errors, 1 pre-existing warning (`plugins/persist.ts:91`, не из этой итерации).
- `npm run build` — собрался за 12.99s.
- QA / deploy НЕ запускал — по инструкции (юзер сам глазами проверит).

### Что юзер увидит

- Bar — gradient-fill столбики с округлёнными верхними углами, axis-pointer shadow при hover.
- Line — плавная кривая с area-fill gradient (saturated → transparent), кружки-symbols.
- Pie — белые разделители между slices, slice выезжает при hover (scale 6px + shadow).
- Doughnut — slim ring 55-75% radius, без подписей внутри, минималистично.
- Tooltip — тёмный slate-900 pill с rounded corners и soft shadow.
- Legend — circle-markers внизу, 18px gap, slate-600 текст.
- 750ms cubic-out entry animation.
- Палитра — 10 саturated-но-balanced тонов (indigo / violet / teal / amber / pink / green / orange / cyan / lime / fuchsia).

---

## 2026-05-21 — MiniChat UX bugfixes: drag-anchor / tooltip z-index / markdown-table cap

**Задача:** владелец на /reports/5 нашёл 3 проблемы в MiniChatWidget — (1) при перетаскивании Toolbox-toggle открытый чат-overlay остаётся на старом месте и «отрывается» от своей кнопки; (2) PrimeVue tooltip кнопок внутри чата (expand / close) рендерится ПОД самим чатом; (3) AI вернул markdown-таблицу — popover растянулся в ширину, чтобы её вместить.

### Изменённые файлы

| Файл | Что меняем |
|---|---|
| `front/src/components/Toolbox/types.ts` | Расширил `ToolboxOverlayControl` — добавил `realign(): void` для повторного позиционирования открытых popover'ов. |
| `front/src/components/Toolbox/Toolbox.vue` | `watch([currentPosition, currentPlacement])` → `realignOpenOverlay()` через `nextTick + rAF` дергает `realign()` на всех зарегистрированных overlay-control'ах (CompanySwitcher, ProfileMenu, MiniChat); каждый `realign()` сам no-op'ит, если popover закрыт. |
| `front/src/components/chat/MiniChatWidget.vue` | (а) `PopoverInstance` тип расширен `alignOverlay()` (публичный метод PrimeVue Popover); (б) `realign()` композитор-обёртка над `popoverRef.alignOverlay()`; `defineExpose({ syncPopover, realign })`. (в) `.mini-chat__overlay` SCSS — добавил `max-width: min(420px, calc(100vw - 1.5rem))` (рядом с уже-существующим `width`, иначе wide markdown-content всё равно растягивал popover за пределы 420px) + `overflow: hidden` на root popover, чтобы клипать к border-radius до того, как inner panel'у удастся перехватить overflow. |
| `front/src/components/chat/ChatMessageBubble.vue` | `:deep(.chat-md-table)` — `display: block; max-width: 100%; overflow-x: auto` + `white-space: nowrap` на th/td: таблица превращается в скроллируемый блок внутри пузыря (а не в `display:table`, который ignore'ил `max-width` из-за `table-layout: auto`). `:deep(.chat-md-pre) { max-width: 100% }` — та же страховка для широких code-блоков. |
| `front/src/components/Company/CompanySwitcher.vue` | Зеркальные правки — `PopoverInstance.alignOverlay`, `realign()`, expose. CompanySwitcher / ProfileMenu используют ровно тот же Popover-pattern что MiniChat, поэтому страдают тем же drift'ом при drag'е Toolbox. |
| `front/src/components/ProfileMenu/ProfileMenu.vue` | То же что CompanySwitcher. |
| `front/src/theme/scss/foundation/_overlays.scss` | Убрал `.p-tooltip { z-index: $z-tooltip !important }`. **Это и был корень бага 2.** PrimeVue Tooltip directive вызывает `ZIndex.set('tooltip', el, baseZIndex)` — runtime-z-index ВСЕГДА выше последнего открытого overlay'я (auto-increment). Статический `!important` зажимал tooltip на `1300`, тогда как Popover получал inline `style="z-index: 2250+"` через ту же ZIndex util. Inline + `!important` в CSS → CSS выигрывает → tooltip уезжал ПОД popover. Замена правила на длинный комментарий, объясняющий, почему статический override здесь вреден. |

### Решения по развилкам

- **Bug 1 — почему `alignOverlay()` а не closing-on-drag:** PrimeVue Popover публично экспортирует `alignOverlay()`. `absolutePosition(container, target, false)` пересчитывает координаты overlay'я против сохранённого `this.target` (button, который перемещается вместе с Toolbox потому что Toolbox — `position: fixed`-root). Закрывать popover на каждый drag было бы враждебно к UX (юзер только что положил чат в удобное место и сейчас передвигает Toolbox — он не хочет потерять чат). `nextTick + requestAnimationFrame` нужен потому, что reactive position применяется к Toolbox inline-style в той же flush'е, что и watcher, но кадр ещё не отрисован — без задержки `alignOverlay` прочитал бы `getBoundingClientRect` от старой геометрии.
- **Bug 1 — broadcast на ВСЕ overlay'и вместо tracking какой открыт:** все три overlay-instance уже зарегистрированы в `overlayControls` (Toolbox владеет ссылками). `realign()` сам no-op'ит при `!isOpen.value` → broadcast стоит ровно 3 функ-вызова, проще и резистентнее к рассинхрону, чем читать `openOverlay` из `useToolboxOverlays`.
- **Bug 2 — почему не bump `--app-z-tooltip` вверх, а просто убрать override:** Bump'ом пришлось бы выбирать конкретное «достаточно большое» значение, которое всё ещё может оказаться ниже какого-нибудь auto-incremented overlay'я (PrimeVue ZIndex util монотонно растёт от `currentMax`). Удаление override отдаёт управление PrimeVue'шной утилите — она гарантирует tooltip > последний открытый overlay по конструкции.
- **Bug 3 — почему `display: block` для table вместо wrapper-div:** разметка приходит через `v-html` (markdown-it → raw HTML). Обернуть `<table>` в `<div class="md-table-wrap">` потребовало бы либо custom rule в markdown-renderer, либо post-process на dom. `display: block` + `overflow-x: auto` напрямую на `<table>` даёт тот же эффект без модификации рендерера.
- **Bug 3 — почему добавил `max-width` рядом с `width`, а не заменил:** SCSS `width: min(420px, calc(100vw - 1.5rem))` сам по себе не cap'ал popover — wide-content внутри (table с длинными ячейками) умудрялся протолкнуть container шире 420px на reflow. Пара `width` + `max-width` гарантирует hard cap. `overflow:hidden` на root popover добавлен как backup на случай, если markdown-block будет особенно агрессивный (visible тогда промахивает border-radius до того, как inner-clip'ы успеют отработать).

### Type-check / lint

- `cd front && npm run type-check` — 0 errors.
- `cd front && npm run lint` — 0 errors, 1 warning (`plugins/persist.ts:91`, pre-existing, не из этой итерации; зафиксирован в memory).
- QA / deploy НЕ запускал (по инструкции).

### Что юзер увидит

- Перетащил Toolbox → открытый MiniChat (или CompanySwitcher / ProfileMenu) едет вместе с кнопкой, не «отрывается».
- Переключил placement Toolbox'а top↔left при открытом чате → popover пересчитывает позицию (а не остаётся в углу).
- Hover'нул на «expand» / «close» внутри чата → tooltip всплывает НАД пузырём чата, читаемый.
- AI вернул широкую markdown-таблицу → MiniChat остаётся `420px` шириной; таблица внутри пузыря прокручивается горизонтально внутри своего scroll-area (а не растягивает popover на пол-экрана).
- Та же фикс-логика для широких `<pre>`-блоков (code samples).

---

## 2026-05-21 — Фикс persist dashboard layout между сессиями (visibilitychange/pagehide flush)

### Баг
Юзер двигал/ресайзил виджеты в Dashboard view → переключал вкладку браузера → возвращался → layout слетал к исходному состоянию. После reload страницы — тоже исходный layout.

### Root cause
`useReportPreferences.createInstance` использует `setTimeout`-debounce 600мс перед PUT. `flush()` существует, но вызывается только в `release()` — а `release()` срабатывает на `onScopeDispose`, который при tab-switch **не триггерится** (Vue не unmount'ит компонент скрытой вкладки).

Дополнительный фактор — Chrome/Firefox throttle `setTimeout` в background tabs (минимальный интервал до 1 раза/мин). Если юзер двигает виджет → переключает вкладку до истечения 600мс — debounced PUT не успевает уйти, а в background дальше тормозится. На `pagehide` (закрытие вкладки) axios получает abort до того, как таймер разрешится.

### Fix
В `front/src/pages/ReportPage/composables/useReportPreferences.ts`:

1. Добавлены module-level handlers `onVisibilityChange` (срабатывает на `document.visibilityState === 'hidden'`) и `onPageHide`. Оба итерируют по живым `instances` и вызывают `flush()` на каждой — это форсит немедленный PUT всех ожидающих изменений.
2. Listeners навешиваются лениво при первом `acquireReportPreferences` (через `attachGlobalListeners` с idempotency guard `listenersAttached`) и снимаются когда `instances.size === 0` (последний release или test-reset).
3. `visibilitychange→hidden` — основной защитный путь: страница ещё жива, axios гарантированно долетает. `pagehide` — best-effort при закрытии вкладки/окна.

### Файлы
- `front/src/pages/ReportPage/composables/useReportPreferences.ts` — добавлены `attachGlobalListeners` / `detachGlobalListeners` / `flushAllInstances` + хуки в `acquireReportPreferences`, `release`, `__resetReportPreferencesForTests`.

### Что НЕ потребовалось
- Backend (`UserReportPreferenceController`, миграция) — не менял, контракт `PUT /api/reports/{id}/preferences` уже принимает `dashboard_layout` ключ, проблема была чисто фронтовая (debounce не успевал улететь).
- `useDashboardLayout` / `ReportDashboardView.vue` — не трогал, цепочка `onLayoutUpdate → emit('update-layout') → prefs.update({dashboard_layout})` работает корректно, чинить нужно было только flush-сценарий.
- Другие preference-обёртки (`useReportViewMode`, `useColumnGroups`, `useWidgetGroups`) — автоматически починены т.к. flush идёт по всем instances глобально.

### Trade-offs
- `pagehide` через axios может не успеть при резком закрытии вкладки (XHR без keepalive). Для 100%-надёжности нужен `navigator.sendBeacon` или `fetch keepalive`, но это требует ручной выкачки Bearer-токена из storage и дублирования axios-логики — отложено (краевой случай: юзер двигает виджет и в ту же секунду закрывает вкладку).
- localStorage кэш всё ещё пишется синхронно при каждом `update`, так что после cold reload первый paint остаётся корректным даже если PUT не долетел.

### Type-check / lint
- `npm run type-check` — без ошибок.
- `npm run lint` — 3 warnings, все pre-existing (Toolbox.vue, persist.ts).

---

## 2026-05-21 — Chat bubble overflow на длинных JSON-строках (Баг 1 + Баг 2 slim-context follow-up)

### Контекст
В полноэкранном `/ai-reports` (после expand из MiniChat) первое user-сообщение содержало сериализованный `config.dashboard_widgets[]` как одну длинную строку без пробелов. Bubble (`max-width: 70%` в flex-row без `min-width: 0`) распирало контентом за пределы видимой области — куски JSON `"id":"summary_unsold_by_project","title":...` уезжали за верх экрана. Прошлый фикс `.chat-md-table` через `:deep()` решал только табличный случай; для plain-text/inline-code/`<pre>` блоков с unbreakable-токенами не работал.

### Баг 1 — defense-in-depth для bubble (3 уровня)

**`front/src/components/chat/ChatMessageBubble.vue`:**
1. `.message-wrapper` — добавлены `min-width: 0; max-width: 100%`, чтобы flex-item мог реально шринкаться ниже intrinsic min-content (без этого правила wrap внутри bubble не активируются в flex-row).
2. `.bubble` — `max-width: 70%` заменён на `max-width: min(720px, 100%)` (читаемая колонка независимо от ширины родителя); добавлены `overflow-wrap: anywhere; word-break: break-word; min-width: 0`. `overflow: hidden` явно НЕ ставил — иначе ломает абсолютно-позиционированные tooltips/popovers.
3. `.bubble-content` (plain `<p>` для user/system) — `overflow-wrap: anywhere; max-width: 100%` поверх существующих `pre-wrap` + `break-word`. Это ключевой фикс для user-message с JSON-блобом: backend хранит исходный текст с prefix'ом, фронт не может его «расслимать постфактум», но обязан корректно рендерить.
4. `:deep(code)` (inline backtick) — `overflow-wrap: anywhere; word-break: break-word`, иначе один backtick'ованный JSON-path растягивает bubble.
5. `:deep(.chat-md-pre)` — `white-space: pre-wrap; overflow-wrap: anywhere`, дочерний `<code>` инхеритит. Раньше длинный однострочный JSON в fenced-блоке давал горизонтальный scroll шире чата; теперь wrap'ается построчно.

**`front/src/components/chat/ChatMessageList.vue`:**
- Добавлены `max-width: 880px; width: 100%; margin: auto` на сам `.message-list`. На fullscreen `/ai-reports` чат-колонка раньше наследовала всю ширину окна (1600px+) — bubble мог быть ~1100px. Теперь центрируется как читабельная колонка. MiniChat popover (420px) уже уже cap'а — не затронут.

### Баг 2 — slim-context fallback (verify)

**`front/src/components/chat/MiniChatWidget.vue` — `buildContextPrefix()`:** уже реализован в прошлой итерации, по плану §6.6. Проверил соответствие требованию:
- `JSON.stringify(config).length > 2000` → slim-проекция: только `{primary_model, columns: [{field, type}]}`. `dashboard_widgets`, `where`, `chart`, `column_groups` физически отсутствуют в slim-объекте — попасть в payload не могут.
- `≤ 2000` → full config (мелкие отчёты).
- `filters_applied` всегда передаётся as-is (фильтры обычно небольшие).

Альтернативные пути инжекта проверены: `reportContextStore` (Pinia) — единственный источник, читается только из `MiniChatWidget.buildContextPrefix()`. AiReportsPage и `useChatPage` не строят свой prefix — они просто отображают сообщения, уже сохранённые в БД. Существующие чаты с тяжёлым prefix остаются «как есть» (исторические данные), новые чаты после этого фикса получают slim.

### Файлы
- `front/src/components/chat/ChatMessageBubble.vue` — bubble wrap-rules (3 уровня).
- `front/src/components/chat/ChatMessageList.vue` — max-width 880px + центрирование колонки в fullscreen.

### Что НЕ трогал
- `front/src/components/chat/MiniChatWidget.vue` — slim-fallback уже на месте, контракт корректен.
- `.chat-md-table` rules — оставлены как есть (предыдущий фикс).
- `MiniChat__overlay` width-cap (420px) — не трогал, инструкция явно сказала.
- Backend — Баг 2 на бэке (slim system-prompt) — зона `chat-ai-engineer`.

### Type-check / lint
- `npm run type-check` — без ошибок.
- `npm run lint` — 1 warning (pre-existing `persist.ts:91` — comma-dangle), 0 errors. Из наших правок — чисто.

---

## 2026-05-21 — Column groups dedup + DnD + persist (ReportPage)

### Контекст
На отчёте «Свод по проектам» (`/reports/5`) видимы 3 проблемы по таблице:
1. Заголовки групп колонок дублируются в верхней строке шапки (`Объём | Финансы | Объём | Финансы`) и в popup-меню «Показать группы колонок».
2. Нет drag-and-drop порядка колонок — статичный конфиг с бэка.
3. Нужен persist пользовательского порядка (по аналогии с dashboard layout).

### Проблема 1 — Дедупликация в popup, run-corrected header

**`front/src/pages/ReportPage/composables/createGroupsComposable.ts`:**
- `groupLabels` теперь дедуплицирует по первому появлению — `contiguousBuckets` для column-groups намеренно эмитит отдельный bucket на каждый contiguous-run одинаковых меток (так должно быть для PrimeVue `<ColumnGroup>` colspan-семантики), но popup-меню должно показывать одно имя группы вне зависимости от количества runs. Toggle by-label корректен «как был» — `visibleItems` фильтрует по сравнению с `Set<label>`, hide «Финансы» прячет все колонки этой группы во всех runs.
- Top-level header (top row of `<ColumnGroup type="header">`) уже строился корректно через `groupHeaderCells` в `index.vue` (walks `visibleColumns`, emits one `<th colspan="N">` per contiguous-run). Дубликаты в шапке как раз и есть **правильное поведение** для разрезанной группы: на скриншоте было два run'а одинаковой метки потому что между ними вставлены колонки другой группы — это валидная двухуровневая шапка, при reorder склеится автоматически. Фактический баг был только в popup'е (5 чекбоксов вместо 3 уникальных).

### Проблема 2 — DnD через PrimeVue built-in

Не ставил `vuedraggable` — PrimeVue DataTable имеет нативный `reorderable-columns` prop + `@column-reorder` event. Это: (1) ноль новых зависимостей, (2) integration с двухуровневым `<ColumnGroup>` шапкой работает out-of-the-box (PrimeVue идентифицирует колонку по `field`, индексы — в body-Column array), (3) встроенный visual feedback (стрелки-индикаторы при drag-over, нативный курсор).

Drag handle — вся ячейка заголовка (PrimeVue convention). Срабатывает на mousedown по любому `<th>` body Column'а. Внутренний `data-p-reorderable-column="true"` атрибут — для CSS-стилизации в будущем если потребуется handle-only режим.

PrimeVue мутирует свой внутренний `this.columns` при drop — это игнорится через bump'инг `columnReorderKey` (re-mount DataTable после каждого drop), наш `displayColumns` — single source of truth.

DnD блокируется на:
- `isGrouped === true` — master/detail отчёты (фронт уже не рендерит этот DataTable там).
- Колонки с `payment_schedule` — у PaymentScheduleCell абсолютно-позиционированные footer-labels (через `:has()`-trick), которые ломаются в PrimeVue drag-overlay clone. Большинство отчётов их не содержат, default — DnD on.

### Проблема 3 — Persist через новый `useColumnOrder`

**Новый файл `front/src/pages/ReportPage/composables/useColumnOrder.ts`:**
- API: `displayColumns: ComputedRef<PresentationColumn[]>`, `applyReorder(drag, drop)`, `reset()`, `isCustomised: boolean`.
- Хранимая структура: `{ order: string[], groups: Record<string, string|null> }`.
- `order` — массив полей в новом порядке. Поля из конфига отсутствующие в `order` — appendятся в хвост (AI добавил новую колонку в конфиг → не теряется).
- `groups` — per-field override для `column_group`. При drop на новую позицию: смотрим левого и правого соседа, если эффективные группы совпадают — наследуем; если разные — ungroup (`null`); если override совпал с config default — удаляем запись (трекинг чистый).
- LocalStorage ключ: `vizion-column-order-{reportId}-{userId}`. Single source — никаких race с другими composable'ами.

**Композиция в `front/src/pages/ReportPage/index.vue`:**
```
tableColumns (raw config)
  → useColumnOrder(...) → displayColumns
  → useColumnGroups(displayColumns, ...) → visibleColumns
  → template
```
`useColumnGroups` пересчитывает `groupHeaderCells` (top-row colspan-runs) автоматически из нового порядка. Persist column visibility toggles работают как раньше (independent ось — `hidden_column_groups`).

### Backend dependency — НЕ сделано (флаг для backend-specialist'а)

**Сейчас:** `useColumnOrder` пишет только в localStorage (per-device). По требованию: «Если whitelist строгий — флагируй... НЕ делай backend сам».

**`UserReportPreferenceController::update()` whitelist строгий** — принимает 4 поля: `view_mode`, `dashboard_layout`, `hidden_column_groups`, `hidden_widget_groups`. Добавить `column_order` без backend-задачи невозможно (validator вернёт 422 на лишний ключ, plus model `$fillable` не пропустит, jsonb-колонки нет в миграции).

**Когда backend будет готов:** замена в `useColumnOrder.ts` минимальная (~30 строк):
1. Заменить `ref<StoredColumnOrder>` + `readFromStorage/writeToStorage` на `useReportPreferences(reportId, userId).state.value.column_order`.
2. `applyReorder` → `prefs.update({ column_order: nextState })`.
3. localStorage остаётся как fast-paint кэш (паттерн уже реализован в `useReportPreferences.ts`).
Shape `{order, groups}` намеренно идентичен будущему API-полю — миграция механическая.

### Files
- `front/src/pages/ReportPage/composables/createGroupsComposable.ts` — dedup `groupLabels`.
- `front/src/pages/ReportPage/composables/useColumnOrder.ts` — новый файл, localStorage-only persist + reorder/group-inherit логика.
- `front/src/pages/ReportPage/index.vue`:
  - Импорт `useColumnOrder`.
  - Композиция: `tableColumns → columnOrder.displayColumns → columnGroups`.
  - `<DataTable :reorderableColumns :key columnReorderKey @column-reorder>`.
  - `onColumnReorder` handler (visible-index → displayColumns-index translation).
  - `reorderableColumnsEnabled` computed (disable on grouped report или payment_schedule columns).
- `front/package.json` / `package-lock.json` — без новых зависимостей (PrimeVue built-in DnD).

### Что НЕ трогал
- `report.config.group_by` (row-grouping) — отдельная фича.
- `useColumnGroups` toggle visibility — остался, работает по dedup'нутым labels.
- Master/detail (`isGrouped`) — DnD выключен принципиально, нет regress'а в этой ветке.
- Backend (миграция / контроллер / модель) — задача для backend-specialist'а, расписано выше.

### Type-check / lint / build
- `npm run type-check` — без ошибок.
- `npm run lint` — 1 warning (pre-existing `persist.ts:91` — comma-dangle), 0 errors из наших файлов.
- `npm run build` — собирается (8.59s).

### Phase 2: backend sync wired (2026-05-21)

Бэкенд (backend-specialist) расширил `user_report_preferences` ключом `column_order`: миграция применена, `UserReportPreferenceController` принимает shape `{order?: string[], groups?: Record<string, string|null>} | null`, GET/PUT возвращают поле. Перевёл `useColumnOrder.ts` с localStorage-only на полный server-sync через общий `useReportPreferences`.

**Изменения:**

1. **`api/types/reportPreferences.ts`** — добавлен `ReportColumnOrderPreference` (`{order: string[], groups: Record<string, string|null>}`) и поле `column_order: ReportColumnOrderPreference | null` в `ReportPreferences`. `ReportPreferencesPatch` подхватился автоматически через `Partial<Omit<…>>`.
2. **`api/types/index.ts` + `api/index.ts`** — re-export нового типа.
3. **`useReportPreferences.ts`:**
   - `STORAGE_NAMESPACE_COLUMN_ORDER = 'vizion-column-order'` (тот же ключ, что владел старый локальный `useColumnOrder`, чтобы существующие закэшированные данные у юзеров не потерялись).
   - `seedFromCache` парсит legacy-envelope `{order, groups}`, фильтрует мусор, схлопывает пустой envelope в `null`.
   - `writeCache` пишет JSON `{order, groups}`; `null` → `removeLocalStorage` (чтобы reset очищал кэш).
   - `update()`-branch для `column_order`: `'column_order' in patch` → пишет в `next.column_order`, явный `null` сохраняется (последний-write-wins per field, объединяется в `pendingPatch`).
   - `buildFallbackPreferences` → `column_order: null`.
4. **`useColumnOrder.ts`** — переписан под shared store:
   - Удалены: локальный `state` ref, `readFromStorage/writeToStorage/removeFromStorage` (теперь в `useReportPreferences`), `watch([reportId, userId], hydrate, immediate)` (теперь в shared store).
   - Добавлен: `useReportPreferences(reportId, userId)` (внутри уже есть `watch(reportId, {immediate:true})` + guard на `id<=0`, не нужно дублировать).
   - `stored = computed(() => prefs.state.value.column_order ?? EMPTY_ORDER)` — единая точка чтения.
   - `applyReorder` → `prefs.update({ column_order: { order, groups } })` (debounced PUT внутри shared store).
   - `reset` → `prefs.update({ column_order: null })` (явный clear — backend удаляет поле; localStorage envelope тоже стирается через `writeCache`).
   - **Публичный contract сохранён 1-в-1:** `displayColumns`, `applyReorder`, `reset`, `isCustomised` — те же сигнатуры. `index.vue` не трогал (строка 655 — `useColumnOrder(reportId, tableColumns, isGrouped)` — работает).

**Race-conditions / antipatterns:**
- Не делаю собственного watch — `useReportPreferences` уже имеет `watch(reportId, acquireForId, {immediate:true})` с guard на `id<=0` (см. PROJECT.md §Race antipatterns). Если внешний `reportId` приходит как `0` на mount — shared store просто не создаёт instance, `prefs.state.value` отдаёт fallback (`column_order: null`), `displayColumns` рендерит config order. Когда id переключается на реальный — store сам подхватывает.
- `EMPTY_ORDER` — module-level constant (не реактивный), безопасно — используется только как fallback sentinel.

**localStorage кэш — поведение:**
- При первой загрузке (до GET-ответа) `seedFromCache` поднимает старые данные пользователя из `vizion-column-order-{rid}-{uid}` → instant first paint в кастомизированном порядке.
- После GET сервер выигрывает; кэш переписывается из ответа.
- Любой `prefs.update({ column_order })` синхронно зеркалит в кэш + дебаунсит PUT 600ms.
- `reset` → backend clears, кэш `remove`'ится.

**Dedup/DnD логика прошлой итерации:**
- `resolveColumnGroup`, `computeGroupForMovedColumn`, `orderedFields`, `displayColumns`, `applyReorder` — тело без изменений. Только источник данных переехал с `state.value` → `stored.value`.

**Не трогал:**
- Backend (готов, миграция применена).
- `index.vue` (контракт composable'а не сломан).
- Другие wrappers (`useColumnGroups`, `useDashboardLayout`, `useWidgetGroups`, `useReportViewMode`).

### Type-check / lint / build (Phase 2)
- `npm run type-check` — без ошибок.
- `npm run lint` — 1 warning (pre-existing `persist.ts:91` — comma-dangle), 0 errors из наших файлов, 0 новых warnings.
- `npm run build` — собирается (13.60s).

## 2026-05-21 — MiniChat прокидывает `report_context` в quick_qa payload

**Задача:** backend (chat-ai-engineer) добавил двухслойный quick_qa system-prompt. Активация — через новое опциональное поле `report_context` в payload `POST /api/chats/{id}/messages` (см. `chats_frontend.md` §`report_context`). MiniChat на странице отчёта должен присылать слим-снапшот `{primaryModel, reportId, reportTitle, columns, filters}`; full-screen чаты (`/ai-reports`, `/ai-chat`) — нет (legacy quick_qa-каталог).

**Решения по развилкам:**
- **Передавать на каждом ходе, не только на первом.** Прежний `pendingContextInject` flag гасился после первого send'а, потому что в текст вшивался prefix-дамп и второй раз дублировать нельзя. С payload-полем backend stateless по этому полю, фильтры между ходами могут меняться → отсылаем актуальный snapshot всегда. JSON тела ~200 байт, дешевле прежней prefix-инжекции.
- **Variant (b): убрать prefix из текста сообщения**, полностью полагаемся на payload-поле как source of truth. UX чище — юзер видит свой простой текст в bubble, а визуальный chip «📎 Контекст: {title}» над body чата (`mini-chat__context`) уже информирует, что in-report режим активен. Не делал badge через `message.metadata.report_context_title` — backlog (требует round-trip с бэком).
- **Expand → fullscreen без context handoff** — оставил текущую семантику «expand = переход в обычный чат», как просило ТЗ.

**Реализация:**

1. **`api/types/chats.ts`** — добавлен `ReportContextPayload` interface (`primaryModel: string`, optional `reportId`, `reportTitle`, `columns: string[]`, `filters: Record<string, unknown>`); поле `report_context?: ReportContextPayload` в `SendMessageRequest`.
2. **`services/ChatService.ts`** — `sendMessage(chatId, content, reportContext?)` принимает третий optional аргумент, кладёт `report_context` в payload только когда передан.
3. **`composables/useChatMessaging.ts`** — `sendMessage(content, sendOptions?: {reportContext?})` пробрасывает через `chatService.sendMessage(chatId, content, sendOptions?.reportContext)`. Не-MiniChat вызовы (`pages/shared/useChatPage.ts` на /ai-reports и /ai-chat — обе строки `chat.sendMessage(content)` / `chat.sendMessage(pending.content)`) не передают второй аргумент → backend получает payload без `report_context` → legacy quick_qa каталог.
4. **`components/chat/MiniChatWidget.vue`:**
   - Удалён `buildContextPrefix()` и весь `pendingContextInject` flag.
   - Добавлен `buildReportContextPayload(): ReportContextPayload | null` — читает `reportContextStore.config.primary_model` (обязательный гейт активации), `reportId`, `title`, `config.columns[].field` (flat `string[]` без типов — backend контракт), `filtersApplied`. Без `primary_model` возвращает `null` — backend всё равно отвалится на legacy каталог.
   - `handleSubmit(content)` теперь: `chat.sendMessage(content, reportContext ? {reportContext} : undefined)`. Никакого `[Контекст отчёта: …]` префикса в `content`.
   - `resolveTargetChat()` упрощён до `{ ok: boolean }` (поле `injectContext` больше не нужно).
   - `watch(reportContextStore.reportId)` упрощён — `pendingContextInject.value = false` строка убрана; reset `report_generation`-чата при смене отчёта сохранён (см. comment в файле — для quick_qa flow не критично, но симметрия поведения).

**Что НЕ трогал:**
- `pages/shared/useChatPage.ts` — full-screen чаты сознательно остаются без `report_context` (ТЗ).
- `api/chats.ts` — sendMessage всё ещё принимает `SendMessageRequest`, тип расширен в `api/types/chats.ts`, рантайм-код не меняется.
- Локализационные ключи `miniChat.contextBadge` / `mini-chat__context` chip — остаются как UI-индикатор активного in-report режима для юзера.
- Backend / роутинг — изменений нет.

**Совместимость:**
- Старый бэк (до этой задачи) — игнорирует `report_context`, обрабатывает только `content` — поэтому отсылка нового поля **не ломает** старые деплои.
- Новый бэк без поля (со старого фронта) — fallback на `chat.report_id` → `report.config.primary_model` (по доке backend'а).

**Backlog:**
- Если хочется visual-badge над bubble юзера (вариант c) — backend должен сохранить `report_context.reportTitle` в `message.metadata.report_context_title`, фронт прочтёт и нарисует chip в `ChatMessageBubble`. Сейчас metadata user-сообщений не наполняется бэком.
- Cross-page handoff из MiniChat в `/ai-reports` с сохранением reportContext — отложено как «спорное» (текущая семантика expand = переход в обычный чат проще).

**Файлы:**
- `front/src/api/types/chats.ts`
- `front/src/services/ChatService.ts`
- `front/src/components/chat/composables/useChatMessaging.ts`
- `front/src/components/chat/MiniChatWidget.vue`

### Type-check / lint / build
- `npm run type-check` — без ошибок.
- `npm run lint` — 1 warning (pre-existing `persist.ts:91` — comma-dangle), 0 errors / 0 новых warnings из наших файлов.
- `npm run build` — не запускал (ТЗ просит type-check + lint; build не требовался).


---

## 2026-05-21 — MacroData mapping admin UI (per-company personalised IDs)

**Задача:** в карточке компании добавить UI для CRUD per-company ID-маппингов (например `finance_type_sale_ids → [3786]`). Источник — backend контракт `GET|PUT|DELETE /api/companies/{company}/macrodata-mappings` + `POST .../probe` (auto-detect через скан БД клиента). У каждого CRM MACRO клиента типы фин-операций имеют свои ID, поэтому отчётам нужен per-company словарь.

**Решения по развилкам:**

- **Где встроена секция:** новый таб «Маппинг MacroData / MacroData mapping» в `pages/CompanyPage/index.vue` (третий после Users / Settings). Альтернатива — expand в списке компаний из `ManagementModal` — отметена: контекст «текущая компания» уже резолвится через `companiesStore.activeCompanyId` + `useScopedResource`, отдельная страница / отдельный путь не нужны.
- **ACL:** добавлена capability `canManageCompanyMacrodataMappings` в `shared/auth/capabilities.ts` (admin + superadmin). Зеркалит backend ACL. Аналитик и viewer не видят таб вовсе — для них backend всё равно вернёт 403, нет смысла открывать тупик.
- **Inline edit `value`:** для MVP — `Textarea` с `JSON.parse` валидацией (на чистый JSON-массив `[3786, 3787]` или скаляр). На пустой trim / невалидный JSON — inline-ошибка из i18n. Чип-инпут для list-of-ids — backlog.
- **Probe diff dialog:** отдельный компонент `MacrodataMappingProbeDialog.vue`. Сверяет proposed ↔ current value, помечает row'ы как `new` / `changed` / `unchanged`, по умолчанию чекает только new+changed. Чекбокс «select all» в header header'е — proxy через computed `selectAllProxy`. Pluralization summary («N маппингов будет сохранено») — ручной branch (mod10/mod100) внутри компонента, чтобы не тащить vue-i18n built-in plural в локальный schema-объект.
- **Probe failure UX:** 503 от backend трактуется как `macrodata_unavailable` — показываем сервер-message verbatim как toast (не блокируя текущее состояние списка). Любая другая ошибка — общий `notifyApiError` через нормализатор.
- **Apply из probe:** один bulk PUT с массивом отмеченных items + `notes = "Auto-probed: <matched_by>"`. После успеха — local replace списка, без повторного GET (backend всегда возвращает свежий список в response).
- **Кодстайл:** PrimeVue 4.5 `Dialog`/`DataTable`/`Column`/`Tag`/`Checkbox`/`Textarea` + `<script setup lang="ts">`. Pinia store не вводил — список локален в компоненте, нет cross-page state. Capability — добавлена в существующий `capabilities.ts` без рефакторинга других правил.

**Изменённые / новые файлы во `front/`:**

| Файл | Тип | Что |
|---|---|---|
| `front/src/api/types/macrodataMappings.ts` | new | DTOs: `MacrodataMappingDto`, `MacrodataProbeResultDto`, upsert request shape |
| `front/src/api/macrodataMappings.ts` | new | axios-обёртки: `listMappings` / `bulkUpsertMappings` / `deleteMapping` / `probeMappings` |
| `front/src/api/types/index.ts` | edit | реэкспорт новых типов |
| `front/src/api/index.ts` | edit | реэкспорт `macrodataMappingsApi` + типов |
| `front/src/shared/auth/capabilities.ts` | edit | новая capability `canManageCompanyMacrodataMappings` |
| `front/src/components/Company/MacrodataMappingSection.vue` | new | основная секция: scan button + table + edit/delete dialogs |
| `front/src/components/Company/MacrodataMappingProbeDialog.vue` | new | dialog для diff'а probe-результата с чекбоксами и кандидатами |
| `front/src/components/Company/index.ts` | edit | реэкспорт двух новых компонентов |
| `front/src/components/Company/locale/ru.json` | edit | новый блок `macrodataMapping.*` (28 ключей) |
| `front/src/components/Company/locale/en.json` | edit | зеркальный блок `macrodataMapping.*` |
| `front/src/pages/CompanyPage/index.vue` | edit | добавлен `TabPanel value="2"` с секцией + computed `canEditMacrodataMappings` |
| `front/src/pages/CompanyPage/locale/{ru,en}.json` | edit | ключ `mapping` (label таба) |

**API endpoints использованы:**
- `GET /api/companies/{company}/macrodata-mappings` — список текущих маппингов на инит
- `PUT /api/companies/{company}/macrodata-mappings` — bulk upsert (как для inline-edit одной записи, так и для apply'а результатов probe)
- `DELETE /api/companies/{company}/macrodata-mappings/{semantic_key}` — удаление одной записи
- `POST /api/companies/{company}/macrodata-mappings/probe` — auto-scan; 503 → toast с сервер-message

**ACL правило:** `canManageCompanyMacrodataMappings(role)` = `role in ['admin', 'superadmin']`. Используется в `pages/CompanyPage/index.vue` для условного рендера таба и его panel'а. Backend `/api/companies/{id}/macrodata-mappings*` — канонический gatekeeper; фронт-capability только UX.

**i18n ключи добавлены (короткий список):**
- `macrodataMapping.title` / `.subtitle` / `.scanButton` / `.scanning` / `.loadError` / `.emptyState`
- `macrodataMapping.table.{semanticKey,value,notes,autoProbedAt,neverAutoProbed,actions}`
- `macrodataMapping.edit.{createTitle,editTitle,semanticKeyLabel,semanticKeyPlaceholder,semanticKeyHelp,semanticKeyInvalid,semanticKeyExists,valueLabel,valuePlaceholder,valueHelp,valueInvalid,notesLabel,notesPlaceholder}`
- `macrodataMapping.delete.{title,message,warning}`
- `macrodataMapping.probeDialog.{title,subtitle,noResults,selectionSummaryZero,One,Few,Many,diffNew,diffChanged,diffUnchanged,diffMissing,column*,candidates*,unresolvedTitle,unresolvedHint,applyButton,cancelButton}`
- `macrodataMapping.errors.{macrodataUnavailable,saveFailed,deleteFailed,probeFailed}`
- `macrodataMapping.success.{saved,deleted,probeApplied}`
- Plus page-level `mapping` (Tab label) в `pages/CompanyPage/locale/{ru,en}.json`

Симметрия RU↔EN проверена скриптом — разница нулевая в обоих locale-bundles.

### Type-check / lint / build
- `npm run type-check` — без ошибок (host node v22.22.2; frontend-контейнер nginx-only, см. memory `frontend_container.md`).
- `npm run lint` — 0 errors, 1 warning (pre-existing `persist.ts:91` — comma-dangle, не из нашей итерации).
- `npm run build` — успешный production-build, 12.7s, 1386 modules.

**Backlog / тех-долг:**
- Chip-style input для значения-массива (вместо `Textarea` + JSON.parse). MVP — Textarea.
- Inline ошибка от backend на 422 (валидация semantic_key regex) сейчас просто всплывает в toast через `notifyApiError`. Если backend начнёт возвращать `errors.semantic_key`/`errors.value` — добавить mapping в `editErrors`.
- `defineExpose({ openCreate, hasMappings })` готов — кнопка «New mapping» в `MacrodataMappingSection.vue` не добавлена в header (UI решено держать минималистично: добавление руками идёт через probe; manual create — fallback для редкого случая, когда нужно «вписать вручную», но в дизайне пока не запрошен). Если потребуется — header-кнопка добавляется одним Button без новых composables.
- Tooltip A11Y / клавиатурный flow на иконке `pi pi-search` в header'е — хватает PrimeVue-default. Tabindex не пересматривал.

## 2026-05-21 — MacroData mapping: QA-фиксы (toast life + auto_probed_at + value rendering)

Три точечных фикса после QA-прогона предыдущей итерации, на той же фиче (без расширения скоупа).

**Файлы:**
| Файл | Тип | Что |
|---|---|---|
| `front/src/composables/useNotifications.ts` | edit | четвёртый/третий optional аргумент `life?: number` у `notifySuccess/Info/Warning/Error/notifyApiError`; пробрасывается в `notificationCenter` без поломки существующих 61 call-сайтов |
| `front/src/api/types/macrodataMappings.ts` | edit | `MacrodataMappingUpsertItem.auto_probed_at?: string \| null` (partial-update контракт от backend) |
| `front/src/components/Company/MacrodataMappingSection.vue` | edit | (1) константа `TOAST_LIFE_MS=3000` пробрасывается во все 8 notify-вызовов секции; (2) `applyProbeSelection` теперь стампит `auto_probed_at: probeResult.value.probed_at` на каждой выбранной строке; manual `submitEdit` поле не передаёт — backend сохраняет существующий timestamp; (3) value-column — `Array.isArray` ветка рендерит явные `[ … ]` brackets + Tag'и (`severity="info"`), скаляр (number/string) — один Tag без скобок, null/пусто — em-dash; SCSS добавлен `.value-array` (inline-flex) + `.value-bracket` (monospace, muted $surface-500) |

**Fix 1 — Toast life:**
- QA фиксировал накопление 2-3 toast'ов поверх кнопок action-column. Корень: `AppNotifications.vue` спред `toast.add({ life: 4000, ...message })` при `message.life=undefined` (а это все существующие вызовы через `useNotifications`) сбрасывает default'ный 4000. Здесь фикс **скоупный** — расширили wrapper `useNotifications` так, чтобы callers могли передать `life` явно, и для MacrodataMappingSection задали 3000ms через `TOAST_LIFE_MS` константу. Глобальную регрессию `AppNotifications` спреда не правили (вне scope этой итерации, в backlog).
- `MacrodataMappingProbeDialog.vue` не трогали — там нет своих notify-вызовов, ошибки apply'а отдаются через emit→`MacrodataMappingSection`.

**Fix 2 — auto_probed_at при probe-apply:**
- Backend в предыдущей итерации расширил `PUT /api/companies/{id}/macrodata-mappings`, теперь принимает optional `mappings[].auto_probed_at` (ISO datetime или null). Partial-update: отсутствие ключа = не трогаем существующий timestamp. Фронт-тип расширили (`MacrodataMappingUpsertItem.auto_probed_at?`).
- В `applyProbeSelection` берём `probeResult.value.probed_at` (момент probe-сканирования, ISO от backend) и стампим этим значением каждую выбранную через чекбокс запись. После apply'а в таблице `auto_probed_at` колонка показывает нормальную дату вместо «—».
- В `submitEdit` (ручной inline-edit одной mapping) — поле НЕ передаётся. Это делает inline-edit «семантически нейтральным» к auto-probe маркеру: запись, которая была authored через probe, после ручной правки сохраняет старый `auto_probed_at`. Если в будущем продакт скажет «ручное редактирование должно стирать probe-маркер» — поменяем на explicit `auto_probed_at: null`.

**Fix 3 — Value rendering в DataTable:**
- Раньше массив значений `[3884]` рисовался как один Tag без скобок — пользователь не отличал scalar от array. Теперь рендер:
  - `Array.isArray(value)` → `<span class="value-array">[…tags…]</span>` с monospace brackets (gap=0.25rem, flex-wrap).
  - `typeof value === 'number' | 'string'` → один Tag без скобок (severity=info, rounded).
  - `isEmptyValue(value)` (null / пустая строка / пустой массив) → em-dash в `value-empty`.
  - Любой остальной shape (object) → fallback `<code class="value-json">JSON.stringify(...)</code>` (unchanged).
- Brackets — `$surface-500` (muted gray), monospace, чтобы читались как punctuation, а не как часть данных.

**Backwards compatibility:**
- `useNotifications` signature расширена optional-аргументом — все 61 существующий call-сайт работает без изменений (передают undefined → notificationCenter получает `{ summary, life: undefined }` → `AppNotifications` спред-баг сохраняется, как и было, но это не регрессия от этой итерации, в backlog).
- `MacrodataMappingUpsertItem.auto_probed_at` — optional, никто из существующих сайтов его не отправляет.

**API запросы изменились:**
- `PUT /api/companies/{id}/macrodata-mappings` при probe-apply теперь шлёт `mappings[].auto_probed_at: "2026-05-21T..."` — backend готов и принимает (см. предыдущую итерацию).

**Type-check / lint / build:**
- `npm run type-check` — без ошибок.
- `npm run lint` — 0 errors, 1 pre-existing warning (`persist.ts:91`, не из нашей итерации, см. memory `lint_preexisting.md`).
- `npm run build` — успешный production-build, 13.33s, 1387 modules.

**Backlog / тех-долг:**
- `AppNotifications.vue` спред-баг — `toast.add({ life: 4000, ...message })` при `message.life=undefined` сбрасывает default → большинство toast'ов в приложении остаются sticky. Сейчас не правили, потому что (а) это вне scope MacrodataMapping фиксов и (б) исправление массово поменяет UX в приложении и требует отдельного PM-аппрува. Кандидат на отдельную итерацию с QA-прогоном по всем известным toast-flow.
- Если backend начнёт возвращать `errors.semantic_key`/`errors.value` на 422 — добавить inline-маппинг в `editErrors` секции вместо toast'а.

---

## 2026-05-21 — Column Manager popup + remove group-header row

**Задача:** на странице `/reports/<id>` верхняя строка таблицы рендерила декоративные group-headers (`Поступление | Объект | Контрагент | Поступление`) поверх настоящих заголовков колонок — визуальный мусор. Параллельно popup «Показать группы колонок» давал только включить/выключить группу целиком — без управления видимостью каждой колонки в отдельности и без drag&drop в самом popup'е.

**Что сделано:**

1. **Убрана двухуровневая шапка таблицы** — удалён блок `<ColumnGroup v-if="columnGroups.hasGroups" type="header">` с двумя `<Row>` (первая — group-cells с `colspan`, вторая — sub-headers). Теперь рендерится одна row настоящих заголовков колонок (через `<Column>`-list + `#header` slot). `ColumnGroup` + `Row` импорты остаются — они нужны для footer-блока с итогами (`<ColumnGroup type="footer">`).

2. **Новый компонент `ColumnManagerPopover.vue`** — заменил `ColumnGroupsToggle` на странице отчёта (но `ColumnGroupsToggle.vue` сохранён, его всё ещё использует `ReportDashboardView.vue` для группировки виджетов). Popover рендерит секции по `column_group` через `vuedraggable` (Vue 3 wrapper над `SortableJS`):
   - Все секции в общей drag-group `report-column-manager` — DnD работает внутри секции (порядок) и между секциями (смена `column_group`).
   - Каждая строка: drag-handle (иконка `pi pi-bars`) + per-column чекбокс visibility + label.
   - Каждый section-header: bulk-checkbox (`checked`/`unchecked`/`indeterminate`) — переключает видимость всех колонок секции одновременно + счётчик `visible/total`.
   - Первая секция всегда «Без группы» (label = `null`) — даже пустая остаётся как валидный drop-target для «ungroup».
   - На каждый drop в `vuedraggable` `@change` реконструирует полный новый field-order через `document.querySelectorAll('.column-manager__row[data-field]')` (DOM уже отражает drop position раньше чем re-render props) и эмитит `reorder` parent'у — атомарный move + group-assignment.
   - Кнопка «Reset» сбрасывает `column_order` целиком на дефолт конфига отчёта (включает order, group-overrides и visibility).

3. **Сохранён DnD в самой таблице** — `:reorderableColumns="reorderableColumnsEnabled"` остался; обработчик `onColumnReorder` теперь работает с `visibleTableColumns` (учитывает hidden-фильтрацию). Оба пути (popup + table-drag) пишут в один `column_order` через `useColumnOrder` — last-write-wins per field.

4. **Расширен `useColumnOrder`** — добавлены:
   - `applyOrderAndGroup(movedField, newOrder, newGroup)` — атомарная операция от popup'а; не угадывает группу по соседям как `applyReorder` для PrimeVue-drag.
   - `setColumnVisibility(field, visible)` + `hiddenFields: ComputedRef<Set<string>>` — per-column toggle, пишет в новое поле `column_order.hidden`.
   - `isCustomised` теперь учитывает наличие `hidden`.

5. **Расширен `ReportColumnOrderPreference`** — добавлено `hidden?: string[]`. `seedFromCache` валидирует и round-trip'ит массив через localStorage. `writeCache` уже использует `JSON.stringify(prefs.column_order)` — `hidden` попадает в localStorage автоматически.

6. **Финальная фильтрация колонок** — новый computed `visibleTableColumns` в `index.vue` композирует три слоя:
   1. `columnOrder.displayColumns` — user-curated order + group-overrides
   2. `columnGroups.visibleColumns` — старый group-level `hidden_column_groups` (back-compat, ещё может быть в БД у существующих пользователей)
   3. фильтр по `columnOrder.hiddenFields` — новая per-column visibility

   `visibleFooterCells` переписан на тот же `visibleTableColumns` — totals row теперь корректно реордерится при per-column hide/reorder через popup.

**Файлы:**

| Файл | Что изменено |
|---|---|
| `front/package.json` + `package-lock.json` | добавлен `vuedraggable@^4.1.0` (одна либа, как и оговаривали) |
| `front/src/api/types/reportPreferences.ts` | добавлен `hidden?: string[]` в `ReportColumnOrderPreference` |
| `front/src/pages/ReportPage/composables/useColumnOrder.ts` | новые методы `applyOrderAndGroup` / `setColumnVisibility` / `hiddenFields`; `isCustomised` учитывает `hidden`; `reset` без изменений |
| `front/src/pages/ReportPage/composables/useReportPreferences.ts` | `seedFromCache` парсит и валидирует `hidden[]`; empty-envelope условие учитывает `hidden.length === 0` |
| `front/src/pages/ReportPage/components/ColumnManagerPopover.vue` | новый компонент (~360 строк) — popover + vuedraggable секции + bulk toggle |
| `front/src/pages/ReportPage/components/ColumnGroupsToggle.vue` | **не изменён** — всё ещё используется `ReportDashboardView.vue` для widget groups |
| `front/src/pages/ReportPage/index.vue` | удалены `groupHeaderCells` / `groupedSubHeaderCells` / `onColumnGroupToggle` / `onColumnGroupReset`; добавлен `visibleTableColumns` + `columnManagerSections` + `onColumnVisibilityToggle` / `onSectionVisibilityToggle` / `onColumnManagerReorder` / `onColumnManagerReset`; импорт `PresentationColumn` + локальная `ColumnManagerSectionLocal`; шапка таблицы переведена на одно-row `<Column v-for>` |
| `front/src/pages/ReportPage/locale/en.json` + `ru.json` | новые ключи `columnManager.{tooltip,header,hint,reset,ungroupedLabel,dragHandle,toggleColumn}` (симметрично RU↔EN) |

**Проверка:**

- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 новых ошибок/warnings; 1 pre-existing warning в `plugins/persist.ts:91` (не наш файл, не регрессия).
- `npm run build` — production build зелёный, 13.90s.

**Backend-зависимость (нужно делегировать backend-specialist'у):**

В текущем `UserReportPreferenceController::update()` валидатор НЕ описывает ключ `column_order.hidden` — фронт его шлёт, Laravel `$request->validate()` возвращает только validated keys, и `hidden` молча отбрасывается на бэке. **Per-column visibility работает только локально через localStorage**, между устройствами не синхронизируется до тех пор, пока бэк не расширит валидатор. Требуется добавить в `src/app/Http/Controllers/UserReportPreferenceController.php` (строки 81-85):

```php
'column_order.hidden'           => ['sometimes', 'array'],
'column_order.hidden.*'         => ['string'],
```

Это две строки, без миграций (БД-колонка `column_order` уже `array` cast в `UserReportPreference` модели — JSON принимает любую структуру). Делегировать `backend-specialist`'у. До этого — не блокер для UX, просто синхронизация на серверной стороне молчит.

**Backlog / тех-долг:**

- DnD в popup'е читает порядок через `document.querySelectorAll('.column-manager__row[data-field]')` после drop — глобальный селектор работает потому что только один column-manager mount'ится за раз (popover singleton), но если когда-нибудь понадобится несколько одновременно — нужен scoped DOM ref. Сейчас не приоритет.
- vuedraggable добавил 5 high + 4 moderate вулнерабилити (транзитивно через старый `sortablejs`). `npm audit fix` не запускали (требует явной просьбы). Кандидат на отдельную итерацию обновления зависимостей.

## 2026-05-21 — Column groups removal (концепция выпилена)

**Задача:** пользователь решил, что группы колонок не нужны. Backend и AI prompt уже выпилили `column_group` (поле не возвращается из API даже если в БД) и `hidden_column_groups` (стрипается из preferences-payload, legacy `column_order.groups` молча игнорится на бэке). Фронт нужно подровнять под чистый flat-API.

**Что НЕ трогали:**

- `widget_group` для dashboard виджетов (`useWidgetGroups`, `ColumnGroupsToggle.vue` в Dashboard) — остался.
- `group_by` (rows grouping, master/detail) — другая фича, остался.
- Persist `column_order.order` и `column_order.hidden` — остались.
- Table-side DnD колонок (PrimeVue `reorderableColumns` + `@column-reorder`) — остался.
- `useReportPreferences` core singleton — остался.

**Изменённые файлы:**

| Файл | Что изменено |
|------|---|
| `front/src/api/types/reports.ts` | удалено поле `column_group?: string \| null` из `ReportColumnDto` |
| `front/src/api/types/reportPreferences.ts` | `ReportColumnOrderPreference` теперь только `{ order, hidden? }` — удалено поле `groups`. `ReportPreferences` потерял `hidden_column_groups: string[]`. JSDoc переписан под новый shape |
| `front/src/pages/ReportPage/composables/useReportPreferences.ts` | удалён `STORAGE_NAMESPACE_COLUMN_GROUPS` и весь блок чтения/записи `hidden_column_groups`; `seedFromCache` парсит `column_order` без `groups` (legacy `groups` ключ в localStorage молча стрипается); `writeCache` без блока для `column_groups`; `update` без ветки `hidden_column_groups`; `buildFallbackPreferences` без `hidden_column_groups` |
| `front/src/pages/ReportPage/composables/useColumnOrder.ts` | полная перезапись: убрано всё про `groups` map, `resolveColumnGroup`, `computeGroupForMovedColumn`, `applyOrderAndGroup`. API composable: `displayColumns`, `applyReorder(dragIndex, dropIndex)`, `setColumnVisibility`, `hiddenFields`, `reset`, `isCustomised` |
| `front/src/pages/ReportPage/composables/useColumnGroups.ts` | **удалён** — больше нет потребителей |
| `front/src/pages/ReportPage/composables/createGroupsComposable.ts` | `HiddenGroupsField` теперь только `'hidden_widget_groups'`; `buildPatch` упрощён до одной ветки; JSDoc/комменты перенесены под "был `useColumnGroups`, остался только widgets" |
| `front/src/pages/ReportPage/composables/useWidgetGroups.ts` | косметика: убраны ссылки на `useColumnGroups` в JSDoc |
| `front/src/pages/ReportPage/composables/useReportPresentation.ts` | удалено `column_group?: string \| null` из `PresentationColumn`, удалён маппинг `column_group: col.column_group ?? null` в `tableColumns` computed |
| `front/src/pages/ReportPage/components/ColumnManagerPopover.vue` | **полная перезапись**: убраны секции по `column_group`, теперь один плоский sortable-список через vuedraggable. Каждая строка: drag-handle + visibility checkbox + label. Над списком — bulk-toggle row (checkbox + "Показать все колонки" + счётчик `visibleCount/total`, с indeterminate state). Props: `columns: PresentationColumn[]`, `hiddenFields: Set<string>`, `anyCustomised: boolean`. Events: `toggle-column(field, visible)`, `toggle-all(visible)`, `reorder({dragIndex, dropIndex})`, `reset()`. Стили сохранены / упрощены (одна sortable-зона, без section-headers) |
| `front/src/pages/ReportPage/components/ColumnGroupsToggle.vue` | косметика: дефолты `tooltip-key`/`header-key`/`reset-key` теперь `widgetGroups.*` (был `columnGroups.*`) — единственный live use-site всё равно перебивает их явно, но дефолты теперь self-consistent с продакшн-keys |
| `front/src/pages/ReportPage/index.vue` | удалён импорт `useColumnGroups`; удалена локальная interface `ColumnManagerSectionLocal`; `columnGroups` биндинг удалён, `visibleTableColumns` упрощён (`columnOrder.displayColumns` → фильтр по `hiddenFields`); `columnManagerSections` удалён целиком, заменён прямой передачей `columns="columnOrder.displayColumns.value"` в popover; `onSectionVisibilityToggle` → `onBulkVisibilityToggle` (итерируется по всем колонкам); `onColumnManagerReorder` теперь принимает `{dragIndex, dropIndex}` и вызывает `columnOrder.applyReorder`; ребиндинг событий popover'а (`@toggle-section` → `@toggle-all`) |
| `front/src/pages/ReportPage/locale/{ru,en}.json` | удалён блок `columnGroups.*` (был для `ColumnGroupsToggle`-дефолтов — больше не используется); в `columnManager` блок: убран `ungroupedLabel`, добавлен `bulkLabel`, фраза `hint` перефразирована (убрана отсылка к группам); ключи симметричны RU↔EN |

**Проверка:**

- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 новых ошибок/warnings; 1 pre-existing warning в `plugins/persist.ts:91` (та же, что фиксировалась в предыдущих итерациях, не наш файл).
- `npm run build` — production build зелёный, 14.45s.
- `grep -rn "column_group\|hidden_column_groups\|column_order\.groups" front/src/` — 0 матчей.
- `grep -rn "useColumnGroups\|columnManagerSections\|applyOrderAndGroup" front/src/` — 0 матчей (кроме исторических комментов в `createGroupsComposable.ts`, где явно поясняется что `useColumnGroups` удалён 2026-05-21).

**Backend-зависимость:**

Backend уже выпилил концепцию (поле в API-response, ключ в preferences validator). Расхождений нет.

**Архитектурная заметка:**

`createGroupsComposable<T>` остался как generic factory для будущего расширения (сейчас единственный потребитель — `useWidgetGroups`). Дискриминированный `HiddenGroupsField` union с одной веткой выглядит слегка избыточно, но удалять его сейчас значит ломать абстракцию которая всё равно может пригодиться, когда появится третий group-axis. Оставил как есть.

## 2026-05-22 — ChatThinkingTimeline: "Думал N секунд" header + duration + autoscroll + a11y

**Контекст:** общий collapsible "thinking"-блок для AI-сообщений в обеих точках рендера AI-чата (MiniChatWidget + AiReportsPage / AiChatPage). Архитектурно компонент уже был общим: `ChatThinkingTimeline` рендерится внутри `ChatMessageBubble`, который используется через `ChatMessageList` в `MiniChatWidget` и в `ChatPageShell` (база для `AiReportsPage` / `AiChatPage`). Нужно было апгрейдить визуал и поведение, новый компонент создавать не пришлось.

| Файл | Что изменилось |
|---|---|
| `front/src/components/chat/ChatThinkingTimeline.vue` | Хедер теперь показывает **длительность турна** после завершения: «Думал N секунд» / «Думал N минут» (RU с правильной плюрализацией) и «Thought for N seconds» / «Thought for N minutes» (EN); `<1s` → «Думал меньше секунды». На ошибке: «Думал N секунд · Ошибка». Длительность считается из `props.startedAt` / `props.finishedAt` (canonical), с fallback на `events[0].created_at` / `events[last].created_at` для live-стрима и legacy-сообщений. Добавлены optional props `startedAt?: string \| null`, `finishedAt?: string \| null`. Chevron — единая иконка `pi-chevron-down` с CSS-ротацией `rotate(180deg)` в open-состоянии (transition 0.15s) вместо переключения двух классов. A11Y: header теперь имеет `aria-controls` + `aria-label` (toggle open/close, через `useId()` для стабильных ids), body — `role="region"` + `aria-labelledby` + `aria-label="Шаги размышления"`. Добавлен **autoscroll** внутри `__body` (`ref`-controlled scrollTop = scrollHeight на каждый новый event, только пока `status` running и блок раскрыт). Body теперь blockquote-стиль (border-left 3px + светлый фон + opacity 0.92 для де-эмфазиса reasoning относительно финального ответа), `max-height: 320px` + `overflow-y: auto`. Изначальный `isExpanded` собирается из `isWorking` (раньше всегда `true`) — собранные собщения теперь стартуют свёрнутыми. `:focus-visible` outline для keyboard-навигации |
| `front/src/components/chat/ChatMessageBubble.vue` | Пробрасываем `:started-at="message.startedAt"` + `:finished-at="message.finishedAt"` в `ChatThinkingTimeline` — данные уже были в `ChatMessage` entity (см. `entities/chat/types.ts:62-63`), просто не передавались |
| `front/src/components/chat/locale/ru.json` | + `thinkingTimeline.headerThoughtSeconds` (3-form pluralization: секунду / секунды / секунд), `headerThoughtMinutes` (минуту / минуты / минут), `headerThoughtLessThanSecond`, `toggleOpen`, `toggleClose`, `regionLabel` |
| `front/src/components/chat/locale/en.json` | + те же ключи, EN-плюрализация (2-form: singular / plural) |

**Что было переиспользовано / не переписано:**

- Логика парсинга `tool_call` / `tool_result` / `dry_run_*` / `retry` / `final_message` в `timelineItems` computed — без изменений.
- Thinking-content `<details>` блок (`text_delta` kind=thinking accumulator) — без изменений.
- Реактивность на инкрементальные обновления events / status — Vue-реактивность из коробки, watcher на `events.length` для autoscroll плюс existing watcher на `status` для авто-сворачивания, без перерисовки.

**Что НЕ делалось (явно):**

- Не создавалась новая отдельная компонента (`ThinkingBlock.vue`) — `ChatThinkingTimeline` уже был общим единственным местом рендера, дробить на ещё одну обёртку = добавлять indirection без пользы.
- Не трогался backend (`ChatEventEmitter`, `ChatStreamController`, `ChatMessage` model) — данные уже приходят, всё чисто фронтовое.
- Не делалась персистенция open/closed state в localStorage / preferences — это локальный UI-state на жизнь компонента, как явно зафиксировано в ТЗ.

**Проверка:**

- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 новых ошибок/warnings; 1 pre-existing warning в `plugins/persist.ts:91` (не наш файл).
- i18n симметрия RU ↔ EN — 0 расхождений (`ru_keys ^ en_keys == ∅`).

**UI:**

- **Свёрнутый блок** (status=done): тонкий горизонтальный блок с серой подсветкой границы, слева иконка `pi-check-circle` (серый), посередине «Думал 8 секунд», справа chevron вниз. Клик по всему хедеру раскрывает блок.
- **Свёрнутый блок** (status=error): красная подсветка границы, иконка `pi-times-circle` (красная), «Думал 5 секунд · Ошибка».
- **Раскрытый блок** (status=running): синяя подсветка границы, header «Думаю…» / «Использую инструменты…» (в зависимости от того, висит ли in-flight `tool_call`), spinner в header-иконке. Внутри — blockquote-стиль (3px border-left $surface-300, светлый фон, opacity 0.92, max-height 320px со скроллом). Список шагов: spinner-running / иконка-done / × -error, текст-метка + опциональный detail (например, «получено 142 строки»). Autoscroll держит свежий шаг в видимой части блока.
- **Раскрытый блок** (status=done): тот же blockquote-стиль, но все шаги в done-состоянии, без autoscroll.

**Файлы изменены / созданы:**

- Изменены: `front/src/components/chat/ChatThinkingTimeline.vue`, `front/src/components/chat/ChatMessageBubble.vue`, `front/src/components/chat/locale/{ru,en}.json`
- Созданы: нет — общий компонент уже существовал, в две точки рендера уже включён.


## 2026-05-22 — fix vue-i18n Russian pluralization (CLDR rules)

- `front/src/plugins/i18n.ts` — добавлен `pluralRules.ru` (CLDR-стандарт: one/few/many) в `createI18n({...})`. Без него vue-i18n использовал EN-дефолт (n===1 → форма 0, иначе форма 1), что давало «Думал 1 минуты» / «Думал 2 минут».
- Локали в `chat/locale/ru.json` (`headerThoughtSeconds`, `headerThoughtMinutes`, `details.rowCount`) уже были в правильном порядке `one | few | many` — не трогал.
- Type-check / lint чисто (только pre-existing warning в `plugins/persist.ts`).
- Замечен ещё один pipe-RU ключ — `pages/ReportPage/locale/ru.json` `items_count` — тоже в порядке one|few|many, теперь будет корректно склоняться благодаря тем же `pluralRules`.


## 2026-05-22 — fix Toolbox icon tooltips rendered below the panel

- Симптом: hover на иконку в Toolbox (верхний-правый угол) — подсказка `v-tooltip` рендерилась физически под самой панелью, текст не виден полностью.
- Корень: tooltip-базовый z-index в `theme/tokens/zIndex.ts` был `tooltip: 1300`, всего на +100 выше `toolbox: 1200`. Хотя в теории `@primeuix ZIndex` util должен инкрементировать tooltip над текущим top'ом overlay'ев, на практике в первом hover'е (когда никаких других overlay'ев не открыто) tooltip получал значение `1301` и попадал под `.toolbox-panel` (на одном корневом stacking-уровне body, но визуально подсказка отрисовывалась под краем панели из-за её shadow/border). Зазор был слишком тонким.
- Фикс: поднял базовый `tooltip` до `Z_BASE + 1500` (=2500), `modal` до 2600, `top` до 3000 — в `front/src/theme/tokens/zIndex.ts`. PrimeVue config принимает `primeVueZIndex.tooltip` через `app.use(PrimeVue, { zIndex: primeVueZIndex })` (см. `main.ts`), значение проброшено также в SCSS-переменную `--app-z-tooltip` через `applyAppCssVariables()`.
- Минимальное изменение: только числа в `zIndex.ts` + обновлённый комментарий в `theme/scss/foundation/_overlays.scss` (отражает новую раскладку и причину, ссылается на этот баг).
- НЕ переопределял `.p-tooltip` через CSS `!important` — комментарий в `_overlays.scss` явно отговаривает от хард-клампа (это сломало бы tooltip над динамическими Popover'ами Toolbox). Решено документированным путём — поднятие токена.
- Type-check / lint чисто (только pre-existing warning в `plugins/persist.ts`).
- Файлы: `front/src/theme/tokens/zIndex.ts`, `front/src/theme/scss/foundation/_overlays.scss`.

## 2026-05-22 — MiniChat overlay: жёсткий cap 400px

- `front/src/components/chat/MiniChatWidget.vue`: понизил ширину overlay'я с 420px до 400px и сделал `max-width` жёстким через `!important` (страховка против inline-стилей PrimeVue Popover). `width: min(400px, calc(100vw - 1.5rem))` для адаптива, `max-width: 400px !important` — твёрдый cap. Комментарий обновлён.


## 2026-05-22 — ChatThinkingTimeline: рендер `tool_call` / `tool_result` карточек + `thinking` connecting

**Задача:** chat-ai-engineer (backend) начал эмиттить новые event-типы в `chat_message_events`:
- `tool_call` — модель вызвала tool (`probe_data` / `query_data` / `create_report` / `update_report`).
- `tool_result` — tool вернул результат (`success: bool` + flat summary).

Также `thinking` event теперь имеет дискриминатор `stage` (`connecting` пришёл вместо устаревшего `pre_prism`). Фронтовый таймлайн должен корректно рендерить эти типы — раньше парсер по факту читал `payload.name` вместо `payload.tool` и `payload.result.row_count` вместо плоского `payload.rows_count`, поэтому даже существующие tool_call/tool_result показывались без деталей или с неправильным именем.

**Что изменено:**

- `front/src/components/chat/ChatThinkingTimeline.vue`
  - Парсер `timelineItems` переписан под актуальный backend-контракт:
    - `tool_call` payload — `{ tool: string, arguments: object }`; читаем `payload.tool` (а не `payload.name`).
    - `tool_result` payload — `{ tool, success, ...summary_flat }`; summary-ключи плоские (`rows_count`, `total_count`, `fields_count`, `aggregate_value`, `group_rows_count`, `report_id`, `error`), а НЕ вложены под `result`.
  - Pairing `tool_call → tool_result` теперь по FIFO-очереди per-tool-name (Map<string, number[]>), не overwriting Map. Корректно резолвит результат даже при повторных вызовах того же tool в одном turn'е.
  - Состояния карточки tool-step: `running` (тулкол был, тулрезалт ещё не пришёл → spinner, синий border) / `done` (success=true → иконка по типу tool'а, серый) / `error` (success=false → `pi-times-circle` красная + сообщение ошибки из `payload.error`).
  - Новые поля в `TimelineItem`: `argsLabel?` (краткое описание аргументов под именем tool'а), `errorMessage?` (текст ошибки на отдельной строке в красном), `indented?` (визуальная вложенность под write-tool-карту).
  - `buildArgsLabel(toolName, payload)` — per-tool форматтер:
    - probe_data: `"EstateDeals · поля: id, name"` (≤3 поля списком) или `"EstateDeals · 5 полей"` (плюрализация RU 3 формы).
    - query_data: `"EstateDeals · sum · 3 фильтра · по user_id"` (фильтры опц., group_by опц.).
    - create_report: `"«Sales 2026» · EstateDeals · 4 колонки · 2 виджета"`.
    - update_report: `"#42 · EstateDeals · 4 колонки"`.
  - `extractToolResultDetail(toolName, payload)` — per-tool детализатор результата (probe summary, aggregate value через Intl.NumberFormat, group rows count, report id).
  - Добавлен `thinking` event case с фильтром `payload.stage === 'connecting'` → row «Подключаюсь к модели…» / «Connecting to model…» (иконка `pi-cloud`). Остальные `thinking`-stages по-прежнему молча игнорируются.
  - `dry_run_start` / `retry` теперь получают флаг `indented: true`, если они пришли между активным write-tool-`tool_call` и его `tool_result` (`activeWriteToolIdx` трекер). Закрывается при приходе матч-`tool_result` для write-tool'а.
  - Орфан-`tool_result` (без предшествующего call — может прийти на resume/reconnect) рендерится отдельной строкой с правильным success/error.
- `front/src/components/chat/locale/{ru,en}.json`
  - `thinkingTimeline.tools.query_data` — новый ключ ("Запрашиваю данные" / "Querying data").
  - `thinkingTimeline.tools.probe_data` обновлён на "Изучаю структуру данных" / "Probing data structure" (точнее по сути операции).
  - `thinkingTimeline.steps.connecting` — новый ключ ("Подключаюсь к модели…" / "Connecting to model…").
  - `thinkingTimeline.args.*` — 11 ключей для формата аргументов tool_call (включая плюрализованные columns/widgets/filters/fields).
  - `thinkingTimeline.details.*` — 6 ключей для формата результатов (`probeSummary`, `probeNoTotal`, `aggregateValue`, `groupRowsCount`, `reportCreated`, `reportUpdated`).
  - `thinkingTimeline.errors.toolFailed` / `toolFailedGeneric` — новые ключи для строки ошибки.
  - RU плюрализация — 3 формы (one|few|many) для всех счётчиков (поля, колонки, виджеты, фильтры, группы); CLDR `pluralRules.ru` уже подключён в `plugins/i18n.ts` с прошлой итерации, корректно работает.

**UI после изменений:**

- **probe_data running:** строка с иконкой `pi-spin pi-spinner` (синяя), label «Изучаю структуру данных», под ним второй строкой args «EstateDeals · поля: id, name» (sans-serif, $surface-600).
- **probe_data success:** иконка `pi-search` (серая), label остался, под ним args + detail «12 из 42 · 3 поля» ($surface-500).
- **query_data success:** иконка `pi-database` (серая), label «Запрашиваю данные», args «EstateDeals · sum · 3 фильтра · по user_id», detail «= 1 234 567» (Intl.NumberFormat).
- **create_report turn (полный пример dry-run + retry):**
  ```
  ▸ Использую инструменты…                           [header, spinner]
  ───────────────────────────────────────────────────
  ⚡ Запрос принят
  ☁ Подключаюсь к модели…
  📊 Создаю отчёт                                    [running, spinner]
     «Sales 2026» · EstateDeals · 5 колонок · 2 виджета
    │ 🛡 Проверка отчёта                            [indented, running]
    │ ✓ Отчёт прошёл проверку                       [indented, done]
  📊 Создаю отчёт                                    [done]
     «Sales 2026» · EstateDeals · 5 колонок · 2 виджета
     отчёт #123 создан
  🏁 Ответ собран
  ```
  В случае ретрая (semantic dry_run_failed → retry → второй tool_call) под create_report-картой будут `🛡 Проверка → ✗ Проверка не прошла → 🔄 Повторная попытка`, потом приходит новый `tool_call(create_report)` (новая карточка, отдельная от первой), и т.д. Итого в одном create_report-turn'е с одной ретрай-итерацией: 2 tool-card'а + 3 indented под-шага (2× dry_run + 1× retry) = до 5 карточек на саму активность tool'а, плюс `started` + `connecting` + `final_message` сверху/снизу.
- **error на tool_result:** иконка `pi-times-circle` (красная), label + args сохраняются, под ними строка ошибки «Ошибка: relation_aggregate requires HasMany…» ($red-700, sans-serif). Существующая логика `headerError` (красная подсветка border всего thinking-блока) тоже срабатывает.
- **indented под-шаги:** padding-left 1.4rem, тонкий вертикальный коннектор ($surface-300, opacity 0.6) слева от иконки — визуально показывает что dry_run/retry принадлежат tool'у выше.

**Reactivity:**

- Когда после `tool_call(probe_data)` приходит `tool_result(probe_data, success=true)`, парсер находит соответствующую запись по FIFO-очереди (`popPending('probe_data')`), мутирует её state с `running` на `done`, дописывает detail. Vue реактивно перерендерит только эту `<li>` (key стабильный — `tc-{sequence}`), без перерендера всего timeline'а. Существующая логика autoscroll в body продолжает работать (watcher по `events.length`).
- Карточка существует одной строкой timeline'а от tool_call до tool_result — детали добавляются как новые `<span>`'ы внутри, переход visual: spinner → tool-icon, синий → серый.

**Проверка:**

- `cd front && npm run type-check` — 0 ошибок.
- `cd front && npm run lint` — 0 новых ошибок/warnings; 1 pre-existing warning в `plugins/persist.ts:91` (не наш файл).
- i18n симметрия RU ↔ EN — 0 расхождений (69 ключей в обоих).
- Все существующие event-типы (`started` / `text_delta` / `dry_run_*` / `retry` / `final_message`) продолжают работать — переписан только тулколовый бранч + добавлен thinking-connecting; остальные cases оставлены без изменений в плане поведения.

**Файлы изменены / созданы:**

- Изменены: `front/src/components/chat/ChatThinkingTimeline.vue`, `front/src/components/chat/locale/{ru,en}.json`
- Созданы: нет — расширение существующего компонента.

---

## 2026-05-22 — MiniChat overlay шире 400px (фикс №2: scoped → unscoped)

**Контекст:** в предыдущей итерации добавили `max-width: 400px !important` в `:deep(.mini-chat__overlay)` scoped-блок, но пользователь после hard-refresh видит overlay шире 400px. Bundle на vizion.lazarewww.ru свежий (Last-Modified 12:32 UTC), значит правило просто не применяется к нужному элементу.

**Root cause:** `MiniChatWidget.vue` использует `<Popover append-to="body">` — PrimeVue teleport'ит overlay root в `<body>`. Vue scoped `:deep(.X)` компилируется в `[data-v-XXX] .X`, но у body-mounted элемента нет родителя с `data-v-XXX` компонента → селектор не матчится. В dev могло «случайно» работать через HMR, в production-сборке — нет.

**Решение:** перенести правила `.mini-chat__overlay` / `.mini-chat__content` в глобальный unscoped SCSS-файл. Использовать селектор `.p-popover.mini-chat__overlay` (повышенная специфичность над дефолтным `.p-popover`).

**Что сделано:**

| Файл | Изменение |
|---|---|
| `front/src/assets/styles/_mini-chat-overlay.scss` | **СОЗДАН.** Unscoped global. Селектор `.p-popover.mini-chat__overlay { width: min(400px, calc(100vw - 1.5rem)) !important; max-width: 400px !important; min-width: 0 !important; ... }`. Media-query `(max-width: 560px)` тоже здесь (`width: calc(100vw - 1rem) !important`). |
| `front/src/assets/styles/main.scss` | Добавлен `@use '@/assets/styles/mini-chat-overlay';` в конец цепочки импортов. |
| `front/src/components/chat/MiniChatWidget.vue` | Удалены scoped правила `:deep(.mini-chat__overlay)` и `.mini-chat :deep(.mini-chat__content)`. Удалена scoped-ветка media-query для `.mini-chat__overlay`. Оставлен только инлайн-комментарий с указанием места, где живёт стиль (для будущих читателей). |

**Ключевые свойства с `!important`:**

- `width: min(400px, calc(100vw - 1.5rem)) !important` — мягкая адаптация под маленькие экраны.
- `max-width: 400px !important` — жёсткий потолок (главный анти-растяг).
- `min-width: 0 !important` — нейтрализует возможный `min-width` от PrimeVue `.p-popover`.

**Селектор и его специфичность:**

`.p-popover.mini-chat__overlay` (`0,2,0`) — выше дефолтного `.p-popover` от PrimeVue (`0,1,0`). `!important` страхует от inline-стилей, которые PrimeVue ставит динамически (top/left при позиционировании).

**Почему unscoped SCSS, а не отдельный `<style>` без scoped в том же `.vue`:**

В проекте уже есть паттерн `_payment-schedule-footer.scss` — точно такая же ситуация (PrimeVue-фрагмент рендерится через slot/teleport вне scope component'а). Держим один паттерн вместо двух способов решать одну проблему. SCSS-импорт чище — не плодит дублирующиеся блоки в каждом инстансе компонента.

**Проверка:**

- `cd front && npm run type-check` — 0 ошибок (изменения SCSS-only + удаление scoped-правил, типы не задеты).
- `cd front && npm run lint` — 0 новых warnings; 1 pre-existing warning в `plugins/persist.ts:91`.

**Файлы изменены / созданы:**

- Создан: `front/src/assets/styles/_mini-chat-overlay.scss`.
- Изменены: `front/src/assets/styles/main.scss`, `front/src/components/chat/MiniChatWidget.vue`.

**Следующий шаг для пользователя:** hard-refresh + Devtools → проверить computed style на `.p-popover.mini-chat__overlay` в `<body>`. Если всё ещё шире 400 — снять скриншот computed-панели и DOM-tree overlay'а, причина уже не в `:deep` (нужно искать inline `style="width: …"` от PrimeVue, который, скорее всего, ставится при позиционировании и который `!important` уже должен побороть).

---

## 2026-05-22 — ChatThinkingTimeline: фикс QA-баги после real-time таймлайна

QA после итерации `462d303` нашёл 2 бага.

### Bug #1 (карточки tool_call/tool_result с пустыми placeholders)

**Симптом:** карточка `create_report` в timeline показывала `«» · · · ` (4 пустых сегмента) вместо `«Сделки за квартал...» · EstateDeals · 10 колонок · 2 виджета`. Detail-строка под карточкой — `отчёт # создан` без `{id}`.

**Root cause:** в `ChatThinkingTimeline.vue` 8 вызовов `t()` использовали 2-аргументную форму с обёрткой `{ named: { ... } }`:

```ts
// БЫЛО (не работало):
t('thinkingTimeline.details.reportCreated', { named: { id: payload.report_id } })

// СТАЛО (работает):
t('thinkingTimeline.details.reportCreated', { id: payload.report_id })
```

В vue-i18n 9 (composition API) 2-arg `t(key, value)` интерпретируется как **NamedValue напрямую** — ключи 2-го объекта это и есть подстановки. Когда мы передавали `{ named: { ... } }`, библиотека искала placeholder `{named}` (которого нет), а `{id}` / `{title}` / `{model}` оставались empty-string'ами. Корректная 2-аргументная форма — `t(key, { name: value })` без обёртки. Это подтверждено остальным кодом репозитория (`useReportPresentation`, `MiniChatWidget` и т.д. — везде direct shape).

3-аргументная форма с pluralization (`t(key, choice, { named: { ... } })`) остаётся корректной — это и есть документированный API для combined plural + named. Оставлены без изменений.

**Файлы изменены:**

| Файл | Изменение |
|---|---|
| `front/src/components/chat/ChatThinkingTimeline.vue` | 8 вызовов `t()` без plural-choice освобождены от `{ named: ... }` обёртки. В template (slot для `errorMessage`) тоже. Все 3-arg вызовы с plural-choice оставлены без изменений. Локали `ru.json` / `en.json` не правились — текст ключей корректный, бага была в форме вызова. |

**Затронутые ключи:**

- `args.modelWithFieldsList`, `args.queryAggregateNoFilters`, `args.queryGroupedBy`, `args.createReportArgsColumnsWidgets`, `args.createReportArgs`, `args.updateReportArgs`, `details.aggregateValue`, `details.reportCreated`, `details.reportUpdated`, `errors.toolFailed` — 10 ключей теперь корректно интерполируют named-параметры.

### Bug #2 (real-time таймлайн не работает на втором турне в существующем чате)

**Симптом:** quick_qa на первом турне нового чата — events `probe_data` появляются live по одному. На втором турне (create_report) — thinking-block виден раскрытым, но пустой 110 сек, потом все 6 шагов отрисовываются разом в момент settle. Бэкенд эмиттит events отдельными SSE-фреймами (verified), DB содержит их с правильным timing.

**Root cause:** race между мутацией `currentChat.value.messages = [...]` и `subscribeToAssistantStream`. В `useChatMessaging.sendMessage` после `await chatService.sendMessage(...)` массив messages обновляется синхронно, но `stream.start(...)` — `void`-fire async-function, первый `await` внутри (`replayBatchEvents`) yield'ит в microtask queue. В этом окне `consumeStream` уже мог открыть fetch и распарсить первый frame; колбэк `appendTimelineEvent` вызывает `findMessage` — а в момент вызова Vue reactivity ещё не успела сделать DOM-flush, и в edge-кейсе `findMessage` возвращал `null` → events дропались.

Дополнительная гипотеза: даже если DOM-flush был, в `replaceMessageById` мы переприсваиваем `chat.messages` новым массивом, и watcher/render у `ChatThinkingTimeline.events` мог пропустить дельту из-за batching, если предыдущий update ещё не процессился.

**Решение (двухуровневое):**

1. **`await nextTick()`** между мутацией `messages` и вызовом `subscribeToAssistantStream`. Гарантирует, что Vue прокачает обновление перед открытием стрима.
2. **Pending-events buffer** (`pendingEventsByMessageId: Map<messageId, ChatMessageEventDto[]>`) как страховка. Если `appendTimelineEvent` всё-таки не нашёл message — событие кладётся в buffer; при следующем `appendTimelineEvent` для того же `messageId` (или после возврата из `nextTick()`, или в `finalizeAssistantMessage`) buffer flush'ится в `message.timelineEvents`.

**Дополнительно:**

- Placeholder инициализируется с `timelineEvents: []` (не `undefined`) — renderer'ы могут безопасно делать `.length`.
- Buffer чистится в `clearMessagingScope()` (выход с страницы / смена компании / logout).
- В `finalizeAssistantMessage` после `fetchChat` мерджим buffered events в fresh-chat'овые messages, чтобы события не терялись если settle произошёл раньше flush'а.

**Файлы изменены:**

| Файл | Изменение |
|---|---|
| `front/src/components/chat/composables/useChatMessaging.ts` | Импорт `nextTick`. Добавлен `pendingEventsByMessageId` Map. `appendTimelineEvent` буферизует events если `findMessage` вернул null. Новая helper-функция `flushPendingEvents`. `sendMessage` — `await nextTick()` перед `subscribeToAssistantStream` + явный `flushPendingEvents` после. `finalizeAssistantMessage` — мерджит buffer в fresh messages, потом `pendingEventsByMessageId.clear()`. `clearMessagingScope` чистит buffer. Placeholder `assistantMessage` приходит с `timelineEvents: []` (не `undefined`). |

### Проверка

- `cd front && npm run type-check` — 0 ошибок.
- `cd front && npm run lint` — 0 новых warnings; 1 pre-existing warning в `plugins/persist.ts:91`.
- Локали `ru.json` / `en.json` — структура ключей не изменялась, симметрия RU↔EN сохранена.

### Файлы изменены / созданы

- Изменены: `front/src/components/chat/ChatThinkingTimeline.vue`, `front/src/components/chat/composables/useChatMessaging.ts`.
- Созданы: нет.

### Что отдать QA на повторный прогон

- Создать chat → отправить первое сообщение (`probe_data` / `quick_qa`) — должны быть видны live-шаги (как было после `462d303`).
- В том же чате отправить второе сообщение, провоцирующее `create_report` (например «создай отчёт по сделкам») — теперь должны быть видны live-шаги `probe_data` (раз или несколько), затем `create_report` cartouche с args `«Сделки...» · EstateDeals · 10 колонок · 2 виджета`, после settle — detail `отчёт #24 создан`.
- Args карточки `update_report` (если QA сможет легко спровоцировать) — должны рендериться `#24 · EstateDeals` + `· N колонок`.

---

## 2026-05-22 — Bug #2 follow-up: `/events` replay через native fetch с явным Authorization

### Контекст

QA подтвердил Bug #1 fixed (`t()` named-params в create_report card). Bug #2 (real-time на втором турне) сменил симптом: первый replay-GET `/events?since=0&limit=100` уходит с Bearer (axios), повторный GET для следующего турна — **без Authorization → 401 → reconnect ничего не восстанавливает → live-стрим висит до завершения worker'а**.

Network log пользователя:
```
#24 GET .../events?since=0&limit=100  → 200, Bearer present (axios)
#25 GET .../stream/110?since=2        → SSE pending
#26 GET .../events?since=0&limit=100  → 401, Authorization ABSENT
```

### Анализ

Все `/events`-вызовы в коде идут через `chatService.fetchMessageEvents` → `chatsApi.fetchMessageEvents` → единственный `apiClient` (axios singleton) с request-interceptor'ом, который ставит `Authorization: Bearer ${getToken()}`. SSE-стрим `/stream/...` идёт через native fetch с явно собираемым Authorization из `userStore.getAuthCredential` (документировано — `EventSource` не поддерживает headers).

В коде нет ветки, где бы `/events` запрос шёл мимо axios. Тем не менее QA воспроизводимо видит 401 без заголовка на втором турне. Возможные причины (без живой репро):

1. Axios interceptor stale closure после reload / persistence rehydration.
2. Поведение какого-то браузерного middleware между турнами.
3. Stale axios-instance caching.

### Решение

Сделать оба `chat-stream` запроса (`replay /events` + `live /stream`) **одинаковыми по транспорту**: native `fetch()` с явным `Authorization: Bearer ${userStore.getAuthCredential}` header. Это убирает асимметрию между двумя GET'ами и гарантирует, что на каждый запрос токен берётся прямо из Pinia store без посредников.

### Изменения

| Файл | Изменение |
|---|---|
| `front/src/components/chat/composables/useChatStream.ts` | (1) Импорт `ChatMessageEventsResponseDto`; убран `useServices` и `chatService`. (2) Новые helper'ы: `buildEventsRequestUrl(chatId, messageId, since, limit)` строит canonical-URL; `fetchEventsPage(chatId, messageId, cursor)` делает native `fetch()` с `Authorization` header, проверяет `response.ok`, возвращает типизированный `ChatMessageEventsResponseDto`. (3) `replayBatchEvents` теперь использует `fetchEventsPage` вместо `chatService.fetchMessageEvents`. (4) В `start()` `controller = new AbortController()` создаётся ДО `replayBatchEvents` — теперь `stop()` корректно abort'ит и batch-fetch (раньше controller жил только внутри `consumeStream`); `consumeStream` пересоздаёт controller per attempt, как и раньше. |

### Контракт сохранён

- `chatsApi.fetchMessageEvents` остаётся в `front/src/api/chats.ts` — может пригодиться для других сценариев (timeline replay для финальных turns, debug инструмент); ничего не сломалось.
- `chatService.fetchMessageEvents` остаётся в `services/ChatService.ts` — публичный API сервиса не тронут.
- Backend endpoint `GET /api/chats/{chat}/messages/{message}/events` не изменился; ACL по Sanctum Bearer уже умеет работать.

### Что НЕ делалось

- Backend не трогался (контракт остался как есть; `chats_frontend.md` не обновлялся — внутренняя реорганизация транспорта).
- Не разный код-путь для первого/второго турна — оба турна идут через один и тот же `stream.start()` (новый и существующий чат, новое сообщение и `resumeActiveStream` после reload).
- `EventSource` нигде не используется — везде fetch+ReadableStream (уже было).
- Никаких токенов в query-параметрах — Authorization остаётся в header.

### Проверка

- `cd front && npm run type-check` — 0 ошибок.
- `cd front && npm run lint` — 0 новых warnings (только pre-existing в `plugins/persist.ts:91`).

### Что отдать QA

- В существующем чате отправить второе сообщение (или больше) и убедиться, что timeline (`probe_data` / `create_report` / `dry_run_*` / `final_message`) появляется live по мере поступления, а не разом в момент settle.
- В Network tab проверить, что **каждый** `GET /api/chats/{id}/messages/{messageId}/events?since=...` несёт `Authorization: Bearer ...` заголовок (не только первый), и возвращает 200.
- Reload страницы во время AI-turn — реconnect должен работать (batch replay + SSE resume).

---

## 2026-05-24 — Tooltip z-index, акт 3: подъём токена до 11000 (выше открытого MiniChat popover)

### Контекст саги

| Дата | Что было | Результат |
|---|---|---|
| 2026-05-22 | Подняли `zIndex.tooltip` до 2500 (Z_BASE + 1500) — выше Toolbox 1200 и Popover baseZIndex 1250 | Tooltip всё ещё падал ПОД Toolbox когда стек overlay'ев пуст (PrimeUix per-key counter не лифтит выше base) |
| 2026-05-23 | Добавили жёсткий клэмп `.p-tooltip { z-index: $z-tooltip !important }` в `_overlays.scss` | Закрытый MiniChat — OK ✓. **Открытый MiniChat — сломалось ✗** |
| 2026-05-24 | Подняли `zIndex.tooltip` до 11000 (Z_BASE + 10000), клэмп оставили | Tooltip над любым реалистичным overlay'ем |

### Что было сломано

Когда MiniChat popover открыт, PrimeVue ZIndex util инкрементирует runtime z-index с baseZIndex (`zIndex.toolbox + 50 = 1250`); в практике значения уходили до **~3251**. Прежний tooltip-клэмп `2500` оказался ниже → tooltip уходил ПОД открытый MiniChat.

### Решение (вариант 3 — комбинированный)

1. Подняли `zIndex.tooltip` с `Z_BASE + 1500` (= 2500) до `Z_BASE + 10000` (= 11000) — большой запас над любым Popover/Modal/Confirm.
2. Жёсткий клэмп `.p-tooltip { z-index: $z-tooltip !important }` оставлен — он нужен потому что PrimeUix counter ненадёжен против static-overlay'ев приложения (выяснено в акте 2).
3. CSS-vars `--app-z-tooltip` генерируется автоматически из `zIndex` (см. `appVariables.ts`), так что подъёма токена достаточно — `_overlays.scss` подхватит новое значение через `var(--app-z-tooltip)` без правок.

### Почему 11000, а не PrimeVue auto-increment

| Вариант | Почему отверг |
|---|---|
| 1. `99999` (экстремум) | Достаточно, но магическое число без структуры. 11000 — структурное (`Z_BASE + 10000`), документировано в комментарии. |
| 2. Убрать `!important`, положиться на ZIndex util | Возвращает баг акта 1 (tooltip под Toolbox когда стек пуст). |
| 3. **11000 + сохранить клэмп** ✓ | Выбран. Гарантия над Popover (~3251), Modal (2600), top (3000) с запасом ~7700 на будущие overlay'и. |

### Файлы

| Файл | Изменение |
|---|---|
| `front/src/theme/tokens/zIndex.ts` | `tooltip: Z_BASE + 1500` → `Z_BASE + 10000` (= 11000). Перемещён в конец объекта для визуального выделения. Обновлён header-комментарий со всей сагой и инвариантом «tooltip — самый верхний слой». |
| `front/src/theme/scss/foundation/_overlays.scss` | Переписан комментарий над клэмпом: 3 акта саги, причина 11000, инвариант. Сама CSS-правка не изменена (`z-index: $z-tooltip !important`). |

### Trade-off

Tooltip теперь поверх любых модалок (Modal = 2600, Top = 3000 < 11000). Это **правильное** поведение для tooltip-семантики (подсказка над модалкой норм). Если когда-нибудь нужен Confirm-диалог поверх tooltip — поднимать `modal`/`top`, не понижать `tooltip`. Зафиксировано в комментарии токена.

### Проверка

- `cd front && npm run type-check` — 0 ошибок.

### Что отдать QA

- MiniChat **закрыт** в Toolbox: навести на иконку любого инструмента → tooltip над Toolbox (как было после акта 2).
- MiniChat **открыт** в overlay: навести на любую другую иконку Toolbox (или элемент с tooltip'ом) → tooltip должен быть ВЫШЕ открытого MiniChat popover'а, не уходить под него.
- Любой Popover (Profile menu, Company switcher) открыт + hover по соседней tooltip-обёрнутой иконке → tooltip сверху.

---

## 2026-05-24 — Bugfix: MiniChat expand-кнопка теряет chatId для quick_qa чатов

### Симптом

Клик по кнопке `pi-external-link` («Открыть на весь экран») в MiniChat overlay → новая вкладка открывается на `/ai-reports`, но конкретный чат не активируется — пользователь видит дефолтный список чатов вместо разговора, который вёл в мини-виджете.

### Root cause

`MiniChatWidget.openInFullScreen()` всегда делал `window.open('/ai-reports?activate=' + id)` независимо от типа чата. Но MiniChat умеет два сценария:

- На странице отчёта → создаёт `report_generation` чат → `/ai-reports` корректен.
- На любой другой странице (Reports list, Company etc.) → переиспользует/создаёт `quick_qa` чат → `/ai-reports` **не подходит** (эта страница в scope `report_generation`).

`useChatPage.initScope` имеет защиту: `candidate.type === options.type` — при mismatch активация молча скипается, фронт падает в fallback (`activeChat.value` для `report_generation` scope, обычно пусто). Никакой ошибки в консоли, никакого редиректа, просто пустая вкладка.

Дополнительно: `useAiChatPage` (страница `/ai-chat` для `quick_qa`) вообще не читал `?activate=` query param — то есть даже если бы кнопка вела по правильному адресу, активация бы не сработала.

### Фикс

Изменения минимально-инвазивные, без перестройки stores и архитектуры:

1. **`MiniChatWidget.openInFullScreen()`** — теперь выбирает путь по `currentChat.type`:
   - `'quick_qa'` → `/ai-chat?activate=N`
   - `'report_generation'` → `/ai-reports?activate=N`
2. **Извлечён общий composable `useActivateChatIdQueryParam`** (`pages/shared/useActivateChatIdQueryParam.ts`) — раньше эта логика жила инлайн только в `useAiReportsPage`. Дублирование вытащил, чтобы `useAiChatPage` мог переиспользовать ту же реализацию (ref-based handoff в обход vue-router-clobber, см. memory `vue_router_clobbers_history_state.md`).
3. **`useAiChatPage`** — теперь зовёт `useActivateChatIdQueryParam()` и прокидывает `pendingActivateChatId` в `useChatPage`. Логика валидации скоупа (`candidate.type === 'quick_qa'`) уже была в `useChatPage.initScope`, дополнительной защиты не нужно — chat type-mismatch так же безопасно скипнется.
4. **`useAiReportsPage`** — переключён на тот же shared composable; локальная `consumeActivateQueryParam` удалена (поведение идентичное).

### Файлы

| Файл | Изменение |
|---|---|
| `front/src/components/chat/MiniChatWidget.vue` | `openInFullScreen()`: route by `chat.currentChat.value?.type` → `/ai-chat` или `/ai-reports`. Комментарий с обоснованием — почему по типу чата, а не по текущему route. |
| `front/src/pages/shared/useActivateChatIdQueryParam.ts` | **Новый файл.** Извлечённая логика чтения и one-shot-зачистки `?activate=N`. Используется обеими full-screen чат-страницами. |
| `front/src/pages/AiReportsPage/composables/useAiReportsPage.ts` | Inline `consumeActivateQueryParam` удалён → импорт `useActivateChatIdQueryParam`. Поведение не меняется. |
| `front/src/pages/AiChatPage/composables/useAiChatPage.ts` | Подключён `useActivateChatIdQueryParam` + проброс `pendingActivateChatId` в `useChatPage`. Раньше cross-tab handoff для `quick_qa` не работал вообще. |

### Что НЕ менялось

- `useChatPage.initScope` — его guard (`candidate.type === options.type`) уже корректен; теперь работает в обе стороны.
- Pinia stores (`useChatsStore`, `useReportContextStore`) — не тронуты.
- i18n — новых ключей нет, существующие `miniChat.openInFullScreen` используются как есть.
- Логика создания/переиспользования чатов в MiniChat (`resolveTargetChat`) — без изменений.

### Проверка

- `cd front && npm run type-check` — 0 ошибок.
- `cd front && npm run lint` — 0 errors, 1 pre-existing warning в `plugins/persist.ts` (не наш файл).

### Что отдать QA

Happy-path scenarios:

1. **Quick QA из не-report страницы** (например `/reports`): открыть MiniChat → отправить пару сообщений → нажать `↗` → новая вкладка `/ai-chat?activate=N` → активируется ИМЕННО тот чат, что был в мини-виджете (не последний quick_qa в списке).
2. **Report generation на странице отчёта** (`/reports/:id`): открыть MiniChat → пара сообщений → `↗` → новая вкладка `/ai-reports?activate=N` → активируется тот же чат.
3. **One-shot:** после открытия — URL в новой вкладке должен быть `/ai-chat` или `/ai-reports` без `?activate=` (query param зачищается `router.replace`). Refresh страницы не должен повторно активировать чат.
4. **Кнопка disabled когда чата нет** (`:disabled="!currentChat"`) — поведение прежнее, не должно регрессировать.

---

## 2026-05-24 — меню действий с пользовательским отчётом (publish/unpublish/delete)

**Задача:** на странице отчёта (`/reports/:id`) рядом с фильтром добавить кнопку `pi pi-ellipsis-v` — Popover с инфо (автор / дата / статус) и действиями publish / unpublish / delete (role-based). Backend готов: `created_at` / `updated_at` / `author` приходят в response отчёта; новые endpoints `POST /api/reports/{id}/publish` и `/unpublish` возвращают полный DTO; `DELETE /api/reports/{id}` каскадно удаляет связанный chat.

**Изменения:**

| Файл | Что | Зачем |
|---|---|---|
| `front/src/api/types/reports.ts` | новый `ReportAuthorDto`, добавлены поля `created_at`, `updated_at`, `author` в `ReportDto` | контракт расширения от backend |
| `front/src/api/types/index.ts` | re-export `ReportAuthorDto` | публичный type |
| `front/src/api/reports.ts` | методы `publishReport(id)` / `unpublishReport(id)` в `ReportsApi` + реализация (POST на `/api/reports/{id}/{publish,unpublish}`) | новые endpoints |
| `front/src/services/ReportService.ts` | `publishReport` / `unpublishReport` — пробрасывают `mapReportDtoToReport` | service-уровень |
| `front/src/entities/report/reportItem.ts` | `ReportItem` теперь несёт `is_system?`, `created_at?`, `updated_at?`, `author?` (через `mapReportToItem`) | страничный view-model видит метаданные |
| `front/src/shared/auth/capabilities.ts` | `canManageReportPublication(role)` (admin/superadmin), `canDeleteReport(role, isOwner, isSystem)` (superadmin/admin = любой; analyst = только свой; viewer = никогда; системные всегда нет) | ACL фронта зеркалит backend |
| `front/src/pages/ReportPage/components/ReportActionsMenu.vue` | **новый** компонент: `<Button icon="pi pi-ellipsis-v">` → `<Popover>` с блоком инфо (автор + дата + статус-Tag) + действия с разделителем; confirm через `DeleteConfirmModal`; toasts через `useNotifications`; per-action `busyAction` ref для loading state | UI |
| `front/src/pages/ReportPage/index.vue` | импорт + рендер `<ReportActionsMenu>` после `.filter-toggle-wrap`; handlers `onReportUpdated` (in-place mutate `is_published` / `updated_at` чтобы не сбросить `rows/meta/pagination`) и `onReportDeleted` (вызов `goBack()` → `/reports`) | подключение |
| `front/src/pages/ReportPage/locale/{ru,en}.json` | блок `actionsMenu.*` (17 ключей: title / info_section / author / created_at / status* / publish / unpublish / delete / delete_confirm_* / toast_*) | RU+EN симметрично |
| `front/src/mocks/data.ts` | MSW-моки расширены: `created_at`, `updated_at`, `author` (custom report — non-null; system report — `null`); `buildMockReportListResponse` пробрасывает новые поля | тип-сейф для type-check (mocks под тем же `ReportDto`) |

**Решения по развилкам:**

- **Confirm dialog:** использован уже существующий `DeleteConfirmModal` из `components/modals/` вместо PrimeVue `useConfirm` (`ConfirmationService` в проекте не зарегистрирован в `main.ts`). Совпадает с паттерном `Company/ManagementModal`.
- **Локальное обновление `report` после publish/unpublish:** in-place mutation `report.value.is_published = updated.is_published` — backend возвращает полный DTO, но `rows / columns / meta` могут не пройти через серверный `transform` для пагинации/фильтров текущего вида. Полная замена через `mapReportToItem(updated)` риск — потерять текущую страницу / фильтр. Сохраняем то, что уже отрисовано, и обновляем только `is_published` + `updated_at` + (опционально) `author` / `created_at` если backend всё-таки их прислал.
- **Navigation после delete:** `goBack()` (уже есть в `useReportPageActions`, идёт на `/reports`). Альтернатива — `router.back()` — не использована, потому что страница могла быть открыта прямой ссылкой / новой вкладкой, и `router.back()` тогда уйдёт за пределы SPA.
- **Loading state:** `busyAction: Ref<'publish' | 'unpublish' | 'delete' | null>` — spinner только на активной кнопке, остальные действия disabled. Popover остаётся открытым до завершения запроса (закрывается явным `popoverRef.value?.hide()` на успех).
- **Author display:** `name` с трим, fallback на `email` если пусто. `title`-tooltip с `email` показывается только когда видимая строка — `name` (избегаем `email title=email`).
- **i18n ключи:** `delete_confirm_message` намеренно без `{title}` — `DeleteConfirmModal` уже бирколизирует `itemName` сам через свой prompt-шаблон; чтобы не дублировать заголовок в двух местах, текст подсказки общий ("Отчёт и связанный AI-чат будут удалены безвозвратно.").
- **Системные отчёты:** в инфо-блоке вместо автора/даты выводится одна строка `Статус: Системный отчёт` (Tag severity="info"). Действия скрыты (capabilities всегда вернут false для `isSystem=true`).

**ACL-таблица (зеркалит backend):**

| Роль | Publish/Unpublish | Delete своего | Delete чужого | Системный отчёт |
|---|---|---|---|---|
| superadmin | ✓ | ✓ | ✓ | inert |
| admin | ✓ | ✓ | ✓ | inert |
| analyst | ✗ | ✓ | ✗ | inert |
| viewer | ✗ | ✗ | ✗ | inert |

«inert» = меню показывается, но в блоке actions ничего нет (только инфо «Системный отчёт»).

**Проверка:**
- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 errors, 1 pre-existing warning в `plugins/persist.ts` (не наш файл).
- i18n parity RU↔EN — расхождений нет (проверено через скрипт flatten + diff Set).

**Что отдать QA:**

1. **Smoke (любая роль кроме viewer):** открыть пользовательский отчёт → справа от иконки фильтра видна кнопка `...` → клик → Popover с автором / датой / статусом + кнопки publish или unpublish + delete.
2. **Publish/Unpublish flow (admin/superadmin):** черновик → `Опубликовать` → toast «Отчёт опубликован», статус в popover меняется на «Опубликован», кнопка превращается в `Скрыть из публикации`. И наоборот.
3. **Delete flow (admin/superadmin или analyst-owner):** `Удалить отчёт` → confirm-модал с заголовком отчёта в `<strong>` + предупреждение про каскадное удаление чата → `Удалить` → toast «Отчёт удалён» → редирект на `/reports` → отчёта в списке нет.
4. **ACL (viewer):** на чужом отчёте — popover показывает только инфо, без действий. На системном — только статус «Системный отчёт».
5. **ACL (analyst):** на своём отчёте видит только `Удалить` (publish/unpublish скрыты). На чужом — только инфо.
6. **Loading-state:** во время publish/delete — спиннер на конкретной кнопке, другие disabled, popover не закрывается до завершения.
7. **i18n:** переключить язык — все ключи переводятся (Tag «Published»/«Опубликован», подсказки, тосты).
8. **Console + network:** проверить отсутствие ошибок; запрос delete → DELETE `/api/reports/{id}` → 204; publish → POST `/api/reports/{id}/publish` → 200 + full report.

---

## 2026-05-24 — Report Actions Menu: QA fixes (toast life + Popover race guard)

**Контекст:** QA на новой фиче `ReportActionsMenu` (publish/unpublish/delete) нашёл два UX-замечания. Backend параллельно правит свой критбаг про `is_system` в response — его не трогаем.

**Изменения:**

| Файл | Изменение | Зачем |
|---|---|---|
| `front/src/pages/ReportPage/components/ReportActionsMenu.vue` | (1) добавлена локальная константа `TOAST_LIFE_MS = 3000`, передаётся 3-м аргументом в `notifySuccess(..., undefined, TOAST_LIFE_MS)` для всех 3 toast'ов фичи (publish / unpublish / delete). (2) В `togglePopover` добавлен guard `if (!event.currentTarget) return; if (!anchorRef.value) return;` перед `popoverRef.value?.toggle(event)`. | Замечания QA #1 и #2 ниже. |

**Замечание #1 — toast перекрывал кнопку `…` ~10-14 секунд:**

Root cause: `useNotifications.notifySuccess(message, summary?, life?)` пробрасывает `life: undefined` в `notificationCenter`, оттуда в `AppNotifications.vue`, где `toast.add({ life: 4000, ...message })` — spread оверрайдит дефолт `4000` на `undefined`. PrimeVue Toast при `life: undefined` становится sticky (не закрывается автоматически), физически перекрывает кнопку actions menu в правом верхнем углу (toast position = top-right), Playwright `click()` падает с `subtree intercepts pointer events`.

Решение: **локальный** фикс — передаю явный `life: 3000` только для тостов этой фичи. Глобальный дефолт в `AppNotifications.vue` (4000) и контракт `useNotifications` НЕ трогаю — у других фич может быть свой dwell time, отдельная задача. JSDoc-комментарий на константе объясняет причину, чтобы при следующем рефакторинге не убрали.

**Замечание #2 — PrimeVue Popover console error на быстром Escape + повторном клике:**

```
TypeError: Cannot read properties of null (reading 'offsetHeight')
  at Proxy.alignOverlay (primevue-CiyWsOVI.js:244:2432)
  at Proxy.onEnter (primevue-CiyWsOVI.js:244:1674)
```

Race: при Escape Popover запускает leave-transition, overlay DOM ещё разбирается, и если в этот момент юзер программно ре-кликает по кнопке — `onEnter` нового открытия пытается выровняться по overlay, который уже `null`. Не блокирует UX, но засоряет console.

Решение: в handler'е `togglePopover` дроплю невалидные toggle'и до того, как они попадут в Popover-internal:
- `if (!event.currentTarget) return` — отсечёт случаи когда event теряет target (re-dispatch, programmatic invoke без attach'а)
- `if (!anchorRef.value) return` — anchor `<Button>` уже размонтирован, не во что выравнивать

Happy-path (обычный клик по живой кнопке) не задет. JSDoc на handler'е документирует, что фиксит и почему.

**Что НЕ трогалось (за пределами scope):**
- Backend (параллельная правка `is_system` в response — не наша).
- Сидеры / тестовые отчёты.
- `useNotifications` / `AppNotifications.vue` (фикс локальный, не глобальный).
- Никаких push.

**Проверка:**
- `npm run type-check` — 0 ошибок.
- `npm run lint` — 0 errors. Остаётся 1 pre-existing warning в `front/src/plugins/persist.ts:91` (comma-dangle) — не наш файл, известный, не регрессия.
- i18n parity — ключи не менялись, диффа нет.

**Что отдать QA (regression smoke):**

1. Открыть пользовательский отчёт (admin/superadmin) → клик `…` → Popover открывается без console error.
2. Publish/Unpublish/Delete — toast «…отчёт опубликован/скрыт/удалён» закрывается через ~3 секунды, не блокирует повторный клик по `…`.
3. Открыть `…` → Escape → сразу клик по `…` снова — никакого `TypeError: Cannot read properties of null (reading 'offsetHeight')` в console. Popover открывается нормально.
4. Повторить шаг 2 в EN — тосты тоже закрываются за 3с.

---

## 2026-05-24 — TS-фикс: `ReportDto.user_id` допускает `null` (системные отчёты)

**Контекст:** после PM review фичи Report Actions Menu. Backend возвращает `user_id: null` для системных отчётов (ReportSeeder сидит без owner'а), а на фронте `ReportDto.user_id` был объявлен как `number` — strict-TS на ответе сломался бы на runtime narrowing'ах.

**Изменено:**

- `front/src/api/types/reports.ts` — `ReportDto.user_id: number | null` (+ JSDoc, почему null).
- `front/src/entities/report/reportItem.ts` — `ReportItem.user_id?: number | null` (тоже допускает null, потому что `mapReportToItem` делает прямой passthrough из `Report`, которое наследует `Omit<ReportDto, …>` БЕЗ исключения `user_id` — то есть `Report.user_id` автоматически становится `number | null` после изменения DTO; ReportItem-проекция обязана быть совместима).

**Что НЕ менялось:**

- Backend / контракты / capabilities — нетронуто.
- Логика в `ReportActionsMenu.vue:247` (`if (!me || props.report.user_id == null) return false`) — уже использует loose `== null`, что корректно обрабатывает оба случая (`null` и `undefined`). Менять не пришлось.
- `mocks/handlers.ts` / `mocks/data.ts` — мок-данные присваивают `user_id: 1` (number), что валидно сужается до `number | null`. Сами моки не трогали.

**Проверка:**

- `cd front && npm run type-check` — clean (`vue-tsc --build` без ошибок).
- `cd front && npm run lint` — 0 errors. Остаётся всё тот же pre-existing warning в `front/src/plugins/persist.ts:91` (comma-dangle, не наш файл).
- i18n — ключи не менялись.

**Не пушу.** Готово к commit владельцем.

---

## 2026-05-24 — Mini-chat API layer (под предстоящий рефакторинг MiniChatWidget)

**Контекст:** backend готов и протестирован (139 chat tests). Появились новые endpoints/поля для mini-chat'а с lazy-creation flow. Эта итерация — **только API-слой**: типы, axios-обёртки, entity-маппинг, тонкие сервисные wrappers. UI (MiniChatWidget / useMiniChat / dropdown) — следующая итерация, в этом коммите не тронут.

**Изменено:**

- `front/src/api/types/chats.ts` — расширены DTO и добавлены request-типы:
  - Новый union `ChatScopeType = 'report' | 'general' | 'report_generation'`. Третий вариант оставлен для совместимости с legacy / full-screen чатами (после backend backfill).
  - `ChatListItemDto`: добавлены **required** `scope_type`, `last_message_at: string | null`, `user_message_count: number`, `is_active_window: boolean`. Backend гарантирует на всех endpoints списка.
  - `ChatDetailDto`: добавлен **required** `scope_type`. Mini-chat аггрегаты (`last_message_at`, `user_message_count`, `is_active_window`) — **optional**, потому что не все legacy endpoints (`POST /chats`, `GET /chats/{id}`) их шлют; новые `/resume` и `/messages` (inline-create) шлют всегда.
  - `SendMessageResponseDto` — обновлён JSDoc (теперь покрывает и legacy `POST /chats/{id}/messages`, и новый `POST /chats/messages`). Shape не менялся.
  - Новые request-типы: `ListChatsRequest`, `ResumeChatRequest`, `InlineCreateMessageRequest` (с JSDoc'ом по контракту из `chats_frontend.md`).
- `front/src/api/chats.ts` — 3 новые функции на `chatsApi`:
  - `fetchChatsScoped(params)` — `GET /api/chats` с query-params `{scope_type, report_id, limit}`. Существующий `fetchChats()` (без params) **оставлен как есть** — full-screen sidebar продолжает на нём работать, не ломаем существующие call-sites.
  - `resumeChat(params)` — `GET /api/chats/resume`. **204 No Content → `null`**; 200 → `ChatDetailDto`. Защита от прокси, возвращающих пустую строку в `response.data` — два пути на `null`.
  - `sendInlineCreate(payload)` — `POST /api/chats/messages` (inline create + first message в одной транзакции). 202 → `SendMessageResponseDto`. `chat` поле всегда заполнено (caller нуждается в `chat.id` для SSE).
- `front/src/entities/chat/types.ts` — entity-слой:
  - Re-export `ChatScopeType` из api типов.
  - `ChatListItem`: добавлены `scopeType?`, `lastMessageAt?`, `userMessageCount?`, `isActiveWindow?` — **optional** на entity layer, потому что есть существующие in-memory constructors (`useChatsStore.syncChatListItemFromDetail`, `chatHelpers.toChatListItem`, optimistic items) которые их не заполняют. Реальные items из backend через mapper всегда заполнены.
  - `ChatDetail`: добавлен `scopeType` (required), + optional `lastMessageAt`, `userMessageCount`, `isActiveWindow`.
- `front/src/entities/chat/mappers.ts` — `mapChatListItemDtoToItem` и `mapChatDetailDtoToDetail` снэйк→кэмел маппинг новых полей (passthrough для `null`-able агрегатов).
- `front/src/entities/chat/index.ts` — экспорт `ChatScopeType`.
- `front/src/services/ChatService.ts` — 3 тонкие обёртки:
  - `fetchChatsScoped(params)` — wraps `chatsApi.fetchChatsScoped` + maps to `ChatListItem[]`.
  - `resume(params)` — wraps `chatsApi.resumeChat` + maps `ChatDetailDto | null` → `ChatDetail | null`.
  - `sendInline(payload)` — wraps `chatsApi.sendInlineCreate` + returns `SendMessageResult` (та же envelope что у существующего `sendMessage`).
- `front/src/mocks/data.ts` + `front/src/mocks/handlers.ts` — обновлены фикстуры и обработчики, чтобы соответствовать **required** новым полям в `ChatListItemDto` / `ChatDetailDto`. Маппинг scope в моках мирорит реальный backend backfill: `report_generation` → `scope_type='report_generation'`, `quick_qa` → `'general'`.

**Новые методы доступные UI-слою (для следующей итерации):**

```ts
import { useServices } from '@/services'
const { chatService } = useServices()

// Scoped list (mini-chat в Toolbox дропдаун)
const items = await chatService.fetchChatsScoped({ scope_type: 'general', limit: 20 })
const reportItems = await chatService.fetchChatsScoped({ scope_type: 'report', report_id: 42 })

// Auto-resume на mount/open
const chat = await chatService.resume({ scope_type: 'general' })
const reportChat = await chatService.resume({ scope_type: 'report', report_id: 42 })
// chat === null → нет активного чата → открываем in-memory; иначе loadChat(chat.id)

// Первое сообщение на in-memory чате → материализация
const result = await chatService.sendInline({
  scope_type: 'report',
  report_id: 42,
  content: 'Какая динамика по месяцам?',
  report_context: { primaryModel: 'Finances', reportTitle: '…', columns: [...], filters: {...} },
})
// result.chat — свежесозданный ChatDetail с id; result.streamUrl — для SSE подписки
```

**Edge-cases / решения:**

1. **204 No Content на `/resume`** — нет в проекте прецедента, использован стандартный паттерн: `if (response.status === 204) return null`, плюс defensive guard на пустую строку в `response.data` (некоторые axios stacks при пустом body выдают `''` вместо `undefined`).
2. **`ChatListItem` aggregates как optional** — компромисс ради не-трогания UI слоя в этой итерации. Существующие in-app конструкторы (`useChatsStore`, `chatHelpers`) не знают о новых полях и собирают partial-shape; их доработка — следующая итерация одновременно с использованием новых полей в UI. На уровне API DTO поля **required** — контракт со фронтом строгий, моки соответствуют.
3. **`ChatScopeType` включает `'report_generation'`** — фронт получит этот вариант на старых чатах (после backend backfill, где `type='report_generation'` имел `report_id=NULL`). UI'у в следующей итерации нужно решить как отображать (скорее всего как 'general' + label "AI-конструктор").
4. **`chat.type` vs `chat.scope_type`** — это два разных поля. `type` — AI-режим (`report_generation` / `quick_qa`), `scope_type` — UI-привязка (`report` / `general`). Не путать.
5. **`fetchChats()` и `fetchChatsScoped()`** разнесены сознательно — full-screen sidebar (useAiChatPage / useAiReportsPage) остаётся на старом методе без query-params, чтобы не задеть их behavior; mini-chat использует scoped вариант. Backend поддерживает оба вызова на одном endpoint (`GET /api/chats`).

**Что НЕ трогалось:**

- `front/src/components/chat/MiniChatWidget.vue` — UI следующей итерации.
- `front/src/components/chat/composables/useChat.ts`, `useChatMessaging.ts`, `useChatStream.ts` — пока не нужно.
- `front/src/stores/chats.ts` (Pinia `useChatsStore`) — пока не нужно.
- `front/src/pages/AiChatPage/`, `front/src/pages/AiReportsPage/` — full-screen чаты, не наша зона.
- `front/src/pages/shared/useChatPage.ts` — full-screen orchestrator.
- i18n / locale файлы — текстовые изменения не требуются.
- Backend / сидеры — нетронуто.

**Проверка:**

- `cd front && npm run type-check` — clean (`vue-tsc --build`, 0 ошибок).
- `cd front && npm run lint` — 0 errors. Pre-existing warning в `front/src/plugins/persist.ts:91` (comma-dangle, не наш файл, известное расхождение).
- `npm run build` / `npm run dev` — **не запускались** (по заданию: UI следующей итерации).
- i18n parity — ключи не трогали.

**Не пушу.** Готово к commit владельцем.

---

## 2026-05-24 — Mini-chat UI рефакторинг под preview + dropdown flow

**Контекст:** API-слой mini-chat'а (sendInline / resume / fetchChatsScoped) уже был сделан в предыдущей итерации. Эта итерация переписывает виджет в Toolbox под новый UX: preview-state без записи в БД, dropdown с историей в шапке, auto-resume при открытии, lazy creation чата при первом сообщении.

**Изменено / создано:**

- **(NEW)** `front/src/components/chat/composables/useMiniChat.ts` — основная state-machine виджета. Управляет preview-state, scope detection (route='ReportDetail' + reportContextStore → 'report'; иначе 'general'), auto-resume, dropdown loading, send (sendInline в preview / sendMessage в существующем чате), SSE stream через `useChatStream`, scope-change watcher (reset state при переходах между report-страницами или из/в report scope).
  - **Изоляция от `useChatsStore` — ключевое решение**: composable НЕ дёргает `setActive*` / `prependChat` / `syncChatListItemFromDetail`. Если бы дёргал — full-screen `/ai-chat` или `/ai-reports` страница на mount'е подцепила бы mini-chat'овский чат как активный, что разрушило бы UX (пользователь открыл бы свой чат в полноэкранке и попал бы в чужой). Дублирует ~150 LOC SSE-обвязки из `useChatMessaging` (appendTimelineEvent / applyTextDelta / finalizeAssistantMessage / subscribeToAssistantStream) — это компромисс ради изоляции. Альтернативой было бы вынести в helper, но `useChatMessaging` сейчас слишком плотно связан с `chatsStore` (через `syncChatListItemFromDetail`); общий helper потребовал бы рефакторинга обоих слоёв.
  - `shallowRef<ChatDetail>` для `currentChat` — immutable update pattern, как в `useChat`. Replace через `replaceMessageInChat` (новый объект chat вместо мутации).
  - `loadDropdown()` вызывается параллельно в `initializeOnOpen` (`void`), после `finalizeAssistantMessage` (refresh после settle), и после `sendInline` (refresh после материализации). Best-effort, ошибки не блокируют основной flow.
  - Race-protection: `nextTick` перед `subscribeToAssistantStream` + pending-events buffer (то же что в `useChatMessaging`) на случай первых SSE-фреймов раньше commit'а placeholder'а в `currentChat.messages`.
  - Auto-resume при `initializeOnOpen` детектит in-flight assistant (status=pending|running) и сразу подключается к stream'у — покрывает сценарий, когда mini-chat открыли а в другой вкладке этот же чат в процессе AI-турна.

- **(NEW)** `front/src/components/chat/MiniChatHeaderDropdown.vue` — компактный PrimeVue Popover dropdown:
  - Триггер: кнопка с `pi-sparkles` иконкой, текущий title чата (truncated 60ch), arrow `pi-chevron-down`.
  - Body: pinned "Новый диалог" (`pi-plus`, primary color) → divider → header "Недавние диалоги" → лист или empty/loading state.
  - Items показывают title (fallback на первое сообщение → "Новый чат"), relative time через `Intl.RelativeTimeFormat` (без heavyweight date lib для одной точки использования). Stale-items (`isActiveWindow === false`) с opacity 0.6.
  - Highlight `currentId` через `is-active` класс.
  - Emits: `select(chatId)`, `new-chat`.
  - **Z-index**: `TOOLBOX_POPOVER_BASE_Z_INDEX + 100` — dropdown открывается *внутри* mini-chat popover'а, нужно явно поднять выше базы, потому что PrimeUix counter inkrements ненадёжны (см. memory `primevue_zindex_runtime_quirk`).
  - Внутренний overlay имеет глобальные `:global(...)` SCSS-селекторы (та же причина что в widget'е — `append-to="body"` переносит элемент в `<body>`, scoped `:deep()` не достучится).

- **(REWRITTEN)** `front/src/components/chat/MiniChatWidget.vue`:
  - Switched с `useChat()` → `useMiniChat()`. Removed: `chatsStore`, `useRoute`, `findLastChatByType`, `resolveTargetChat`, `initializeChat`, watcher на `reportContextStore.reportId` (теперь reset state живёт внутри `useMiniChat` scope-change watcher'а), local `isInitializing` ref (теперь из composable).
  - Header переехал: вместо `<i pi-sparkles> {{ title }}` стал `<MiniChatHeaderDropdown />` (clickable trigger с current title + dropdown). Actions (expand / close) остались справа.
  - **Expand-кнопка `↗`** теперь disabled в preview-state: `isExpandDisabled = isPreview || !currentChat || isInitializing || isLoadingChat`. Tooltip переключается на "Сначала отправьте сообщение" / "Send a message first" когда disabled.
  - Body имеет 3 ветки рендера: loading → preview-empty-state → existing-chat-empty-state → message list. Preview-state получает свой placeholder ("Начните новый диалог" / "Спросите что угодно про этот отчёт").
  - `handleSubmit` — обёртка над `mini.sendMessage(content, {reportContext})`; building `reportContext` payload оставлен в widget'е (та же логика что была раньше — primaryModel + columns flat list + filters jsonb). `useMiniChat` ничего про `reportContextStore` не знает, кроме `currentScope` computed (scope detection); payload билдит widget и передаёт через параметр.
  - `defineExpose({ syncPopover, realign })` сохранён — Toolbox integration contract не сломан.
  - Refs destructure'аны из `mini` для idiomatic template access (vue's template-auto-unwrap не работает для refs вложенных в объект).

- **(UPDATED)** `front/src/components/chat/locale/en.json` + `ru.json` — добавлены 8 ключей (RU↔EN parity verified):
  - `miniChat.newChat` = "New chat" / "Новый диалог"
  - `miniChat.recentChats` = "Recent chats" / "Недавние диалоги"
  - `miniChat.noRecentChats` = "No recent chats" / "Нет недавних диалогов"
  - `miniChat.loadingHistory` = "Loading history…" / "Загрузка истории…"
  - `miniChat.previewPlaceholder` = "Start a new conversation" / "Начните новый диалог"
  - `miniChat.previewPlaceholderOnReport` = "Ask anything about this report" / "Спросите что угодно про этот отчёт"
  - `miniChat.expandDisabled` = "Send a message first" / "Сначала отправьте сообщение"
  - `miniChat.triggerFallback` = "AI chat" / "AI-чат"
  - `miniChat.errors.loadHistoryFailed` = "Failed to load chat history" / "Не удалось загрузить историю чатов"

**НЕ ТРОГАЛОСЬ:**

- `front/src/components/chat/composables/useChat.ts`, `useChatMessaging.ts`, `useChatStream.ts` — full-screen flow не должен меняться. `useMiniChat` импортирует `useChatStream` напрямую (это переиспользуемый low-level SSE primitive).
- `front/src/pages/AiChatPage/`, `front/src/pages/AiReportsPage/`, `front/src/pages/shared/useChatPage.ts` — full-screen pages нетронуты. Cross-tab handoff через `?activate=N` query param сохранён.
- `front/src/stores/chats.ts` — изолированы; новых helper'ов типа `prependChatItem` без `setActive` НЕ добавлено (см. backlog ниже).
- `front/src/stores/reportContext.ts` — только чтение.
- `front/src/components/Toolbox/Toolbox.vue` — props / events контракт `MiniChatWidget` тот же (`compact`, `tooltipOptions`, `toggle-request`, `visibility-change`).
- Backend / API контракты — нетронуто.

**Принятые решения / open questions:**

1. **Дублирование SSE-обвязки** (useMiniChat дублирует ~150 LOC из useChatMessaging) — сознательный trade-off ради изоляции от `chatsStore`. Альтернатива (extract в shared helper) потребовала бы рефакторинга `useChatMessaging` — отдельная задача, не блокирует. Followup для frontend-specialist'а после возвращения.

2. **Dropdown синхронизация со store** — не добавил `prependChatItem` без `setActive` в `useChatsStore`. Соответственно, если открыть full-screen `/ai-chat` ПОСЛЕ создания чата через mini-chat'овский `sendInline`, новый чат появится в full-screen sidebar'е только после `fetchChats()` на mount'е full-screen страницы (это уже работает — useChatPage делает `fetchChats` в `initScope`). Текущий результат: пользователю придётся переключить страницу или релогиниться — TODO добавить sync если потребуется.

3. **Stale-items** (`isActiveWindow === false`) показываются в dropdown'е с opacity 0.6 — soft visual hint, не блокирует выбор. Backend гарантирует sort by `updated_at desc`, так что свежие всегда наверху.

4. **Preview-placeholder** в EmptyState отдельный от existing-chat-state placeholder'а — preview ассоциируется с "Начните новый диалог", existing-but-empty (исторически невозможный, но защитный) — "Напишите сообщение, чтобы начать чат".

5. **Expand `↗` в preview** — disabled с тултипом "Сначала отправьте сообщение". Альтернатива — скрыть полностью — отвергнута, т.к. tooltip даёт affordance, пользователь поймёт что нужно сделать чтобы её активировать.

6. **`placeholderText` computed** имеет идентичные обе ветки (preview vs existing) — оставлены branchy для будущих tweaks (например preview мог бы получить "Send your first message…"). Не упростил до тернарника намеренно.

7. **`useChatStream.stop()` + `reset()` перед сменой currentChat** — explicit cleanup. Stream автоматически no-op'ит settle-callback'и через guard `currentChat.value?.id !== chatId`, но explicit cleanup надёжнее (предотвращает потенциальные race conditions при быстром переключении).

**Проверка:**

- `cd front && npm run type-check` — clean (vue-tsc --build, 0 ошибок).
- `cd front && npm run lint` — 0 errors. Pre-existing warning в `front/src/plugins/persist.ts:91` (comma-dangle, не наш файл).
- i18n parity — 0 пропущенных ключей в обе стороны (проверено Python-скриптом).
- `npm run build` / `npm run dev` — **не запускались** (по заданию).

**Followup для QA / PM:**

- **QA сценарии:**
  - Открыть mini-chat на /reports/:id с hasReportContext → preview-state OR resume чата report-scope.
  - Отправить первое сообщение → должен материализоваться чат, появиться в dropdown'е сверху, expand-кнопка стать активной.
  - Открыть dropdown → выбрать другой чат → должен загрузиться без 401/404, без побочных эффектов на full-screen pages.
  - "Новый диалог" в dropdown'е → preview-state restore, input доступен, expand disabled.
  - Перейти с одного report (/reports/1) на другой (/reports/2) с открытым mini-chat → state reset, при следующем open — новый resume для нового scope.
  - Открыть mini-chat на не-report странице (/companies или /home) → general scope, dropdown показывает только general чаты.
  - Параллельно: открыть full-screen `/ai-chat` → проверить что mini-chat'овский чат НЕ становится активным в full-screen.

- **PM verify:** контракт виджета с Toolbox не нарушен (props/events/exposed methods). RU↔EN parity OK. Никаких console.log / dd. Не упоминается Claude/AI.

- **Не пушу.** Готово к commit владельцем.

---

## 2026-05-24 — mini-chat: fix "New chat" button stuck in preview-state

**Bug:** на `/reports/1` при открытии mini-chat в preview-state кнопка «Новый диалог» в header-dropdown была `disabled` (CSS `is-disabled` + `disabled` attribute). На `/company` (general scope, где resume вернул чат) — работала. Действие должно быть доступно всегда: в preview-state как idempotent no-op (закрыть dropdown), в режиме открытого чата — `enterPreview()`.

**Fix:** `front/src/components/chat/MiniChatHeaderDropdown.vue`

1. Шаблон (строки 36-43) — снял `:class="{ 'is-disabled': isPreview }"` и `:disabled="isPreview"` с `.mini-chat-dropdown__new`.
2. `handleNewChat()` (~158) — idempotent: если `isPreview` → только `closePopover()` (no-op + dismiss); иначе как раньше — `emit('new-chat')` + `closePopover()`. Эмит из preview-state опущен, чтобы родитель не дёргал `enterPreview()` впустую (оно уже бы выполнило `stream.stop/reset/clear` и переставило бы `isPreview=true`, что лишний noise).
3. SCSS — убрана dead-rule `&.is-disabled` и заменён hover-guard `&:hover:not(.is-disabled)` на простой `&:hover`.

Prop `isPreview` оставлен в `Props` — теперь используется только обработчиком для branching.

**Что НЕ трогал:**
- backend
- auto-resume (`initializeOnOpen`)
- scope detection
- `useMiniChat.enterPreview()` — работает корректно (по QA-логам на /company)

**Type-check + lint:**
- `npm run type-check` — clean
- `npm run lint` — clean (1 pre-existing warning в `plugins/persist.ts`, не наш файл)

**Other findings (не правил):**
- Никаких других багов в коде во время фикса не встретил.

## 2026-05-24 — mini-chat: regression fix — expand `↗` route всегда `/ai-chat`

**Bug (регрессия):** кнопка `↗` «открыть на весь экран» в mini-chat снова вела на `/ai-reports` вместо `/ai-chat`. После rework'а mini-chat все чаты создаются с `type='quick_qa'` (backend `ChatController::inlineCreateMessage` хардкодит `quick_qa`). Full-screen `/ai-chat` фильтрует по `type='quick_qa'`, `/ai-reports` — по `type='report_generation'`. Mini-chat чат, открытый в `/ai-reports`, не находится в списке и не активируется.

**Root cause:** `openInFullScreen` роутил по `current.type === 'quick_qa' ? '/ai-chat' : '/ai-reports'`. Но partial-снапшот чата из inline-create (`SendMessageResponseDto.chat`) **не содержит `type`** (см. doc-комментарий: «title, report_id, scope_type, ai_context, report» — `type` отсутствует). После первого send'а `currentChat.value.type === undefined` → тернарник падает в `/ai-reports`. Mini-chat всегда quick_qa, маршрут не должен зависеть от `type` снапшота.

**Fix:** `front/src/components/chat/MiniChatWidget.vue` (~315-330, `openInFullScreen`)

- Было: `const path = current.type === 'quick_qa' ? '/ai-chat' : '/ai-reports'` → `window.open(\`${path}?activate=...\`)`.
- Стало: безусловно `window.open(\`/ai-chat?activate=${current.id}\`, ...)`. Убрана зависимость от `type` / `scope_type`. Обновлён doc-комментарий с объяснением регрессии.

**Проверено:**
- `/ai-chat` корректно активирует чат по `?activate={id}` через `useActivateChatIdQueryParam` + `useChatPage.initScope` (scope-type guard, `type: 'quick_qa'`). Report-контекст не теряется — он внутри истории сообщений как prefix.

**Что НЕ трогал:**
- backend
- auto-resume / scope detection / dropdown

**Type-check + lint:**
- `npm run type-check` — clean
- `npm run lint` — 1 pre-existing warning в `plugins/persist.ts` (не наш файл), наших ошибок/warnings нет.

## 2026-05-24 — mini-chat: 3 точечных tech-debt фикса (PM code review)

Не функциональные, по результатам PM code review итерации mini-chat rework.

**Fix #3 — `ChatScopeType` union vs backend validation**

`scope_type` бэкенд принимает/хранит только `in:report,general`. Значение `'report_generation'` в union `ChatScopeType` — ошибочное: это значение поля `type` (`ChatType`), а не `scope_type`. Backfill-миграция уже разложила все старые чаты в `'report'` (report_id IS NOT NULL) либо `'general'`; в `scope_type` `'report_generation'` нет в БД вообще. Подтверждено по `chats_frontend.md` (response-пример: `type: "report_generation"` + `scope_type: "general"`) — `scope_type` всегда `'report'`/`'general'` и в request, и в response. → `'report_generation'` убран из union целиком (не разделял типы).

- `front/src/api/types/chats.ts` — `ChatScopeType = 'report' | 'general'`, doc-комментарий переписан (объясняет backfill-правило и связь с `ChatType`).
- `front/src/mocks/data.ts` — два чата с `scope_type: 'report_generation'` (оба с `report_id: 42`) → `scope_type: 'report'` по backfill-правилу.
- `front/src/mocks/handlers.ts` (~96-98) — фабрика создавала чаты с `report_id: null`, мапила scope по `type`. По backfill-правилу null-report → `'general'`; убрана ветка `report_generation`, комментарий исправлен.

**Fix #4 — мёртвый код в `MiniChatHeaderDropdown.vue`**

Удалён debugging-артефакт `const _alignmentAnchor = computed(() => triggerRef.value); void _alignmentAnchor`. `triggerRef` остаётся живым — он привязан в шаблоне (`ref="triggerRef"` на trigger-кнопке), так что computed был чистым мусором. Заодно убран ставший неиспользуемым импорт `computed`.

- `front/src/components/chat/MiniChatHeaderDropdown.vue` — удалены строки 223-224 + `computed` из import (строка 87).

**Fix #5 — захардкоженный `route.name === 'ReportDetail'` в `useMiniChat.ts`**

Реальное имя роута подтверждено — `'ReportDetail'` (`router/routes/base.ts`, `path: '/reports/:id'`). Enum/const имён роутов в проекте нет (имена — inline-литералы). Выбран **reportContext-based вариант** (не const route name): scope определяется по `reportContextStore.hasReportContext && reportId > 0` без `route.name` вообще.

Обоснование: единственный писатель стора — `ReportPage/index.vue` (`set()` на загруженном отчёте, `clear()` на unmount/смене id), больше нигде reportContext не выставляется — проверено grep'ом. Так что hydrated-контекст с положительным `reportId` однозначно = «мы на странице отчёта с живым отчётом». Это и так предусловие report-scope (resume/inline-create требуют `report_id`), и оно устойчиво к переименованию роута, в отличие от строкового литерала. Добавлен guard `reportId > 0` (паттерн из [[race antipatterns]] — falsy/zero id). `useRoute` стал не нужен — импорт и `const route` удалены.

- `front/src/components/chat/composables/useMiniChat.ts` — `currentScope` computed переписан на reportContext-based; удалён `import { useRoute }` и `const route = useRoute()`; doc-комментарий объясняет выбор.

**Type-check + lint:**
- `npm run type-check` — clean
- `npm run lint` — 1 pre-existing warning в `plugins/persist.ts` (не наш файл), наших ошибок/warnings нет.

## 2026-05-24 — ЯДРО глобальной модалки генерации отчёта (шаг 1/3: store + composable + модалка)

Задача: заменить страницу `/ai-reports` глобальной модалкой генерации отчёта поверх любой страницы. Это **первый из трёх** frontend-шагов — только ядро (store + composable + компонент модалки + глобальный монтаж). Триггеры открытия (кнопки, action-marker, ReportActionsMenu) и удаление `/ai-reports` — следующие шаги, в этом шаге НЕ делались. `/ai-reports` и `/ai-chat` не тронуты, продолжают работать.

Зафиксированные решения (от пользователя): модалка — только чат-диалог (без превью отчёта); после генерации остаёмся в модалке + CTA «Открыть отчёт» (скрыта если уже на странице этого отчёта); lazy-creation для create-mode (чат не создаётся в БД до первого сообщения); edit-mode — resume существующего report_generation чата отчёта; закрытие во время стрима не блокируется (стрим завершится в фоне).

**Созданные файлы:**
- `front/src/stores/reportGenerationModal.ts` — Pinia setup-store. State: `isOpen`, `mode` (`'create'|'edit'`), `reportId`, `chatId`, `prefillPrompt`, `reportUpdatedTick`, `lastUpdatedReportId`. Actions: `open(opts)`, `close()`, `resetOptions()` (вызывается модалкой после `@hide`), `signalReportUpdated(id)` (one-way сигнал «отчёт создан/обновлён, перефетчи» для будущего ReportPage — инкремент `reportUpdatedTick`).
- `front/src/components/chat/composables/useReportGenerationModalChat.ts` — orchestrator-composable по образцу `useMiniChat`. Изолированная SSE-подписка (НЕ трогает `useChatsStore` active-id), immutable shallowRef-обновления `currentChat`, `pendingEventsByMessageId`-буфер, reconnect к in-flight стриму. Отличия от mini-chat: scope всегда `general`, тип `report_generation`; create-mode → preview-state + lazy `sendInline({ type: 'report_generation', scope_type: 'general' })`; edit-mode → `fetchChat(chatId)` + reconnect; **трекинг `createdReportId`** (см. ниже).
- `front/src/components/chat/ReportGenerationModal.vue` — PrimeVue `Dialog` (modal, 720px, teleport в body), смонтирован глобально. Header по mode. Body: только чат-диалог (preview-EmptyState / `ChatMessageList` — thinking timeline и tool-статусы рендерятся внутри bubble как на `/ai-reports`; action-marker НЕ включён). Footer: `ChatInput` (v-model prefill) + CTA «Открыть отчёт» (`v-if` createdReportId && != currentRouteReportId).

**Изменённые файлы:**
- `front/src/api/types/chats.ts` — `InlineCreateMessageRequest.type?: ChatType` (опциональный, default `'quick_qa'` на бэке; модалка шлёт `'report_generation'`). `ChatService.sendInline` / `chatsApi.sendInlineCreate` не менялись — они уже прокидывают весь payload, так что mini-chat (без `type`) остаётся обратно-совместим.
- `front/src/components/chat/ChatInput.vue` — добавлен опциональный `modelValue` (v-model) для prefill. Внутренний `content` стал writable computed: при наличии `modelValue` проксирует через `update:modelValue`, иначе fallback на локальный ref (существующие вызовы без v-model не затронуты). Watch на `modelValue` пересинхронизирует overflow-clamp для многострочного prefill.
- `front/src/components/chat/index.ts` — экспорт `ReportGenerationModal`.
- `front/src/layouts/DefaultLayout/index.vue` — `<ReportGenerationModal v-if="showLayout" />` рядом с `<Toolbox>` (не на Login). Модалка сама управляется через store, рендерится всегда, visible=false по умолчанию.
- `front/src/components/chat/locale/{en,ru}.json` — блок `reportGenerationModal.*` (title.create, title.edit, placeholder, previewCreate, previewEdit, openReport). RU↔EN симметрия проверена скриптом — рассинхрона нет.

**Как определяется «отчёт создан/обновлён» (КРИТИЧНО):**
Сигнал — `currentChat.reportId`. Это тот же сигнал, что `/ai-reports` использует для `ChatReportBanner` (`v-if="currentChat?.reportId"` → ссылка `/reports/{reportId}`). Маппер `mapChatDetailDtoToDetail` заполняет `reportId` из `report_id`. После settle assistant-сообщения `finalizeAssistantMessage` делает `chatService.fetchChat(chatId)` (как в mini-chat) — свежий `ChatDetail` приносит уже заполненный `reportId` (бэк populate-ит `chats.report_id` после `ReportTool.handleSuccess`). В `syncCreatedReport(freshChat)`: если `reportId != null` → `createdReportId = reportId` (драйвит CTA) + `modalStore.signalReportUpdated(reportId)` (для будущего refetch'а открытой страницы отчёта). Сигнал шлётся на каждый settle с непустым reportId (в т.ч. `update_report` по тому же id), edit-mode сидит `createdReportId = reportId` сразу в `init`.

**Как переиспользован useMiniChat:**
Скопированы 1:1: `replaceMessageInChat` (immutable shallowRef-патч сообщения), `TIMELINE_EVENT_TYPES`/`isTextDeltaPayload`, `pendingEventsByMessageId`-буфер + `flushPendingEvents`/`appendTimelineEvent`/`applyTextDelta`, `finalizeAssistantMessage` (carry-over runtime `timelineEvents`/`thinkingContent` + refetch без `chatsStore`), `subscribeToAssistantStream` (через `useChatStream`), reconnect к pending/running при загрузке. Изолированность — как в mini-chat: своя SSE-подписка, ноль обращений к `useChatsStore`.

**Type-check + lint:**
- `npm run type-check` — clean
- `npm run lint` — 1 pre-existing warning в `plugins/persist.ts` (не наш файл), наших ошибок/warnings нет.

## 2026-05-24 — Глобальная модалка генерации (шаг 2/3: триггеры открытия + ReportActionsMenu «Редактировать с AI» + ReportPage refetch)

Задача: подключить **триггеры открытия** модалки генерации (ядро из шага 1 уже смонтировано в DefaultLayout), добавить пункт «Редактировать с AI» в ReportActionsMenu и SPA-refetch ReportPage по сигналу `reportUpdatedTick`. Удаление route `/ai-reports` — следующий шаг (3/3), в этом шаге НЕ делалось: route и `AiReportsPage` живы.

### Триггеры открытия модалки (B3)
Найдены и переведены **все** текущие точки входа в `/ai-reports`:
1. **Кнопка `+` «Открыть AI-конструктор»** в шапке ReportsPage → `useReportsPageActions.openAiReports()`. Было `router.push({ name: 'AiReports' })`, стало `modalStore.open({ mode: 'create' })`.
2. **Тайл «Сгенерировать свой отчёт»** (`GenerateReportTile`) → `useReportsPageActions.generateCustomReport()`. Было: `chat.createAndOpenChat('report_generation', { setCurrent: false })` + `router.push({ name: 'AiReports', state: { activateChatId } })`. Стало `modalStore.open({ mode: 'create' })` — eager-создание чата убрано (lazy-creation в модалке). Метод стал синхронным (было `async`). Guard `canUseAi` сохранён в обоих.
3. **Action-marker CTA** в ai-chat (quick_qa предлагает создать отчёт) → `useChatPage.handleActionMarker`. Было: `createAndOpenChat('report_generation', { setCurrent: false })` + `chatsStore.setPendingFirstMessage(...)` + `router.push({ name: 'AiReports' })`. Стало `modalStore.open({ mode: 'create', prefillPrompt: marker.prompt })` — промпт пишется в input модалки **без авто-отправки** (ядро на строке 368 `useReportGenerationModalChat` пишет `inputValue.value = modalStore.prefillPrompt`). Метод стал синхронным (было `async`); `@action="handleActionMarker"` — event-handler, sync ок. **Остальная логика `useChatPage` не тронута** (initScope, `consumePendingFirstMessage`, cross-tab `?activate=` handoff, mini-chat изоляция).

### ReportActionsMenu — «Редактировать с AI» (B4)
- **Гейт viewer** в `ReportPage/index.vue`: `<ReportActionsMenu v-if="report && canSeeActionsMenu">`. `canSeeActionsMenu = canSeeReportActionsMenu(role)` — новая capability = `role !== 'viewer'`. Viewer не видит «три точки» совсем (даже инфо-блок).
- **`showEditWithAi`** в ReportActionsMenu.vue = `canEditReportWithAI(isOwner, isSystem) && reportChatId != null`. Новая capability `canEditReportWithAI(isOwner, isSystem) = isOwner && !isSystem` (роль не важна — owner любой роли; системные user_id=null → isOwner=false автоматически). `reportChatId` = `props.report.chatId ?? null`. Системные / старые отчёты без pinned-чата → нет кнопки.
- Клик → `modalStore.open({ mode: 'edit', reportId: props.report.id, chatId })` + `closePopover()`.
- `hasAnyAction` пересчитан: `showEditWithAi || showPublish || showUnpublish || showDelete` (блок действий показывается если есть хоть одно).
- Иконка `pi-sparkles` (тот же AI-иконка, что в MiniChatWidget / ReportGenerationModal / ChatMessageBubble — проектный стандарт).
- i18n `actionsMenu.editWithAi` (en: «Edit with AI», ru: «Редактировать с AI»). RU↔EN симметрия проверена.

### Проброс `chat_id` (DTO → entity)
- `api/types/reports.ts` — `ReportDto.chat_id?: number | null` (бэк уже отдаёт в `GET /api/reports/{id}`).
- `entities/report/types.ts` — `Report` уже `extends Omit<ReportDto, ...>` без omit'а `chat_id`, поле проходит автоматически (mapper `mapReportDtoToReport` делает spread).
- `entities/report/reportItem.ts` — `ReportItem.chatId?: number | null` + маппинг в `mapReportToItem`: `chatId: report.chat_id ?? null`.

### ReportPage refetch на сигнал (B5)
- `ReportPage/index.vue`: `fetchReport` теперь деструктурируется из `useReportPage()`; добавлен `watch(() => modalStore.reportUpdatedTick, () => { if (modalStore.lastUpdatedReportId === reportId.value) void fetchReport() })`. Edit-with-AI модалка обновила отчёт прямо на его странице → SPA-refetch без полной перезагрузки. Ключ — `reportUpdatedTick` (не id), чтобы back-to-back правки одного отчёта каждый раз срабатывали; id-guard отсекает чужие отчёты.

### Изменённые файлы
- `front/src/api/types/reports.ts` — `ReportDto.chat_id`.
- `front/src/entities/report/reportItem.ts` — `ReportItem.chatId` + маппинг.
- `front/src/shared/auth/capabilities.ts` — `canSeeReportActionsMenu`, `canEditReportWithAI`.
- `front/src/pages/ReportsPage/composables/useReportsPageActions.ts` — оба триггера на `modalStore.open`; убраны `useChat`/`createAndOpenChat`/router-state.
- `front/src/pages/shared/useChatPage.ts` — `handleActionMarker` на `modalStore.open` (prefill, sync); добавлен импорт+инстанс `useReportGenerationModalStore`.
- `front/src/pages/ReportPage/index.vue` — гейт `canSeeActionsMenu`, refetch-watch, `fetchReport` в деструктуризации, импорты.
- `front/src/pages/ReportPage/components/ReportActionsMenu.vue` — кнопка «Редактировать с AI», `showEditWithAi`/`reportChatId`/`onEditWithAi`, `hasAnyAction`.
- `front/src/pages/ReportPage/locale/{en,ru}.json` — ключ `actionsMenu.editWithAi`.

### Что НЕ менялось
- `AiReportsPage` + route `name: 'AiReports'` (`router/routes/base.ts:37`) — живы, удаление в шаге 3.
- `chatsStore.setPendingFirstMessage` — store-action остался (больше не вызывается из кода, но `consumePendingFirstMessage` ещё используется в `useChatPage.initScope` для mini-chat/cross-tab handoff). Чистку отложить в шаг 3, если станет полностью мёртвым.
- Ядро модалки (store / composable / ReportGenerationModal.vue) не трогал.

### Проверка
- `npm run type-check` — clean.
- `npm run lint` — clean (1 pre-existing warning `plugins/persist.ts`, не наш).
- НЕ запускал dev/build.

### Что отдать QA (happy path)
- ReportsPage: `+` в шапке и тайл «Сгенерировать свой отчёт» открывают модалку (create-mode), НЕ переход на `/ai-reports`.
- ai-chat (quick_qa): клик по CTA action-marker открывает модалку с предзаполненным промптом, без авто-отправки.
- ReportPage своего custom-отчёта (с chat_id): «три точки» → «Редактировать с AI» → модалка edit-mode; после правки отчёт перефетчивается на странице.
- viewer: «три точки» на ReportPage не видны совсем.
- Системный отчёт / чужой отчёт у не-владельца: «Редактировать с AI» отсутствует (но инфо-блок виден, если не viewer).

### Для шага 3 (удаление /ai-reports)
- Все точки входа в `/ai-reports` переведены на модалку (перечислены в B3). Прямых `router.push({ name: 'AiReports' })` в коде больше нет, кроме комментариев.
- Кандидаты на удаление: route `name: 'AiReports'` (`router/routes/base.ts`), `pages/AiReportsPage/` (index.vue + composables), возможно `chatsStore.setPendingFirstMessage` (проверить, остался ли живой вызов `consumePendingFirstMessage` нужен — он ещё используется в `useChatPage.initScope`).
- Открытый вопрос: `useChatPage.consumeActivateChatIdFromRouterState` + `pendingActivateChatId`-ref-handoff завязаны на cross-tab `?activate=` и router-state activation — это инфраструктура и mini-chat, НЕ удалять вместе с /ai-reports без отдельной проверки.

## 2026-05-24 — Удаление страницы /ai-reports + cleanup мёртвого handoff-кода (шаг 3/3, финал)

Задача: удалить retired-страницу `/ai-reports` (все триггеры в шагах 1-2 переведены на глобальную модалку), почистить мёртвый handoff-механизм и обновить вводящие в заблуждение комментарии.

### C1 — route + page удалены
- `router/routes/base.ts`: удалена запись `{ path: '/ai-reports', name: 'AiReports', component: () => import('@/pages/AiReportsPage'), ... }`.
- Директория `front/src/pages/AiReportsPage/` удалена целиком (`index.vue`, `index.ts`, `composables/useAiReportsPage.ts`, `locale/{ru,en}.json`).
- Catch-all `{ path: '/:pathMatch(.*)*', redirect: '/reports' }` уже существует в конце routes → мёртвый URL `/ai-reports` редиректит на `/reports`. Не трогал.

### C2 — cleanup `pendingFirstMessage` (УДАЛЁН)
Grep подтвердил: `setPendingFirstMessage` нигде не вызывается (единственным продьюсером был старый `handleActionMarker → AiReports`, убран в шаге 1-2). Консьюмер становился мёртвым → удалил весь механизм:
- `stores/chats.ts`: удалены `state.pendingFirstMessage`, экшены `setPendingFirstMessage` / `consumePendingFirstMessage`, интерфейс `ChatPendingFirstMessage`, дренаж в `clear()`. `ChatPendingFirstMessage` нигде больше не импортировался (проверено grep'ом). Persist-плагин этот ключ не персистил.
- `pages/shared/useChatPage.ts`: удалён блок `consumePendingFirstMessage()` drain в начале `initScope` (теперь сразу после `fetchChats()` идёт ref-based `pendingActivateChatId` handoff).

**НЕ удалял (по ТЗ):** `useActivateChatIdQueryParam.ts` / `pendingActivateChatId`-ref / `consumeActivateChatIdFromRouterState` — это инфраструктура cross-tab `?activate=N`, живёт после удаления /ai-reports (используется `/ai-chat` mini-chat expand). `useChatPage` option `pendingActivateChatId` сохранён — его теперь использует только `useAiChatPage` (раньше ещё и `useAiReportsPage`).

### C3 — ссылки и комментарии обновлены
- `pages/ReportsPage/composables/useReportsPageActions.ts`: переименовал `openAiReports` → `openGenerationModal` (имя стало вводить в заблуждение — функция открывает модалку, не навигирует). Обновил docblock-комментарии (убрал упоминания `router.push({ name: 'AiReports' })`).
- `pages/ReportsPage/index.vue`: обновил `@click` и деструктуризацию под новое имя `openGenerationModal`. (`useReportsPage` спредит actions, отдельной правки не требовал.)
- Комментарии, упоминавшие `/ai-reports` как fullscreen-страницу чата, переписаны на `/ai-chat` (актуальный fullscreen-сёрфейс) / модалку: `components/chat/ChatMessageBubble.vue` (emit-doc + .bubble SCSS), `components/chat/ChatMessageList.vue` (SCSS max-width), `components/chat/composables/useChatMessaging.ts` (reportContext-doc), `components/chat/composables/useMiniChat.ts`, `components/chat/MiniChatWidget.vue` (expand-логика — `/ai-reports` больше не существует, упрощён), `components/chat/composables/useChatQueries.ts` (`setCurrent`-doc), `components/chat/composables/useReportGenerationModalChat.ts`, `stores/reportGenerationModal.ts`, `pages/shared/useActivateChatIdQueryParam.ts`, `components/Toolbox/Toolbox.vue` (no-header-страницы — AiReportsPage заменён на AiChatPage в примере).
- Оставшиеся упоминания `/ai-reports` в коде — только исторические комментарии вида «now-removed standalone /ai-reports page» (3 шт.: useChatPage.ts, reportGenerationModal.ts, useReportGenerationModalChat.ts). Не вводят в заблуждение.

### Backlog (не делал — вне явного scope шага)
- `useChatPage.consumeActivateChatIdFromRouterState` (`history.state.activateChatId` handoff) — теперь тоже мёртвый консьюмер: единственным писателем был `/reports` тайл (`router.push({ name: 'AiReports', state: { activateChatId } })`), убранный в шаге 1-2. Grep подтвердил: писателей `history.state.activateChatId` не осталось. Оставлен как безопасный no-op консьюмер (ТЗ требовало трогать только `pendingFirstMessage`; этот handoff отдельно описан в CLAUDE.md как cross-page activation pattern). Кандидат на удаление отдельной задачей.
- `useChatQueries.createAndOpenChat({ setCurrent })` — параметр `setCurrent: false` больше не передаётся ни одним вызовом (оба call-site используют дефолт `true`); фактически мёртвая опция. Не удалял — это публичная опция на `useChat`, вне scope «обновить ссылки/комментарии». Кандидат на упрощение.

### Проверки
- `npm run type-check` — clean (поймал бы висячие импорты удалённой страницы; ошибок нет).
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` `comma-dangle` warning (не регрессия).
- `grep -rn "AiReports\|ai-reports\|AiReportsPage" front/src/` — функциональных ссылок не осталось, только 3 исторических комментария.
- `/ai-chat`, mini-chat, cross-tab `?activate=N` handoff не задеты (useChatPage option `pendingActivateChatId` и `useActivateChatIdQueryParam` сохранены).

### Cleanup мёртвого кода после /ai-reports (backlog из C-шага закрыт)

Удалены два мёртвых остатка, отмеченные в backlog шага 3 (PM подтвердил в code review).

**1. `consumeActivateChatIdFromRouterState` (`history.state.activateChatId` handoff) — УДАЛЁН.**
- Grep подтвердил мёртвость: единственным писателем `state: { activateChatId }` был `/reports`-тайл (`router.push({ name: 'AiReports', ... })`), убранный в шагах 1-2. После удаления — ни одного писателя; все упоминания `activateChatId` (router-state вариант) жили только внутри самого `useChatPage.ts` (определение функции + её вызов в `initScope`).
- `pages/shared/useChatPage.ts`: удалена функция `consumeActivateChatIdFromRouterState` + её docblock; удалён блок-консьюмер в `initScope` (после ref-based `pendingActivateChatId` handoff). `import { useRouter }` и `const router = useRouter()` стали orphaned (router больше нигде не использовался) → тоже удалены.
- Docblock у option `pendingActivateChatId` (упоминает `history.state` как причину выбора ref) оставлен — он объясняет *почему* ref вместо history.state, остаётся корректным.

**НЕ путать (сохранено, живое):** `useActivateChatIdQueryParam` / `?activate=N` query-param — это mini-chat expand на `/ai-chat`. `pendingActivateChatId`-ref в `useAiChatPage` пишется именно из query-param, не из router-state. Не тронуто.

**2. `createAndOpenChat({ setCurrent })` опция — УДАЛЕНА из сигнатуры.**
- Grep подтвердил: ни один call-site не передаёт `setCurrent` / второй аргумент. Оба вызова (`useChatPage.ts:145` handleSend, `:161` handleCreateNew) — `createAndOpenChat(options.type)`; `useChat.ts` лишь ре-экспортит. (Совпадения `setCurrent` в user.ts/companies.ts/session — это несвязанный `setCurrentUser`.)
- `components/chat/composables/useChatQueries.ts`: убран параметр `chatOptions?: { setCurrent?: boolean }` и локальная `const setCurrent`; тело упрощено — `options.currentChat.value = chat` всегда (поведение дефолта `true`). Docblock переписан.

### Проверки (cleanup)
- `npm run type-check` — clean (поймал бы orphaned `router` / висячие ссылки; ошибок нет).
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` `comma-dangle` warning (не регрессия).
- Grep-подтверждение мёртвости выполнено ДО удаления для обоих кандидатов.

---

## 2026-05-24 — Dashboards/Widgets фаза 2/7: выпил dashboard-on-report UI

Задача: убрать «дашборд-вид» со страницы отчёта (`/reports/:id`). Отчёт становится сухой таблицей; дашборды/виджеты переезжают в отдельные сущности (новые страницы — фаза 5). Backend-контракт уже изменён параллельно: `GET /api/reports/{id}` и getData отдают `config: []` (без `dashboard_widgets`); `GET /api/reports/{id}/dashboard-data` удалён (404); preferences-ответ сжат до `{report_id, column_order}`.

### Удалённые файлы (9)
Компоненты:
- `pages/ReportPage/components/ReportDashboardView.vue`
- `pages/ReportPage/components/ReportDashboardWidget.vue`
- `pages/ReportPage/components/ColumnGroupsToggle.vue` — был widget-group toggle, единственный консьюмер `ReportDashboardView`.

Composables:
- `pages/ReportPage/composables/useReportViewMode.ts`
- `pages/ReportPage/composables/useReportDashboard.ts`
- `pages/ReportPage/composables/useDashboardLayout.ts`
- `pages/ReportPage/composables/useDashboardWidgetData.ts`
- `pages/ReportPage/composables/useWidgetGroups.ts`
- `pages/ReportPage/composables/createGroupsComposable.ts` — generic factory, единственный консьюмер `useWidgetGroups` (его docblock прямо это указывал).

`ColumnGroupsToggle.vue` и `createGroupsComposable.ts` не были в явном delete-списке ТЗ, но grep подтвердил, что после удаления dashboard-вью у них не остаётся консьюмеров (dead code). Удалены.

### `pages/ReportPage/index.vue`
- Template: убран `<SelectButton>` Таблица/Дашборд; убран весь блок `<div class="dashboard-section"><ReportDashboardView .../></div>`. `v-if="!isDashboard && ..."` на header-pagination и ColumnManagerPopover упрощены (always-true → убран флаг). Обёртка `<div class="table-mode">` теперь без `v-if` (отчёт всегда таблица).
- Script: удалены импорты `SelectButton`, `useReportViewMode`, `useReportDashboard`, `useDashboardLayout`, `useWidgetGroups`, `ReportDashboardView`, `DashboardWidgetDto`; `canManageDashboardLayout` убран из импорта capabilities (остался `canSeeReportActionsMenu`). Удалены: блок view-mode (`viewMode`/`isDashboard`/`viewModeOptions`/`onViewModeChange`), `canCustomizeDashboard`, `dashboardWidgets` computed, `dashboard`/`dashboardLayout`/`widgetGroups` инстансы. `userStore` сохранён (нужен `canSeeActionsMenu`). `currentFilters` сохранён (используется в `reportContextStore` watch).
- SCSS: удалены `.report-view-toggle` и `.dashboard-section` блоки.

### Слой API/типов
- `api/reports.ts`: удалён метод `fetchDashboardData` + его типы из импортов + декларация в интерфейсе `ReportsApi`. Удалён хелпер `appendFiltersToParams` (его единственным консьюмером был `fetchDashboardData`; `fetchReport`/`fetchGroupRows` имеют собственную inline-логику фильтров).
- `services/ReportService.ts`: удалён метод `fetchDashboardData` + импорты `DashboardDataResponseDto`/`FetchDashboardDataOptions`. `extractTableData` НЕ тронут (это legacy chart-config→table, не dashboard-on-report).
- `api/types/reports.ts`: удалены `DashboardWidgetChartType`, `DashboardWidgetAggregation`, `DashboardWidgetDto`, `DashboardDataMetaDto`, `DashboardDataResponseDto`, `FetchDashboardDataOptions`; поле `dashboard_widgets?` убрано из `ReportConfigDto`. `LocalizedText` импорт сохранён (используется в др. типах).
- `api/types/index.ts` + `api/index.ts`: убраны соответствующие re-export'ы (включая `ReportPreferenceLayoutItem` — стал orphaned).

### Preferences (column_order остаётся, dashboard-поля выпилены)
- `api/types/reportPreferences.ts`: `ReportPreferences` сжат до `{report_id, column_order}` (убраны `view_mode`/`dashboard_layout`/`hidden_widget_groups`). Удалён интерфейс `ReportPreferenceLayoutItem`. Docblock переписан.
- `composables/useReportPreferences.ts`: убрано чтение/запись/мутация `view_mode`/`dashboard_layout`/`hidden_widget_groups`. `seedFromCache`/`writeCache` теперь работают только с `column_order` (одна localStorage-namespace `vizion-column-order`). Удалены namespace-константы `VIEW`/`LAYOUT`/`WIDGET_GROUPS`, хелпер `isLayoutItem`, импорт `ReportPreferenceLayoutItem`. `update()` оставляет только ветку `column_order`. `buildFallbackPreferences` сжат. Комментарии обновлены (drag widget → reorder columns).
- `useColumnOrder.ts`, `ColumnManagerPopover.vue`, `useReportPresentation.ts` — НЕ тронуты (валидны для таблиц; parallel-session уже почистил group-by в presentation через defensive `isGroupRow`-фильтр).

### Параллельная сессия (group-by/drill-down)
- `useReportPresentation.ts` уже содержал defensive `isGroupRow()`-фильтр, отбрасывающий grouped-строки из flat-таблицы — group-by drill-down выпилен параллельной сессией, я не дублировал и не откатывал.
- `createGroupsComposable.ts`/`ColumnGroupsToggle.vue` были помечены как изменённые параллельной сессией, но их единственные консьюмеры — dashboard-вью. Удалил их вместе с dashboard-кодом, не конфликтуя с group-by частью.

### НЕ тронуто (по ТЗ)
- `grid-layout-plus` зависимость (нужна для дашборд-страниц фазы 5).
- `useColumnOrder.ts` / `ColumnManagerPopover.vue` / `column_order` / `column_order.hidden`.
- `ReportActionsMenu`, модалка генерации отчёта.

### Риски для фазы 5
- Capability `canManageDashboardLayout` (`shared/auth/capabilities.ts`) стала orphaned (единственным консьюмером был `canCustomizeDashboard` в ReportPage). Оставлена как чистый primitive для переиспользования дашборд-страницами фазы 5. Если фаза 5 не появится скоро — кандидат на удаление.
- `grid-layout-plus` остаётся в `package.json` без активных консьюмеров до фазы 5 (по ТЗ — намеренно).
- DTO-типы виджетов (`DashboardWidgetDto` и пр.) полностью удалены — фаза 5 будет определять собственные типы для standalone-дашбордов (контракт другой: дашборд — отдельная сущность, не поле в `report.config`).

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` `comma-dangle` warning (не регрессия).
- `grep -rn "dashboard_widgets|view_mode|isDashboard|ReportDashboard|useDashboardLayout|useWidgetGroups|useReportViewMode|fetchDashboardData|DashboardWidgetDto|dashboard-data|ColumnGroupsToggle|createGroupsComposable" front/src` — функциональных не осталось, только 2 пояснительных комментария в `reportPreferences.ts`/`useReportPreferences.ts` (описывают что было удалено).

---

## Фаза 5/7 — Dashboards / Widgets frontend (новая фича, зеркало report-инфраструктуры)

Большая фаза: страницы списка дашбордов + дашборда с grid виджетов, библиотека виджетов, модалка генерации виджета (зеркало report-модалки), mini-chat scope=dashboard, навигация, capabilities. Backend готов (фазы 1-4).

### API-слой + типы (новое)
- `api/types/widgets.ts` — `WidgetChartType`, `WidgetConfigDto`, `WidgetListItemDto`, `WidgetDto`, `WidgetDataDto`, `Create/UpdateWidgetRequest`, `WidgetInUseErrorDto` (409). chart_type живёт только в `config.chart.type` (O5).
- `api/types/dashboards.ts` — `DashboardWidgetPivotDto` (x,y,w,h,sort,visible), `DashboardWidgetDto`, `DashboardListItemDto`, `DashboardDto`, `DashboardDataDto` (keyed by widget id), `Attach/UpdateLayout/Create/Update` requests.
- `api/widgets.ts` — CRUD + data + publish/unpublish.
- `api/dashboards.ts` — CRUD + attach/detach/layout/data/clone.
- `api/index.ts` — re-export новых api + типов.

### Entities + services (новое)
- `entities/widget/{types,mappers,index}.ts` — `Widget`, `WidgetListItem`, `WidgetData` (camelCase) + мапперы snake→camel.
- `entities/dashboard/{types,mappers,index}.ts` — `Dashboard`, `DashboardListItem`, `DashboardWidget`, `DashboardWidgetPivot`, `DashboardData` + мапперы (включая `mapDashboardDataDtoToData` с number-keyed widgets).
- `services/WidgetService.ts`, `services/DashboardService.ts` — бизнес-методы. Зарегистрированы в `services/index.ts` (`Services` type + `createServices`).

### Chat-типы расширены (widget_generation + dashboard scope)
- `api/types/chats.ts`: `ChatType` += `'widget_generation'`; `ChatScopeType` += `'dashboard'`; добавлены `widget_id?`/`dashboard_id?` в `ChatListItemDto`/`ChatDetailDto`; `dashboard_id`/`widget_id`/`type` расширены в `InlineCreateMessageRequest`, `dashboard_id` в `List/ResumeChatRequest`.
- `entities/chat/types.ts`: `widgetId`/`dashboardId` в `ChatListItem` (optional) и `ChatDetail` (required nullable).
- `entities/chat/mappers.ts`: passthrough `widget_id`→`widgetId`, `dashboard_id`→`dashboardId` в обоих мапперах.
- `pages/shared/useChatPage.ts`: `CHAT_COLLECTIONS_BY_TYPE` сужен до `ChatPageType = 'quick_qa'|'report_generation'` (widget_generation живёт только в модалке, не на странице — как report_generation). Убран неиспользуемый импорт `ChatType`.

### Widget-generation модалка (зеркало ReportGenerationModal)
- `stores/widgetGenerationModal.ts` — зеркало `reportGenerationModal`: `open({mode, widgetId?, chatId?, dashboardId?, prefillPrompt?})`, `widgetUpdatedTick`/`lastUpdatedWidgetId` сигнал, `dashboardId` для "Add to dashboard" CTA.
- `components/chat/composables/useWidgetGenerationModalChat.ts` — зеркало `useReportGenerationModalChat`. Отличия: шлёт `type='widget_generation'`; трекает `createdWidgetId` из `currentChat.widgetId`; edit-mode без pinned-чата шлёт `widget_id` в `sendInline` (lazy-create привязан к виджету). НЕ трогает `useChatsStore`.
- `components/chat/WidgetGenerationModal.vue` — зеркало `ReportGenerationModal`. CTA после создания: "Добавить на дашборд" (если открыт из дашборда → `dashboardService.attachWidget` + signal) либо "Готово". Mounted в `DefaultLayout` (рядом с `ReportGenerationModal`). Экспорт в `components/chat/index.ts`.

### Action-marker redirect_to_widget_generation
- `utils/markdown.ts`: `isActionMarker` теперь whitelist `KNOWN_ACTION_MARKERS = {redirect_to_report_generation, redirect_to_widget_generation}` (валидирует `action` как строку из набора).
- `pages/shared/useChatPage.ts`: `handleActionMarker` ветвится по `marker.action` — widget → `widgetModalStore.open`, иначе → `modalStore.open`.

### ChatThinkingTimeline — рендер widget-tool событий
- `components/chat/ChatThinkingTimeline.vue`: `create_widget`/`update_widget` добавлены в `TOOLS_WITH_NESTED_SUBSTEPS`, `toolIcon` (pi-chart-pie/pi-pencil), `buildArgsLabel` (name/primary_model/chart_type), `extractToolResultDetail` (widget_id).
- Локали `thinkingTimeline.tools.create_widget/update_widget`, `args.createWidgetArgs/updateWidgetArgs`, `details.widgetCreated/widgetUpdated` (ru+en).

### Mini-chat scope=dashboard
- `stores/dashboardContext.ts` (новое) — зеркало `reportContext`: snapshot `{dashboardId, title, widgets[]}` (slim: id/name/primaryModel/chartType). Хелперы `slimChartType`/`slimPrimaryModel`.
- `components/chat/composables/useMiniChat.ts`: `MiniChatScope` += `dashboard` + `dashboardId`; `currentScope` читает `dashboardContextStore`; `dashboard_id` проброшен в `resume`/`fetchChatsScoped`/`sendInline`; scope-change watcher сравнивает и `dashboardId`. Backend резолвит виджет-контекст по `dashboard_id` (синтетический payload не шлём — контракт только про id).
- `components/chat/MiniChatWidget.vue`: контекст-бейдж/placeholder/empty учитывают dashboard-scope (report имеет приоритет). Expand-кнопка ↗ остаётся `/ai-chat` (mini = quick_qa). Локали `miniChat.*OnDashboard` + `dashboardContextHint` (ru+en).

### Страница /dashboards (зеркало ReportsPage)
- `pages/DashboardsPage/` — `index.vue` + composables (`useDashboardsPage` orchestrator, `useDashboardsPageData`, `useDashboardsPageActions`) + `index.ts` + локали.
- Collapse-секции System/Published/Personal (`components/DashboardSection.vue` + `DashboardCard.vue` + локали).
- "+ Новый дашборд" → `POST /api/dashboards` → открыть. `canManageDashboards` гейтит.

### Страница /dashboards/:id (структура как ReportPage)
- `pages/DashboardPage/` — `index.vue` + composables:
  - `useDashboardPageData` — load dashboard + data, period (default текущий месяц), `watch(id, immediate)` с guard на id<=0, period-watch рефетчит только data.
  - `useDashboardLayout` — мост pivot↔grid-layout-plus, persist `PUT /layout` (debounce 600ms, setTimeout как в useReportPreferences), `static` по editable, flush на onScopeDispose, `setVisibility`.
  - `useDashboardPageActions` — `isEditable` (layout-cap && !isSystem), clone, openWidgetGeneration, editWidget (fetch full widget → chatId → modal), detachWidget.
  - `useDashboardPage` — orchestrator: rebuild layout on load, library-modal state, attachWidget, toggleVisibility, watch `widgetUpdatedTick` → reloadAll, `watchEffect` → dashboardContextStore (mini-chat), clear на unmount.
- Grid через `grid-layout-plus` `<GridLayout :layout #item>` (CSS self-injected, ручной импорт не нужен). `@layout-updated` → debounced persist. `toId()` коэрсит `string|number` i из слота.
- `components/PeriodPicker.vue` (PrimeVue DatePicker view=month → YYYY-MM), `WidgetChartCard.vue` (PrimeVue `<Chart>` chart.js/auto, three-dots Menu: edit/visibility/detach), `WidgetLibraryModal.vue` + `WidgetLibrarySection.vue` (collapse System/Published/Personal, фильтрует уже прикреплённые, "+ Создать виджет").
- Системный дашборд read-only → "Сделать своим" (clone). Локали ru+en.

### Навигация + capabilities
- `router/routes/base.ts`: `/dashboards` (Dashboards) + `/dashboards/:id` (DashboardDetail), requiresAuth+companyScope.
- `components/Toolbox/Toolbox.vue`: nav-item "Дашборды" (pi-th-large) перед "Отчёты". Локали `dashboards` (ru+en).
- `shared/auth/capabilities.ts`: `canManageDashboards`, `canManageWidgets`, `canPublishDashboard`, `canPublishWidget`, `canUseDashboardMiniChat`, `canDeleteDashboard`, `canDeleteWidget`. `canManageDashboardLayout` (orphaned после фазы 2) переиспользован для drag-прав дашборда; docblock обновлён.
- `locales/{en,ru}.json`: добавлен `common.success`.
- `DefaultLayout/index.vue`: `<WidgetGenerationModal>` mounted рядом с `<ReportGenerationModal>`.

### Не сделано / открытые вопросы (для фаз 6-7)
- MSW-моки (`mocks/data.ts`/`handlers.ts`) для widgets/dashboards НЕ добавлены — MSW opt-in (`VITE_MOCK_API=true`, `onUnhandledRequest: bypass`), реальный backend готов. Если нужны dev-моки без бэка — добавить отдельно.
- Удаление виджет-сущности (не detach) и publish/unpublish виджета/дашборда — capabilities заведены, но UI для них на странице дашборда не сделан (detach-only по ТЗ); библиотека показывает used_in_dashboards_count, но edit/delete/publish из библиотеки — кандидат на отдельную итерацию (или фаза 6).
- Контракт сошёлся с backend. Возможные точки сверки в QA: (1) форма `name` jsonb {ru,en} при createDashboard (отправляю объект); (2) widget chat_id в `WidgetDto` (использую для resume в editWidget); (3) `GET /dashboards/:id/data` keyed by string widget-id → мапплю в number.

### Проверки
- `npm run type-check` (vue-tsc -p tsconfig.app.json) — clean.
- `npm run lint` (eslint) — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.
- i18n RU↔EN симметрия по всем тронутым локалям — проверено скриптом, расхождений нет.

---

## QA-фикс Dashboards/Widgets — Баг #2: mini-chat overlay align TypeError (2026-05-24)

Симптом (QA): на `/dashboards/:id` при открытии mini-chat overlay из свёрнутого Toolbox — `TypeError: Cannot read properties of null (reading 'offsetHeight')` в PrimeVue `alignOverlay` (визуально overlay открывался корректно, ошибка только в консоли).

### Root cause
Toolbox перехватывает click-событие триггера mini-chat (`handleOverlayToggle('miniChat', $event)` → `overlayTriggerEvent.value[name] = event`) и **реплеит его асинхронно** через `watch(openOverlay)` flush → `syncPopover(true, storedEvent)` → `popoverRef.toggle(storedEvent)`. PrimeVue `Popover.show(event)` делает `this.target = target || event.currentTarget`. Но `event.currentTarget` валиден только во время dispatch — после возврата хэндлера браузер сбрасывает его в `null`. К моменту, когда `show()` исполняется (на следующем flush, не синхронно), `currentTarget === null`, значит `this.target = null`, и `alignOverlay()` → `absolutePosition(container, null)` / `getOffset(null)` дереференсит `null.offsetHeight` → TypeError.

Не уникально для дашбордов архитектурно — `ProfileMenu` / `CompanySwitcher` используют тот же `toggle(event)` без явного target (латентный тот же риск), но mini-chat триггерит баг стабильно из-за лишнего async-хопа (`void mini.initializeOnOpen()` в `handleShow` + гидрация `dashboardContextStore`).

### Фикс — `front/src/components/chat/MiniChatWidget.vue`
- `ref="triggerRef"` на trigger `<Button>` (строка ~3-4 шаблона).
- `resolveAnchorEl()` — резолвит `triggerRef.value?.$el`, возвращает `HTMLElement` или `null` (guard: не `HTMLElement` или `offsetHeight === 0`).
- `togglePopover(event)` — теперь передаёт явный resolved anchor вторым аргументом `toggle(event, anchor)` (PrimeVue использует `target` приоритетно, обходя протухший `event.currentTarget`). Если anchor не резолвится сразу (collapsed панель в середине transition с нулевым box) — `nextTick` retry; если и тогда нет layout-box — пропускаем открытие, а не падаем.
- `realign()` — добавлен guard `if (!resolveAnchorEl()) return` перед `alignOverlay()` (защита от re-align по torn-down/zero-box anchor, напр. Toolbox свернули при открытом overlay).
- Импорт `nextTick` + тип `ComponentPublicInstance`.

`opacity:0` (collapsed-панель) НЕ обнуляет `offsetHeight` (layout сохраняется), поэтому при штатном свёрнутом Toolbox anchor резолвится корректно; `offsetHeight===0` срабатывает только при `display:none`/unmount — это безопасный skip-путь.

Не задето: mini-chat на `/reports`, `/companies`, general/report/dashboard scope — путь `togglePopover` единый, на всех страницах теперь передаётся стабильный anchor (тот же DOM-элемент, что был бы `event.currentTarget` при синхронном клике). `ProfileMenu`/`CompanySwitcher` не трогал (латентный риск зафиксирован как находка, вне scope бага #2).

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.

---

## 2026-05-24 — UX Dashboards/Widgets: live мини-чарты в библиотеке + чипы-пресеты в модалке генерации

**Задача:** доработка UX по эталону capitaldata. (1) карточки библиотеки виджетов — live мини-чарты вместо текстовых строк; (2) модалка генерации виджета — статичные чипы-пресеты + greeting в preview-state. Backend готов (`GET /api/widgets/{id}/data` уже отдаёт labels/datasets).

### Задача 1 — Live мини-чарты в библиотеке виджетов

**Новый composable — `front/src/pages/DashboardPage/composables/useWidgetPreviewData.ts`**
- Per-widget кэш `GET /api/widgets/{id}/data` на время жизни одного открытия модалки. `ensure(id)` дедуплицирует in-flight promise (Map), no-op после `loaded`. `statusOf(id)` / `dataOf(id)` — реактивные refs (`idle|loading|loaded|error`). `clear()` сбрасывает кэш при закрытии модалки (повторное открытие → свежие числа, но в рамках одного открытия один и тот же виджет рендерится без повторного запроса). Без `period` (текущий месяц/дефолт backend'а). Ошибка проглатывается — карточка падает в текстовый fallback, добавление виджета никогда не блокируется.

**Новый компонент — `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue`**
- Компактная карточка (~120px высота чарта) с мини-чартом через PrimeVue `<Chart>`. Тип чарта из `item.chartType` (резолв из `config.chart.type`).
- Sparkline-стиль: `legend.display=false`, `tooltip.enabled=false`, оси скрыты для bar/line; для pie/doughnut — без осей, со слайсами. Локальная палитра из 8 цветов (pie-слайсы / bars / line-stroke различимы на малом размере; line — `fill:false`, `pointRadius:0`, `tension:0.35`).
- Состояния: loading/idle → спиннер; error/!hasData → иконка типа чарта (`pi-chart-bar|line|pie`) как fallback. Lazy: `@vue:mounted` → `emit('ensure', id)`.
- Клик/Enter/Space → `pick(id)` (как раньше). `usedInDashboards` count перенесён сюда из секции.

**`front/src/pages/DashboardPage/components/WidgetLibrarySection.vue`**
- `<ul>`-список текстовых `<li>` заменён на CSS-grid (`repeat(auto-fill, minmax(180px, 1fr))`) из `WidgetPreviewCard`. Принимает prop `preview: WidgetPreviewDataApi`, прокидывает `statusOf/dataOf/ensure` в карточки. Удалён неиспользуемый `useLocalI18n`/`t` и стили `.widget-lib-item`.

**`front/src/pages/DashboardPage/components/WidgetLibraryModal.vue`**
- Создаёт `preview = useWidgetPreviewData()`, прокидывает в 3 секции. `LocalizedWidgetItem` расширен полем `chartType: WidgetChartType` (резолв через whitelist `['bar','line','pie','doughnut']`, fallback `'bar'`). `@hide` → `preview.clear()`. Публичные props/emits модалки не тронуты (dashboard `index.vue` не правился).

### Задача 2 — Чипы-пресеты в модалке генерации виджета

**Новый компонент — `front/src/components/chat/WidgetPromptPresets.vue`**
- Greeting-текст + 5 чипов (PrimeVue `Button` `outlined size="small"`, `border-radius:999px`). Клик → `emit('pick', phrase)` (полностью резолвленная фраза). Список ключей пресетов (`PRESET_KEYS`) пинится в компоненте, **фразы — в локали** `widgetGenerationModal.presets.items.*` (translatable).

**`front/src/components/chat/WidgetGenerationModal.vue`**
- В preview-state create-mode (до первого сообщения) показывает `WidgetPromptPresets` вместо bare `EmptyState`. `showPresets` = `mode==='create' && (isPreview||!currentChat) && messages.length===0`. Edit-mode оставляет прежний hint (пресеты — creation-фразы). Клик по чипу → `applyPreset(phrase)` → `inputValue.value = phrase` (НЕ авто-отправка; согласуется с lazy-create — чат не создаётся до отправки). После первого сообщения чипы скрыты.

**Локали — `front/src/components/chat/locale/{ru,en}.json`**
- `widgetGenerationModal.presets.greeting` + `widgetGenerationModal.presets.items.{dealsByManager, salesAmountByComplex, dealsByStatus, salesDynamics, paymentsByObject}`. Доменные фразы (застройщик/недвижимость): «Сделки по менеджерам за месяц», «Сумма сделок по ЖК», «Сделки по статусам», «Динамика продаж по месяцам», «Платежи по объектам». RU↔EN симметричны.

### Опц «объяснить в чате» иконка на виджет-карточке дашборда
- **Не сделано** — требует отдельной проводки (привязка виджета к чат-промпту с инжектом контекста), вне «быстро и в тему». Отмечено как backlog.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.

### Риски
- Мини-чарты рендерят то, что отдаёт `/data` сейчас (ID в labels). После backend-фикса relation-join (id→имя) код не меняется — рендерит новые labels автоматически.
- N карточек = до N параллельных `/data` запросов при открытии секций (lazy на mount, дедуп по widget_id). При большой библиотеке — всплеск запросов, но каждый кэшируется. Если станет проблемой — добавить IntersectionObserver (только видимые), backlog.
- Палитра в `WidgetPreviewCard` локальная (hardcoded hex), не из theme-токенов — осознанный trade-off ради независимости от runtime-темы на малых превью. Дашборд-карточки (`WidgetChartCard`) по-прежнему используют дефолты Chart.js (не тронуты).

---

## 2026-05-24 — Микро-фикс code review: console.error в DashboardsPage data composable

### Фикс — `front/src/pages/DashboardsPage/composables/useDashboardsPageData.ts:36`
- Удалён `console.error('Failed to fetch dashboards', error)` из catch-блока `fetchDashboards` (несоответствие стандарту «no console.log/console.error в production-коде»).
- Тихая обработка уже была на месте — `notifyApiError(error, t('errors.loadFailed'))` остаётся единственным эффектом catch. Это совпадает с эталонным паттерном sibling-композабла `useCompanyPageData.ts:59-61` (catch → только `notifyApiError`, без console).
- Загрузка дашбордов не задета: useScopedResource.sync / error-flow без изменений, ошибка по-прежнему доходит до пользователя через toast.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.

---

## 2026-05-24 — QA-фикс (non-blocking): Chart.js race при unmount превью-карточек библиотеки виджетов

### Симптом
В библиотеке виджетов при добавлении виджета (карточка уходит из списка, секции перерисовываются) консоль выдавала `Failed to create chart: can't acquire context from the given item` — обычно 3x.

### Root cause
PrimeVue `<Chart>` в `mounted` дёргает асинхронный `import('chart.js/auto')`, и инстанцирует Chart.js уже в `.then()`-колбэке. Превью-карточки в библиотеке быстро монтируются/размонтируются (выбранный виджет удаляется из `v-for` по `item.id`). Если promise динамического импорта резолвится ПОСЛЕ unmount карточки, Chart.js пытается получить 2D-context с уже отсоединённого `<canvas>` → warning. Собственный `beforeUnmount`-destroy у PrimeVue к этому моменту уже отработал и не видел поздний инстанс (он создаётся внутри late-promise). Это не raw Chart.js инстанс у нас — это lifecycle-race внутри PrimeVue Chart. `WidgetChartCard.vue` (дашборд) тем же способом использует `<Chart>`, но не страдает, потому что не churn'ится так быстро.

### Фикс — `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue`
- Добавлен module-level preload `chart.js/auto` (`ensureChartModule()`, общий промис на все карточки). `<Chart>` рендерится (`v-else-if="chartReady"`) только ПОСЛЕ резолва. После первого preload `import()` резолвится синхронно из кэша модулей — карточка больше не может размонтироваться между mount и инстанцированием чарта.
- Guard `isAlive` (флипается в `onBeforeUnmount`): если карточка размонтировалась пока самый первый preload ещё в полёте, `chartReady` не выставляется и `<Chart>` не появляется в DOM.
- До готовности модуля показывается spinner (тот же state, что и `loading`), рендер чартов при открытии библиотеки не нарушен.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.

---

## 2026-05-24 — Виджет-чарты переведены с Chart.js на ECharts (vue-echarts, тема vizion)

### Контекст
Виджет-карточки на дашборде и мини-превью в библиотеке ошибочно были на Chart.js (PrimeVue `<Chart>`). Проектный стандарт — ECharts (`vue-echarts` + глобальная тема `vizion` из `front/src/plugins/echarts.ts`). Старый dashboard-on-report (выпилен в 313ab24) уже был на ECharts — взят как эталон option-маппинга.

### Что найдено grep (`primevue/chart` / `chart.js` / `<Chart`)
- `WidgetChartCard.vue` (дашборд) — реальное использование `<Chart>` от Chart.js.
- `WidgetPreviewCard.vue` (мини-превью библиотеки) — реальное использование `<Chart>` + ручной preload `chart.js/auto`.
- `api/widgets.ts`, `api/dashboards.ts`, `api/types/widgets.ts` — только doc-комментарии («Chart.js-shaped data»), не код. Формулировки в `api/types/widgets.ts` поправлены на ECharts.

### `WidgetChartCard.vue` (дашборд) — Chart.js → ECharts
- `<Chart :type :data :options>` → `<VChart theme="vizion" :option autoresize>`.
- Импорты `primevue/chart` убраны; добавлены `VChart` (vue-echarts), `graphic` (echarts/core), тип `EChartsOption`, `VIZION_ECHARTS_PALETTE` (из плагина).
- `chartData` + `chartOptions` (Chart.js shape) заменены одним `chartOption: EChartsOption` builder из бэкендового `{labels, datasets}`:
  - bar: xAxis category=labels, серия на каждый dataset. Single-series — вертикальный градиент (`graphic.LinearGradient`, как в старом виджете) + `borderRadius [6,6,0,0]`; multi-series — плоские цвета палитры + легенда снизу.
  - line: xAxis category=labels, серия на dataset (smooth). Single-series — area-градиент; multi-series — без area + легенда.
  - pie/doughnut: `series type=pie`, `data=[{name,value}]` из `datasets[0]`; doughnut = `radius 55%/75%`, pie = `0%/70%`. Цвет слайса по индексу палитры.
  - Цвета — `VIZION_ECHARTS_PALETTE` через `colorAt(i)`, не хардкод hex.

### `WidgetPreviewCard.vue` (мини-превью) — Chart.js → ECharts
- `<Chart>` → `<VChart theme="vizion" :option autoresize>`.
- Убран весь Chart.js lifecycle-хак: module-level `ensureChartModule()`/`chartModulePromise`, `chartReady`, `isAlive`, `onBeforeUnmount`. vue-echarts сам диспозит инстанс на unmount — detached-canvas race больше нет. Закрывает прежний баг «can't acquire context», зафиксированный выше в этом же файле.
- `chartData` + `chartOptions` заменены `chartOption: EChartsOption` — компактный sparkline: `animation:false`, оси/легенда/тултип скрыты, `silent:true`. bar — узкие бары (`barMaxWidth 14`), line — smooth + лёгкий area (`opacity 0.12`), pie/doughnut — слайсы без подписей. Палитра из плагина.
- Локальная `PALETTE` (хардкод hex) удалена.
- Сохранены: lazy-load данных (`emit('ensure')` в onMounted), loading/idle/error/fallback состояния, кэш через `useWidgetPreviewData` (вне компонента — не тронут).

### Chart.js выпилен из package.json
- `chart.js` НЕ peer-dependency PrimeVue (грузится динамически, peer не объявлен) и больше нигде в node_modules/src не используется — был прямой зависимостью только под эти виджеты.
- Удалён из `front/package.json`. `npm install` снёс 2 пакета (`chart.js` + транзитивный `@kurkle/color`), lockfile синхронизирован.

### Проверки
- grep `primevue/chart`/`chart.js`/`<Chart` по `src/` — в коде пусто (остались только корректные упоминания ECharts в комментариях).
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning.

### Файлы
- `front/src/pages/DashboardPage/components/WidgetChartCard.vue`
- `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue`
- `front/src/api/types/widgets.ts` (doc-комментарии)
- `front/package.json` + `front/package-lock.json`

### Риски
- Контракт `/widgets/{id}/data` (`{labels, datasets, meta}`) не менялся — только рендер. relation-имена в labels рисуются как раньше.
- Multi-dataset bar/line: бэкенд обычно отдаёт 1 dataset на виджет; multi-series ветка — defensive (легенда + плоские цвета).
- Визуальная проверка (qa-tester) желательна: bar/line/pie/doughnut на дашборде + мини-превью в библиотеке, тема vizion.

---

## 2026-05-24 — Домашняя страница пользователя (звезда-избранное + редирект на корне)

### Задача
Пользователь помечает текущую страницу как «домашнюю» (одно значение, перезапись, дефолт `/reports`, все роли включая viewer). Глобальная звезда-кнопка в шапке + редирект на home при заходе на корень `/`. Backend готов (см. контракт ниже).

### Backend-контракт (готов, фронт только потребляет)
- `home_path` в user-ответе: `POST /api/login`, `/api/iframe-auth` (поле `user.home_path`), `GET /api/user` (top-level `home_path`). Всегда строка, дефолт `/reports`.
- `PUT /api/profile/home` body `{path}` → 200 `{home_path}`. 422 на absolute URL / `//`.

### Типы и entity
- `front/src/api/types/users.ts` — `UserDto.home_path: string` (обязательное), новые `SetHomePathRequest {path}`, `SetHomePathResponse {home_path}`.
- `front/src/api/types/index.ts` — реэкспорт `SetHomePathRequest`/`SetHomePathResponse`.
- `front/src/entities/user/types.ts` — `User.homePath: string` (camel), константа `DEFAULT_HOME_PATH = '/reports'`.
- `front/src/entities/user/mappers.ts` — `mapUserDtoToUser` мапит `home_path` → `homePath` через новый `normalizeHomePath()` (trim + falsy-fallback на дефолт). Спред `...userDto` оставляет лишний `home_path` в объекте (TS не флагует excess при спреде), это безвредно.
- `front/src/entities/user/index.ts` — реэкспорт `normalizeHomePath`, `DEFAULT_HOME_PATH`.

### Store
- `front/src/stores/user.ts` — getter `getHomePath` (фолбэк на `DEFAULT_HOME_PATH`), action `setHomePath(path)` (иммутабельно патчит `currentUser`). `currentUser` не персистится (persist только `token`) — home живёт в рамках сессии, источник истины = backend.

### API / service / coordinator (side-effect через `application/`)
- `front/src/api/profile.ts` (новый) — `profileApi.setHomePath({path})` → `PUT /api/profile/home`.
- `front/src/api/index.ts` — реэкспорт `profileApi` + типов.
- `front/src/services/UserService.ts` — метод `setHomePath(path): Promise<string>` (нормализует ответ). Положен в UserService рядом с `updateCurrentUserLocale` — не плодил отдельный ProfileService.
- `front/src/application/session/userSessionService.ts` — `setHomePath(path)` в интерфейсе + реализации: вызывает сервис, затем `userStore.setHomePath()`. Компонент дёргает coordinator, не axios/сервис напрямую.

### Звезда-кнопка (глобальная, в шапке = Toolbox)
- `front/src/components/HomeStar/{HomeStar.vue, index.ts, locale/{ru,en}.json}` (новый домен-компонент).
  - Размещён в слоте `#actions` `Toolbox.vue` первым (перед MiniChat/CompanySwitcher/ProfileMenu). Toolbox = плавающая шапка, видна на всех контентных страницах, скрыта на `/login` (там layout не рендерит Toolbox). Видна всем ролям (без capability-гейта — спека: «все роли вкл viewer»).
  - filled `pi pi-star-fill` жёлтая (`--p-yellow-400`) при `getHomePath === route.path`, иначе outline `pi pi-star`. Tooltip filled/outline через i18n. `aria-pressed`.
  - Клик когда outline → `userSessionService.setHomePath(route.path)` → success/error toast. Клик когда уже home → no-op (одна домашняя, не снимаем). `:loading` на время PUT.
  - **path vs fullPath**: сравнение и сохранение по `route.path` (без query) — `/reports?x=1` и `/reports` = одна домашняя.

### Router guard — редирект на корне `/`
- `front/src/router/routes/base.ts` — статический `{path:'/', redirect:'/reports'}` заменён на `{path:'/', name:'Root', component: ReportsPage (fallback), meta:{requiresAuth:true}}`. **Почему не static redirect**: route-level `redirect` применяется при резолве ДО `beforeEach`, и guard никогда не увидел бы `/`, не смог бы прочитать homePath. Component — никогда не рендерится (guard всегда редиректит).
- `front/src/router/policy.ts` — в `resolveNavigation` добавлена ветка: `to.path === '/' && isAuthenticated` → `user.homePath || DEFAULT_HOME_PATH` (если home сам `/` → дефолт, защита от петли; если user не готов → дефолт). Только точный `/` — deep-links (`/reports/42`) не трогаются. Неавторизованный на `/` → login (через `requiresAuth`).
- `front/src/pages/LoginPage/composables/useLoginPage.ts` — после логина редирект `redirect ?? '/'` (было `getDefaultRoute(role)`). Теперь единый источник истины — root-handler резолвит `/` → homePath. Deep-link `?redirect=` по-прежнему уважается. Убран неиспользуемый импорт `getDefaultRoute`.

### Mocks (MSW)
- `front/src/mocks/data.ts` — `mockCurrentUser.home_path = '/reports'` (обязательное поле UserDto).
- `front/src/mocks/handlers.ts` — `PUT /api/profile/home` (валидация relative path, 422 на absolute/`//`, мутирует mockCurrentUser).
- `front/src/types/msw.d.ts` — в shim `http` добавлен `put` (отсутствовал).

### Actions-меню (опц. дубль) — НЕ делал
Глобальная звезда в шапке покрывает функционал на всех страницах вкл. `/reports/:id`. Дубль пункта в `ReportActionsMenu.vue` пропущен (опционален по спеке, не трогал report-page композаблы).

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean, кроме pre-existing `persist.ts:91` comma-dangle warning (не регрессия).
- i18n RU↔EN симметрия: `homeStar.{isHome,makeHome,saved,saveError}` в обоих файлах.

### Риски
- Login теперь редиректит на `/` вместо `getDefaultRoute(role)` → root-handler. superadmin/admin раньше после логина попадали на `/company` (их `getDefaultRoute`); теперь — на их `homePath` (дефолт `/reports`, если не задан). Это намеренно (home > роль-дефолт), но меняет привычный landing для админов с дефолтным home.
- Звезда видна всем ролям без capability — соответствует спеке, но visual QA стоит проверить на viewer.
- Toolbox перетаскиваемый/сворачиваемый — звезда в слоте `#actions` наследует это поведение (скрывается при collapse, как другие actions). Ожидаемо.
- Проверить визуально: filled/outline переключение при навигации, клик ставит home (PUT + toast), `/` редиректит на home, `/reports/42` из закладки открывается как есть.

## 2026-05-24 — Форматирование чисел/дат/серий в виджет-чартах (ECharts)

### Новый util
- `front/src/utils/chartFormatters.ts` (новый) — Vue-агностичные форматтеры для виджет-чартов. Callers передают resolved `locale` (+ `currency`):
  - `abbreviateNumber(value, locale)` — компактная ось: 210000000 → «210 млн», 47622368177 → «47,6 млрд», 50000000000 → «50 млрд». Суффиксы тыс/млн/млрд/трлн (ru) или K/M/B/T (en). Ниже 10 000 — точное число с разрядами без суффикса. Одна десятичная только если ненулевая.
  - `formatFullNumber(value, locale, currency?)` — полное число с разделителями разрядов (для tooltip), + narrow-символ валюты суффиксом если деньги.
  - `formatAxisValue(value, locale, currency?)` — abbreviateNumber + символ валюты (yAxis).
  - `isMonetaryWidget(config)` — эвристика «деньги vs count» (см. ниже).
  - `formatTemporalLabel(label, locale)` — «YYYY-MM» → «май 2026»/«May 2026»; ISO дата/timestamp → «4 мая»/«May 4»; иначе строка как есть.
  - `resolveSeriesLabel(rawLabel, config, locale)` — total/value/sum → «Сумма»/«Total», cnt/count → «Количество»/«Count», приоритет у `config.chart.label`.

### WidgetChartCard (дашборд, полный чарт)
- `front/src/pages/DashboardPage/components/WidgetChartCard.vue` — новый prop `config?: WidgetConfigDto | null`. Резолвит locale (из useLocalI18n) + currency (active-company `currency_code` через useCompaniesStore, fallback RUB, narrowSymbol — как useFormatter), только если виджет монетарный.
  - yAxis `axisLabel.formatter` → `formatAxisValue` (bar/line).
  - xAxis `axisLabel.formatter` → `formatTemporalLabel` (bar/line); pie slice-имена тоже через temporal-формат.
  - tooltip `valueFormatter` → `formatFullNumber` (bar/line/pie).
  - series `name` → `resolveSeriesLabel`.

### DashboardPage index
- `front/src/pages/DashboardPage/index.vue` — добавлен accessor `widgetConfig(id)` и проброс `:config` в WidgetChartCard; импорт `WidgetConfigDto`.

### WidgetPreviewCard (мини-превью)
- НЕ трогал. Превью рендерится `silent: true`, оси `show: false`, без tooltip/legend/pie-label — текст нигде не выводится, форматтеры были бы dead code. Решено оставить как есть (по ТЗ «в превью минимум, не ломать»).

### Как детектится денежный виджет (isMonetaryWidget)
1. Находим aggregate, чей `as` == `chart.value_field` (серия, которую реально рисует чарт); если один aggregate — берём его.
2. `fn == 'count'` → не деньги (целое). `fn ∈ {sum,avg,min,max}` И `field` совпадает с money-паттерном (sum/summa/price/amount/revenue/cost/payment/paid/debt/balance + ru: выручк/сумм/цен/деньг/оплат/долг) → деньги.
3. Fallback: если value_field не резолвится в aggregate — сниффим само имя alias'а (cnt/count → не деньги; money-паттерн → деньги).
Консервативно: нераспознанный виджет = обычное число (сокращения, без валюты) — символ валюты не ставится ошибочно.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle).

### Риски / эвристика
- Детект «деньги» — эвристика по имени поля + fn. Виджет с монетарным полем без распознаваемого имени (нестандартный alias) покажется как обычное число (без ₸) — безопасный fallback, не ломает рендер.
- `count`-виджет с полем-алиасом, случайно содержащим money-паттерн, в fn=count всё равно НЕ деньги (fn проверяется первым).
- Валюта берётся из active-company `currency_code` (как у таблиц через useFormatter), не хардкод ₸. Реактивна на смену компании.
- Temporal-детект — try-parse по regex (YYYY-MM / ISO). Категория вида «2024» (только год) или «2026/05» НЕ распознаётся как период — пройдёт как строка. Имена вида ЖК/менеджер не задеваются.
- ru ISO-дата строится вручную (день + genitive-месяц) чтобы избежать «4 мая.» с точкой в части движков.

---

## 2026-05-24 — Дашборд: диапазонный (from-to) period picker вместо одного месяца

Задача: temporal-виджеты (динамика по месяцам) требуют диапазон, а не один месяц. Backend готов: `?period_from=YYYY-MM&period_to=YYYY-MM` (`?period=` = один месяц, обратная совместимость), meta теперь `period_from`/`period_to`.

### API-слой (типы + клиент)
- `front/src/api/types/dashboards.ts` — `DashboardDataDto.meta` теперь `{ period_from: string|null, period_to: string|null }` (было `{ period }`). Добавлен тип `PeriodRange { from: string; to: string }` (inclusive `YYYY-MM`, single source of truth для диапазона).
- `front/src/api/dashboards.ts` — `fetchDashboardData(id, range?: PeriodRange)` шлёт `params: { period_from, period_to }` (было `{ period }`).
- `front/src/api/types/widgets.ts` — `WidgetDataDto.meta` тоже переведён на `period_from`/`period_to` (бэк изменил контракт для обоих endpoint'ов).
- `front/src/api/widgets.ts` — `fetchWidgetData(id, range?: PeriodRange)` шлёт `period_from`/`period_to`. (Превью библиотеки range не передаёт — preview-карточки вне глобального фильтра, не задето.)

### Сервисы
- `front/src/services/DashboardService.ts` — `fetchDashboardData(id, range?: PeriodRange)`.
- `front/src/services/WidgetService.ts` — `fetchWidgetData(id, range?: PeriodRange)`.

### Entities (camelCase mirrors)
- `front/src/entities/dashboard/types.ts` + `mappers.ts` — `DashboardData.period` → `periodFrom`/`periodTo` (маппится из `meta.period_from`/`meta.period_to`).
- `front/src/entities/widget/types.ts` + `mappers.ts` — `WidgetData.period` → `periodFrom`/`periodTo`. (Поля нигде не читаются во вьюхах — dead fields на стороне виджета, но синхронизированы с контрактом.)

### PeriodPicker (переделан в диапазон)
- `front/src/pages/DashboardPage/components/PeriodPicker.vue` — `modelValue` теперь `PeriodRange` (было строка `YYYY-MM`).
  - PrimeVue `DatePicker` `selection-mode="range"` `view="month"` — выбор от/до одним полем (`[Date, Date]` tuple ↔ `{from,to}`). Эмит только когда обе границы выбраны (mid-selection с одной границей игнорируется), нормализация порядка (from ≤ to).
  - Ряд пресет-чипов (PrimeVue Button `rounded` `size="small"`): «12 мес», «Этот год», «3 мес», «Текущий месяц». Активный пресет подсвечивается (не-outlined), детектится через `matchPreset()`.
  - Локальный i18n рядом с компонентом (`components/locale/{ru,en}.json`): ключи `rangePlaceholder`, `presets.{last12,thisYear,last3,currentMonth}` — RU↔EN симметрично.

### Месяц-утилиты (pure, переиспользуются picker + data-composable)
- `front/src/pages/DashboardPage/composables/periodRange.ts` (новый) — `toMonthKey`, `monthKeyToDate`, `defaultRange` (последние 12 мес, текущий включительно — = backend temporal default), `normaliseRange`, `rangesEqual`, `presetRange`, `matchPreset`.

### Data-composable
- `front/src/pages/DashboardPage/composables/useDashboardPageData.ts`:
  - `period` ref теперь `PeriodRange` (дефолт = `defaultRange()` = последние 12 мес, либо из URL).
  - `loadData()` шлёт `period.value` (range) в `fetchDashboardData`.
  - Перефетч при смене диапазона — **debounce 350ms** (drag по нескольким месяцам = один запрос), guard `rangesEqual` (нет лишних запросов на эквивалентный диапазон).
  - URL-state: `initialRange(route.query)` читает `?period_from=&period_to=`; **legacy `?period=YYYY-MM` поддержан** (= одномесячный диапазон). На смену диапазона — `router.replace` с `period_from`/`period_to`, legacy `period` вычищается. Дефолтный диапазон в URL не пишется до первого изменения (lazy).

### index.vue
- `front/src/pages/DashboardPage/index.vue` — НЕ менялся по разметке: `<PeriodPicker v-model="period" />` теперь передаёт `PeriodRange` (тип совпал, type-check clean).

### UX-решения
- Range-mode (один DatePicker `selectionMode="range"`) вместо двух отдельных селекторов — компактнее в шапке, нативный month-grid PrimeVue, понятный диапазон.
- Пресеты-чипы слева от поля для быстрого выбора частых диапазонов; активный подсвечен.
- Дефолт = **последние 12 месяцев** (from = −11 мес, to = текущий) — согласован с backend temporal default, не одиночный месяц.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle).

### Риски
- `WidgetData.period`/`DashboardData.period` переименованы в `periodFrom`/`periodTo` — нигде во вьюхах не читались (grep подтвердил), риск минимальный; ломается только при наличии внешнего кода, который их читал бы по старому имени.
- DatePicker range эмитит `[from, null]` на первом клике — обработчик ждёт обе границы, поэтому при выборе пользователь делает 2 клика (от и до); запрос уходит только после второго. Ожидаемое поведение range-режима.
- Превью-карточки библиотеки (`useWidgetPreviewData`) range не передают — показывают данные за backend-дефолт (last 12 для temporal). Это вне глобального фильтра дашборда, по ТЗ корректно.
- Snapshot-виджеты (без period_field, напр. «Продажи по ЖК») игнорируют диапазон — это backend-сторона, фронт просто всегда шлёт range.
- Legacy `?period=` поддержан только на initial-load (конвертится в одномесячный диапазон); после первой смены диапазона URL переходит на `period_from`/`period_to`.

---

## 2026-05-24 — CSS-фикс: пробел между date-значением и overdue-badge в таблице отчёта

**Задача (mass QA):** в таблице отчёта в date-ячейке с badge просрочки бейдж «Nд» прилипал к дате без пробела — «31.12.2023874д» вместо «31.12.2023 874д».

**Причина:** `.badge-cell` (inline-flex контейнер ячейки) полагался только на `gap: 0.35rem` для отступа между значением и `<Badge>`. PrimeVue Badge сбрасывает margin'ы, и flex-`gap` на практике не держался — бейдж рендерился вплотную к дате.

**Изменение:**

### index.vue
- `front/src/pages/ReportPage/index.vue` (scoped `<style>`, блок `:deep(.badge-cell)`, ~строка 903):
  - убрал `gap: 0.35rem` с контейнера `.badge-cell`;
  - добавил явный `:deep(.badge-cell__badge) { margin-left: 0.35rem; }` — гарантированный отступ на самом бейдже, не зависит от honored-flex-gap.
  - Разметка не менялась — только SCSS.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle).

### Риски
- Минимальные: затронут только `.badge-cell__badge` (рендерится исключительно в date/badge-колонках, где `col.badge` truthy). Другие ячейки (link, payment_schedule, truncate, обычные) не используют этот класс.
- Заменили `gap` на `margin-left` той же величины (0.35rem ≈ 5.6px) — итоговый визуальный отступ не изменился, только механизм стал надёжнее.

---

## 2026-05-24 — Двухшаговый flow генерации виджета: варианты с превью + выбор

**Задача:** В модалке генерации виджета AI вместо мгновенного создания предлагает 2–4 варианта виджета (SSE-событие `widget_variants`). Фронт рендерит карточку на каждый вариант с live ECharts-превью; пользователь выбирает один → виджет создаётся через стандартный create-флоу. Backend готов.

**API-слой:**
- `front/src/api/types/widgets.ts` — добавлен `PreviewWidgetRequest` (`{config, period_from?, period_to?}`) для `POST /api/widgets/preview` (данные без сохранения виджета).
- `front/src/api/widgets.ts` — метод `previewWidget(data)` → `POST /api/widgets/preview` (WidgetDataDto).
- `front/src/services/WidgetService.ts` — `previewWidget(config, range?)` → маппинг в `WidgetData`.
- `front/src/api/types/chats.ts` — в `ChatMessageEventType` добавлен `'widget_variants'`; новые типы `WidgetVariantDto` (`{index, label, config}`) и `WidgetVariantsPayload` (`{variants[]}`). Импорт `WidgetConfigDto`.

**Composable:**
- `front/src/components/chat/composables/useWidgetGenerationModalChat.ts`:
  - `parseWidgetVariantsPayload(raw)` — валидирует payload события (оставляет только well-formed варианты);
  - state `variants` (shallowRef) + `isSelectingVariant` (ref);
  - в `onEvent` стрима событие `widget_variants` интерсептится отдельно (не попадает в timeline, т.к. тип не в `TIMELINE_EVENT_TYPES`), заполняет `variants`;
  - `selectVariant(index)` — рекомендованный путь по контракту: шлёт обычное сообщение `«Создай вариант N: {label}»` через `sendMessage` (сохраняет chat↔widget link + timeline), очищает варианты, guard от двойного клика;
  - `sendMessage` очищает `variants` в начале (новый turn перекрывает старые предложения);
  - `reset` сбрасывает оба новых поля. Экспорт `variants`, `isSelectingVariant`, `selectVariant`.

**Компоненты (новые):**
- `front/src/components/chat/WidgetVariantCard.vue` — карточка одного варианта: label-заголовок + мини ECharts-превью (тот же sparkline-рендер, что в `WidgetPreviewCard.vue`: без осей/легенды/анимации, палитра `VIZION_ECHARTS_PALETTE`, тема `vizion`). Превью грузится в `onMounted` через `widgetService.previewWidget(config)` (best-effort: ошибка → fallback-иконка типа чарта, выбор не блокируется). Кнопка «Выбрать этот» + клик/Enter/Space по карточке → emit `pick(index)`. Тип чарта резолвится из `config.chart.type` (default `bar`).
- `front/src/components/chat/WidgetVariantsPanel.vue` — grid карточек (2 кол. desktop, 1 кол. <560px) + hint.

**Модалка:**
- `front/src/components/chat/WidgetGenerationModal.vue` — добавлен рендер `WidgetVariantsPanel` (показывается при `variants.length > 0`, выше message-list); `showVariants` computed; `handleVariantPick` → `chat.selectVariant`. ChatInput остаётся доступным (можно уточнить запрос вместо выбора). SCSS-блок `.widget-gen-modal__variants`.

**i18n:**
- `front/src/components/chat/locale/{ru,en}.json` — блок `widgetGenerationModal.variants` (`hint`, `pick`, `selectMessage` с `{index}`/`{label}`). RU↔EN симметрично.

**Механизм выбора:** через сообщение «Создай вариант N: {label}» (рекомендованный путь) — сохраняет chat↔widget link и timeline. Label добавлен в текст, чтобы AI однозначно сопоставил выбор, даже если config варианта вышел из короткого контекста. После create — стандартный flow: `final_message` → `chat.widget_id` пиннится в `finalizeAssistantMessage`/`syncCreatedWidget` → CTA «Добавить на дашборд»/«Готово».

**Превью:** переиспользован ECharts-рендер из `WidgetPreviewCard.vue` (ECharts тема vizion, НЕ Chart.js). Логика скопирована в `WidgetVariantCard` (отдельный fetch per-config, без widget id — поэтому не переиспользован `useWidgetPreviewData`, который кэширует по widget id).

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle).

### Риски / открытые вопросы
- Текст selectMessage `«Создай вариант N: {label}»` зависит от того, что AI корректно сопоставит запрос с config'ом варианта из истории чата (per backend-рекомендации). Если backend ожидает строго `«Создай вариант N»` без label — label не помешает, но стоит проверить QA happy-path.
- `widget_variants` НЕ в `TIMELINE_EVENT_TYPES` — событие не отображается в thinking-timeline (намеренно); если backend ожидает его и в timeline — потребуется доп. ключи локализации.
- Превью каждого варианта = отдельный `POST /api/widgets/preview` на mount карточки (2–4 запроса параллельно при появлении вариантов). Дедупликации нет (каждый вариант уникален), кэш не нужен.
- ChatInput оставлен доступным при показанных вариантах — пользователь может уточнить запрос вместо выбора (новый send очистит варианты). Если по UX варианты должны блокировать ввод — тривиальный `:disabled`.

## 2026-05-24 — Layout-фикс: обрезанный нижний ряд виджетов на /dashboards/:id (блокер демо)

### Задача
Дашборд с 6 виджетами (3 ряда по 2): нижний ряд обрезан, вертикального скролла нет — клиент видит 4 из 6. Одинаково на 1280px и 1920px.

### Файлы
- `front/src/pages/DashboardPage/index.vue` (только `<style scoped>`): `.dashboard-detail-page`, `.dashboard-card`, `.dashboard-body`.

### Root cause
Цепочка скролла строилась на «внутренней клетке»: `.dashboard-body { flex: 1; min-height: 0; overflow: auto }` внутри `.dashboard-card { flex: 1; overflow: hidden }` внутри `.main { overflow: hidden }` внутри `#app { height: 100vh; overflow: hidden }`. grid-layout-plus через `autoSize` ставит `.vgl-layout` инлайн-высоту по числу строк (`maxRow * (rowHeight + margin) + margin`), т.е. ~1500px+ для 3 рядов — это превышало клетку и нижние ряды клиппились. Изолированный CSS-репро (headless chromium) показал, что внутренний `overflow:auto` теоретически скроллится, но клетка хрупкая: зависит от безупречного контракта `min-height:0` + definite-height у КАЖДОГО предка сквозь `#app{100vh;hidden}`; любая регрессия (рост хедера, echarts перехватывает wheel над виджетом) ломает её и выглядит как «скролла нет».

### Фикс (scoped — только дашборд, `#app` не трогали)
- `.dashboard-detail-page` стал единственным scroll-контейнером страницы: `overflow-y: auto; overflow-x: hidden` (сохранён `height: 100%`).
- `.dashboard-card` и `.dashboard-body`: `flex: 1` → `flex: 1 0 auto`, убран `overflow: hidden`/`overflow: auto` — растягиваются по содержимому grid, не клиппят.
- `:deep(.empty-state)`: `height: 100%` → `min-height: 60vh` (с content-growing цепочкой `height:100%` схлопнулся бы; `min-height` гарантирует площадь пустого состояния).

Проверено headless-репро (1280 + 1920): страница скроллится, полная высота grid (1500px) достижима, нижний ряд доскролливается. `#app` глобально НЕ менялся — ReportPage (внутренний скролл DataTable) не затронут.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle, вне зоны правки).

### Риски
- Drag/resize (grid-layout-plus): не затронуты — менялся только внешний scroll-контейнер, не `.vgl-layout`/`.vgl-item` и не grid-пропсы. `cursor: move` drag-handle на хедере виджета остался.
- Range picker (хедер дашборда): остался в нормальном потоке (НЕ sticky — он и не был sticky). При скролле уезжает вверх; если для демо нужен sticky-хедер с PeriodPicker — отдельная тривиальная правка (`position: sticky; top: 0` на `.dashboard-header` + z-index), не делал чтобы не менять поведение без запроса.
- Прочие дашборды (1/4 виджета): при малом контенте страница просто не скроллится (grid короче вьюпорта) — поведение корректно, регрессии нет.
- Нужен визуальный прогон qa-tester на дашборде с 6 виджетами (happy-path: все 6 видны при скролле) — изолированный репро подтверждает логику, но живой echarts + Toolbox-оверлей репро не покрывает.

---

## 2026-05-25 — action-маркер в мини-чате рендерился как сырой JSON

### Задача
В MiniChatWidget (Toolbox-overlay, scope_type=mini, quick_qa) когда AI отвечал action-маркером (`redirect_to_report_generation` / `redirect_to_widget_generation`) — fenced ```json``` блок в теле сообщения рендерился как обычный текст. Ни CTA-кнопки, ни открытия модалки. В основном чате (/ai-chat) маркер работал.

### Root cause
Не `chat.type` (это была гипотеза в ТЗ). Парсер маркеров (`extractActionMarker` в `@/utils/markdown`) уже type-agnostic и надёжен — гейтится не по типу чата, а по prop `enableActionMarker` на `ChatMessageBubble`. Полноэкранная страница прокидывает `enable-action-marker` + `@action="handleActionMarker"` через `ChatPageShell` → `ChatMessageList`. В `MiniChatWidget.vue` `<ChatMessageList>` рендерился БЕЗ `:enable-action-marker` (default `false`) и БЕЗ `@action` — поэтому bubble пропускал извлечение маркера и рисовал JSON как fenced-code, а событие открытия модалки никуда не шло.

### Файлы
- `front/src/components/chat/composables/useChatActionMarker.ts` (новый) — shared composable: маппинг `ChatActionMarker` → нужная модалка. `redirect_to_widget_generation` → `useWidgetGenerationModalStore.open({mode:'create', prefillPrompt})`, иначе (`redirect_to_report_generation`) → `useReportGenerationModalStore.open(...)`. Логика вынесена из `useChatPage`, чтобы не дублировать между full-screen и mini-chat. Не завязана на `chat.type`.
- `front/src/pages/shared/useChatPage.ts` — удалён inline `handleActionMarker` + прямые импорты двух modal-store'ов; теперь `const { handleActionMarker } = useChatActionMarker()`. Поведение /ai-chat не изменилось (тот же handler).
- `front/src/components/chat/MiniChatWidget.vue` — на `<ChatMessageList>` добавлены `enable-action-marker` + `@action="handleActionMarker"`; в setup `const { handleActionMarker } = useChatActionMarker()`.

### Как теперь парсится маркер
Bubble (`ChatMessageBubble`) при `enableActionMarker=true` зовёт `extractActionMarker(content)` — находит первый fenced ```json``` блок, парсит через `JSON.parse`, проверяет whitelist (`redirect_to_report_generation` / `redirect_to_widget_generation` + непустые `prompt`/`label`). При совпадении: JSON-блок вырезается из markdown (не рендерится текстом), показывается CTA-кнопка с текстом `label`. Клик → `@action` → `handleActionMarker` → открытие модалки с `prefillPrompt = marker.prompt`. НЕ авто-открытие. Распознавание идентично в mini-chat и /ai-chat (один util + один handler).

### Какие модалки открываются
- `redirect_to_report_generation` → `ReportGenerationModal` (store `useReportGenerationModalStore`, `open({mode:'create', prefillPrompt})`).
- `redirect_to_widget_generation` → `WidgetGenerationModal` (store `useWidgetGenerationModalStore`, `open({mode:'create', prefillPrompt})`).
Обе модалки смонтированы на уровне `DefaultLayout` (Toolbox/mini-chat живут в том же layout), так что тоггл store'а открывает их независимо от точки вызова.

### Проверки
- `npm run type-check` — clean.
- `npm run lint` — clean (кроме pre-existing `persist.ts:91` comma-dangle, вне зоны правки).

### Бэкенд
Не трогали — формат эмиссии маркера (fenced json в теле сообщения) корректен по контракту `chats_frontend.md`.

### Риски
- /ai-chat: handler переехал в shared composable, логика идентична — регрессии не ожидается, но стоит smoke-проверить, что в основном чате CTA по-прежнему открывает обе модалки.
- Мини-чат рендерит messages через тот же `ChatMessageList`, что и full-screen — других мест рендера маркера нет.

## 2026-05-25 — "Перейти к дашбордам" CTA в модалке генерации виджета

### Что сделано
Добавлена вторая CTA-кнопка «Перейти к дашбордам» рядом с «✓ Готово» в completed-состоянии модалки `WidgetGenerationModal` (после успешного создания виджета через `create_widget`).

### Файлы
- `front/src/components/chat/WidgetGenerationModal.vue` — новая кнопка в footer'е (обёрнута вместе с «Готово» в `.widget-gen-modal__cta-row` для горизонтального ряда); `useRoute()`/`useRouter()`; computed `showGoToDashboardsCta`; handler `goToDashboards` (close store → `router.push({ name: 'Dashboards' })`); SCSS `.widget-gen-modal__cta-row` (flex + gap).
- `front/src/components/chat/locale/{ru,en}.json` — ключ `widgetGenerationModal.goToDashboards` (RU «Перейти к дашбордам» / EN «Go to dashboards»).

### Логика показа
- Кнопка появляется в том же completed-состоянии, что и «Готово» (флаг `showDoneCta` = `createdWidgetId !== null && !showAddToDashboardCta`).
- Дополнительное условие: `route.name !== 'DashboardDetail'` — т.е. модалку открыли НЕ со страницы конкретного дашборда (`/dashboards/:id`). Если уже на дашборде — кнопку не показываем. Проверка по живому маршруту через `useRoute()`.
- Клик: `modalStore.close()` → `router.push({ name: 'Dashboards' })` (список `/dashboards`, в текущей вкладке). «Готово» оставлена без изменений (просто закрывает модалку).

### Проверки
- `npm run type-check` — чисто.
- i18n RU↔EN — симметрия по namespace модалки подтверждена (нет ключей без пары).

## 2026-05-25 — Фикс: зависание выбора варианта виджета (QA crit-bug)

### Симптом (QA)
Модалка генерации виджета открыта через action-маркер `redirect_to_widget_generation` с НЕ-дашборда (`dashboardId=null`). AI присылает варианты, пользователь жмёт «Выбрать» → панель вариантов исчезает, сообщение «Создай вариант N» не отправляется, completed-state и CTA «Перейти к дашбордам» не достигаются. С дашборда (`dashboardId` задан) — работает.

### Реальная причина (НЕ в `dashboardId`)
В коде отправки/выбора варианта (`useWidgetGenerationModalChat.ts`) **нет ни одной ветки по `dashboardId`** — путь полностью dashboardId-агностичен (проверено grep'ом по композаблу, `ChatService`, `api/chats`). Единственное, что реально отличает два пути открытия модалки, — `prefillPrompt`: action-маркер открывает модалку с пред-заполненным промптом (`widgetModalStore.open({ mode:'create', prefillPrompt })`), дашборд — без него (`{ mode:'create', dashboardId }`). Из-за пред-заполненного ввода весь первый ход стартует быстрее (один Enter), что повышает шанс гонки.

Сам баг — **двойной guard на `isSending`**: и `selectVariant`, и `sendMessage` независимо проверяли `if (isSending.value) return`. Когда ход предложения вариантов ещё доседлывался в момент клика, `selectVariant` проходил свой guard, выставлял `isSelectingVariant=true` и очищал `variants.value=[]` (панель пропадала), после чего `sendMessage` срабатывал на свой `isSending`-guard и **молча возвращался** — запрос не уходил, панель уже очищена, вернуться к выбору нельзя. Тупик. Корреляция QA с `dashboardId` — побочная (через `prefillPrompt`-тайминг).

### Что изменил (`front/src/components/chat/composables/useWidgetGenerationModalChat.ts`)
- `sendMessage` теперь возвращает `Promise<boolean>`: `true` — ход принят (запрос ушёл / стрим стартовал или модалку перехватил новый flow), `false` — отказ (guard `isSending`/пустой контент, ошибка создания чата, сетевая ошибка). Расставлены явные `return true/false` по всем веткам выхода (включая superseded-токен и `catch`).
- `selectVariant`: убран дублирующий `isSending`-guard (решение «идёт ли уже ход?» теперь принимает ТОЛЬКО `sendMessage` — единый источник истины, два guard'а больше не могут разойтись). Остался лишь анти-дабл-клик `isSelectingVariant`. Перед очисткой делаем `snapshot` вариантов; если `sendMessage` вернул `false` — восстанавливаем панель (`variants.value = snapshot`), чтобы пользователь не оставался в пустом теле без запроса и мог повторить выбор.

Итог: выбор варианта и создание виджета работают одинаково независимо от наличия `dashboardId`; при реально идущем ходе пик не теряется, а возвращает панель; completed-state достигается → CTA «Перейти к дашбордам» показывается (она уже была реализована, её блокировал только этот зависон).

### Баг 2 (toast на publish/unpublish/delete дашборда) — уже реализован
Проверка показала, что `DashboardActionsMenu.vue` (новый untracked-файл этой сессии) **уже** вызывает `notifySuccess` на publish (`actionsMenu.toast_published`), unpublish (`toast_unpublished`) и delete (`toast_deleted`) — паттерн идентичен удалению виджета (`notifySuccess(t(key), undefined, TOAST_LIFE_MS)`). Ключи присутствуют и симметричны в `locale/{ru,en}.json` (RU «Дашборд опубликован» / «Публикация снята» / «Дашборд удалён»; EN «Dashboard published» / «Dashboard unpublished» / «Dashboard deleted»). Изменений не потребовалось — QA-репорт по Багу 2, видимо, снят со сборки до того, как toast'ы были добавлены в этой же сессии.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — без новых warning'ов (единственный warning — pre-existing в `plugins/persist.ts`, не из моих правок).
- i18n RU↔EN по `actionsMenu.*` — симметрия подтверждена.
- НЕ затронуто: путь с дашборда (там guard работал, теперь работает так же), меню дашборда, удаление виджета, логика видимости CTA `showGoToDashboardsCta` (route.name !== 'DashboardDetail').

---

## 2026-05-25 — Фикс: корзина в «Управление компаниями» не открывала окно подтверждения

### Симптом
Под superadmin в модалке управления компаниями клик по иконке корзины у несистемных компаний (Capital Invest, BOMI, X/Y/Z) не делал ничего — окно подтверждения удаления не появлялось, ошибок в UI/консоли нет. У системных компаний кнопки нет (`v-if="!is_system"`) — корректно.

### Корень разрыва
Цепочка эмитов была правильной на всех уровнях:
`CompaniesTable.vue` `@click="$emit('delete', data)"` → `ManagementModal/index.vue` `@delete="confirmDelete"` → composable `confirmDelete` ставит `deleteConfirmVisible = true` + `companyToDelete`.

Разрыв был в самом `DeleteConfirmModal.vue` (`ManagementModal/components/`): его PrimeVue `<Dialog>` биндил видимость через `:model-value="visible"` / `@update:model-value`. **PrimeVue 4 Dialog управляет видимостью пропом `visible` (`v-model:visible`), а `modelValue` игнорирует.** Поэтому `deleteConfirmVisible` действительно становился `true`, но Dialog не реагировал — окно никогда не открывалось. Все остальные Dialog в проекте (CompanyFormModal, MacrodataMappingProbeDialog, generic modals/DeleteConfirmModal, и т.д.) используют `:visible`/`v-model:visible` — этот компонент был единственным аутлайером.

### Что изменил (`front/src/components/Company/modals/ManagementModal/components/DeleteConfirmModal.vue`)
- `:model-value="visible"` → `:visible="visible"`
- `@update:model-value="$emit('update:visible', $event)"` → `@update:visible="$emit('update:visible', $event)"`

Пропсы/эмиты компонента (`visible`, `update:visible`, `cancel`, `confirm`) и обработчики в родителе не менялись — они уже были корректны. Подтверждение по-прежнему вызывает `deleteCompany` в composable → `DELETE /api/companies/{id}` + `companiesStore.removeCompany` + toast.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — без новых warning'ов (единственный — pre-existing в `plugins/persist.ts`).
- i18n не затрагивался (использованные ключи уже существуют, RU↔EN симметричны).

---

## 2026-05-26 — Фикс вертикального выравнивания link-ячейки (CRM-id) в таблице отчёта

### Проблема
На странице отчёта `/reports/:id` («Реестр договоров») в колонке «ID объекта» (link-колонка `is_crm_id`, тип `link`) значение (число + иконка `pi-external-link`) было прижато к ВЕРХУ ячейки, тогда как соседние ячейки строки (Дата, Контрагент, Дом и т.д.) выровнены по центру по вертикали. ID визуально «съезжал наверх» в строках, где соседняя ячейка переносилась на 2+ строки и делала ряд выше. Иконка ссылки при этом стояла в правом верхнем углу (`position:absolute; top/right`), выше базовой линии текста.

### Корень
Кликабельный якорь `.crm-id-cell--link` стретчится на весь `<td>` через `position:absolute; inset:0` (вся ячейка — hit-target), но содержимое выравнивалось `align-items: flex-start` с фиксированным паддингом → контент всегда прижат к верху. Иконка была `position:absolute; top:0.15rem; right:0.15rem` — отдельно прибита к углу, не inline с текстом.

### Что изменил (`front/src/pages/ReportPage/index.vue`, SCSS-блок `:deep(.crm-id-cell--link)`)
- `align-items: flex-start` → `align-items: center` — value + иконка теперь по центру по вертикали относительно всей высоты td (совпадает с `vertical-align:middle` остальных ячеек).
- Добавил `gap: 0.3rem` между значением и иконкой.
- `.crm-id-cell__icon`: убрал `position:absolute; top; right;`, заменил на `flex: 0 0 auto` — иконка теперь inline сразу после значения, центрирована вместе с ним.
- Обновил устаревший комментарий в template (иконка больше не «top-right», а inline после value).

Затронут ТОЛЬКО тип колонки `link` (ветка `crm-id-cell--link`). Другие типы колонок, fallback-span (`crm-id-cell:not(.crm-id-cell--link)`), spacer, padding td — не трогал.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — без новых warning'ов (единственный — pre-existing в `plugins/persist.ts`).
- i18n не затрагивался (CSS-only правка).
- Build/docker не запускал — по явной просьбе, пользователь соберёт разом.

---

## 2026-05-26 — Range/date-range фильтры отчёта: убрать дефис-разделитель + фикс налезания иконки календаря

### Задача
Серия правок верстки на странице отчёта `/reports/:id` (панель фильтров).
1. Убрать символ-разделитель «—» между инпутами «От» и «До» во всех range-фильтрах (числовые + date-range). Между инпутами остаётся только gap.
2. Поправить date-инпуты в фильтре «Дата договора»: иконка календаря (`icon-display="input"`) налезала на текст даты (31.05.2026 упиралась в иконку справа) — не было правого padding под иконку.

### Файлы
- `front/src/components/filters/NumberRangeFilter.vue`
  - Удалён `<span class="separator">—</span>` между двумя `InputNumber`.
  - Удалён неиспользуемый `.separator` SCSS-блок. Логика фильтрации не тронута.
- `front/src/components/filters/DateRangeFilter.vue`
  - Удалён `<span class="separator">—</span>` между двумя `Calendar`.
  - Удалён `.separator` SCSS-блок.
  - `.date-input :deep(.p-inputtext)` padding `0 0.5rem` → `0 2.25rem 0 0.5rem` — правый отступ резервирует место под calendar-иконку, текст даты больше не пересекается с иконкой. Применяется к обоим инпутам диапазона.

### Не тронуто
- Логика фильтрации, парсинг/сериализация дат, эмиты — без изменений.
- `AsyncSelectFilter.vue` (async_select «Выберите значение») — не тронут, как просили.
- Другие типы фильтров (SelectFilter / TextFilter) — не тронуты.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — без новых warning'ов (единственный — pre-existing в `plugins/persist.ts`).
- i18n не затрагивался (CSS + удаление статического символа).
- Build/docker не запускал — пользователь копит фиксы и соберёт разом.

---

## 2026-05-26 — Основной фильтр в хедере отчёта (`primary_filter`)

### Задача
На странице `/reports/:id` отрисовать интерактивный виджет «основного» фильтра в хедере справа от названия отчёта (туда, где раньше был статичный summary-чип). Бэкенд отдаёт `report.config.primary_filter` — строку = field одного фильтра из `filters_available`. Виджет переиспользует существующие компоненты фильтров, делит state с большой панелью (фильтр остаётся и там), применяется сразу (debounce для text/number, immediate для select/date).

### Файлы
- `front/src/api/types/reports.ts`
  - В `ReportConfigDto` добавлено опциональное поле `primary_filter?: string` (контракт бэка). Без правок бэка — расширение типа в `api/types/`.
- `front/src/pages/ReportPage/components/ReportHeaderPrimaryFilter.vue` (НОВЫЙ)
  - Компактная inline-обёртка. По `config.type` резолвит ТОТ ЖЕ компонент фильтра, что и панель (`AsyncSelectFilter / DateRangeFilter / SelectFilter / TextFilter / NumberRangeFilter`) — нулевое дублирование верстки. Inline-label слева (из `config.label`), внутренний stacked `.filter-label` дочернего компонента скрыт через `:deep(.filter-field > .filter-label){display:none}` + строка-компоновка.
  - Применение: `text` / `number_range` → debounce 450ms перед `emit('apply')`; `select` / `multiselect` / `async_select` / `date_range` → immediate. Таймер чистится в `onBeforeUnmount`.
  - Прокидывает `update:selectedLabel` (для async_select) наверх, чтобы кэш `asyncSelectLabels` оставался единым с панелью/summary.
  - Props: `field`, `config: ReportFilterConfig`, `modelValue?: ReportFilterValue` (то же, что панель). Source of truth — родительский `localFilters[field]`, никакого второго state.
- `front/src/pages/ReportPage/composables/useReportPageData.ts`
  - Добавлены computed `primaryFilterField` (из `report.config.primary_filter`, только если field реально есть в `effectiveFiltersAvailable` — иначе `null`, фича выключена) и `primaryFilterConfig`. Экспортированы.
- `front/src/pages/ReportPage/composables/useReportPageActions.ts`
  - Добавлен `applyPrimaryFilter(field, value)`: пишет в `localFilters[field]` → коммитит весь снапшот `localFilters` в `currentFilters` → `page=1` → `fetchReport()`. Делит источник истины с панелью (мутирует тот же `localFilters`, панельный виджет реактивно отражает). Экспортирован.
- `front/src/pages/ReportPage/index.vue`
  - Импорт + рендер `<ReportHeaderPrimaryFilter>` в `header-left` справа от `.report-title-block` (`v-if="primaryFilterField && primaryFilterConfig"`).
  - `headerFilterSummary`: поле `primaryFilterField` исключено из статичных чипов (`if (field === primaryField) continue`) — нет визуального дубля «виджет + статичная подпись с тем же значением». Чипы по другим фильтрам остаются.
  - Хендлеры `onPrimaryFilterApply` → `applyPrimaryFilter`, `onPrimaryFilterSelectedLabel` → `setAsyncSelectLabel`.
  - Импортирован тип `ReportFilterValue`. SCSS: `.report-header-primary { flex: 0 1 auto; min-width: 0 }` в `.header-left`.

### Синхронизация и применение (как реализовано)
- Один state: оба виджета (хедер + панель) биндятся на `localFilters[field]` через те же `getFilterValue` / `model-value`. Хедер мутирует `localFilters`, панель читает реактивно — двусторонняя синхронизация без второго хранилища.
- Применение хедера отличается от панели: панель копит изменения и применяет по кнопке «Применить»; хедер применяет сразу. `applyPrimaryFilter` коммитит ВЕСЬ снапшот `localFilters` (а не только primary-поле) в `currentFilters` — т.е. незакоммиченные правки других полей панели уедут вместе с ним. Это сознательный consistent-выбор: один путь коммита вместо параллельного applied-state; primary — «everyday»-драйвер «покажи данные», и подтянуть остальные правки панели соответствует намерению.
- async_select label: единый кэш `asyncSelectLabels` (хедер форвардит `update:selectedLabel`), summary и панель показывают одно и то же имя контрагента.

### Static-чип
- Когда `primary_filter` задан и поле есть в `filters_available` — этот field вырезается из `headerFilterSummary`; рендерится только интерактивный виджет. Остальные фильтры (date_range/single entity) по-прежнему дают статичные чипы.
- Отчёты без `primary_filter` (или с field, отсутствующим в `filters_available`) — хедер не меняется (виджета нет, чипы как раньше).

### Проверки
- `npm run type-check` — мои файлы чисто. Есть 2 pre-existing ошибки в `NumberRangeFilter.vue` (строки 14/25) — НЕ мои: это незакоммиченная правка @input-хендлера из другой сессии (локальный `InputNumberInputEvent { value: number|null }` строже PrimeVue `string|number|undefined`). Вне зоны этой задачи, флагнуть отдельно.
- `npm run lint` — мои файлы без warning'ов.
- i18n — новых ключей нет (label берётся из backend `config.label` через `getLocalizedText`, дочерние фильтры используют свои локали). Симметрия ru/en не затронута.
- Build/docker не запускал — пользователь копит фиксы.

---

## 2026-05-26 — фикс type-check NumberRangeFilter + брендовая палитра ECharts MACRO

### Задача 1: type-check в NumberRangeFilter.vue (блокер сборки)
Предыдущая правка @input-хендлеров (`:model-value` + `@input="onFromInput/onToInput"`, чтение числа из payload) оставила 2 ошибки vue-tsc: локальный тип `InputNumberInputEvent { value: number | null }` был строже реального PrimeVue-типа (`value: string | number | undefined`).

**Файл:** `front/src/components/filters/NumberRangeFilter.vue`
- Импортирую официальный тип события: `import InputNumber, { type InputNumberInputEvent } from 'primevue/inputnumber'` (PrimeVue экспортирует его из default-модуля; `value: string | number | undefined`, плюс `originalEvent` и `formattedValue`).
- Удалил локальный `interface InputNumberInputEvent { value: number | null }`.
- Добавил безопасный конвертер `toNumberOrNull(value: string | number | undefined): number | null` — пропускает только конечное число (`Number.isFinite`), всё остальное (пусто / очищено / непарсится) → `null`.
- `onFromInput` / `onToInput` теперь принимают официальный `InputNumberInputEvent` и присваивают через `toNumberOrNull(event.value)`.
- Логика ввода не тронута: echo-guard в watcher (overwrite ref только при реальном расхождении incoming vs local) и чтение из payload остались как были.

**Проверка:** `npm run type-check` (host node, контейнер frontend nginx-only) — 0 ошибок. `eslint` по файлу — без warning'ов.

### Задача 2: палитра ECharts под бренд MACRO
Палитра категориальных серий — `VIZION_ECHARTS_PALETTE` в `front/src/plugins/echarts.ts` (она же кладётся в `vizionTheme.color`, тема регистрируется через `registerTheme('vizion', ...)`; все виджет-карточки на vue-echarts читают `theme="vizion"`).

**Было** (10 цветов Tremor/Apple-Health: indigo/violet/teal/amber/pink/green/orange/cyan/lime/fuchsia).

**Стало** (10 цветов, ведут брендовые синие MACRO, чередование насыщенных/приглушённых для различимости соседей):
```
'#2B4987', // Primary Light — ведущий брендовый синий
'#172747', // Primary — глубокий navy
'#8DD9FF', // Info — яркий sky-blue акцент
'#6C757D', // Secondary — нейтральный slate
'#A7EFAA', // Success — зелёный
'#FF5A44', // Danger — красный
'#ABB5BE', // Secondary Light — бледный slate
'#FFB38A', // Warning — персиковый/оранжевый
'#7E7F82', // средне-серый
'#9B9C9F', // светло-средне-серый
```
- Доминанта — фирменные синие (`#2B4987` первый, `#172747` второй).
- Светлые серые (`#F1F2F3`/`#E3E4E6`/`#D5D6D8`) и White в серии НЕ попали — оставлены под фон/гриды/axis line (axis/split-line tokens в теме не менял).
- Money/date форматтеры (`chartFormatters.ts`) не тронуты — только цвета.

**Single-color fallback `?? '#6366f1'` → `?? '#2B4987'`** (Primary Light) в трёх местах `colorAt()`:
- `front/src/pages/DashboardPage/components/WidgetChartCard.vue`
- `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue`
- `front/src/components/chat/WidgetVariantCard.vue`
(сам `colorAt` индексирует палитру по модулю длины, fallback по сути dead-branch, но приведён к бренду для консистентности.)

### Проверки
- `npm run type-check` — 0 ошибок (чисто).
- `eslint` по всем 5 затронутым файлам — без warning'ов.
- i18n не затронут.
- Build/docker не запускал — пользователь копит фиксы.

---

## 2026-05-26 — Валюта в заголовке колонки + итого, не в каждой ячейке (currency_in_header)

### Задача
Money-колонки рендерили символ валюты в КАЖДОЙ ячейке («3 990 000 AED»). Нужно: ячейка — голое число без валюты; символ — в ЗАГОЛОВКЕ колонки («Стоимость, AED») и в строке ИТОГО («19 840 000 AED»). Валюта ДИНАМИЧЕСКАЯ по активной компании (Buildera → AED, KZT → ₸), без хардкода символа.

### Расследование (источник валюты)
- Money-форматтер — frontend-side, `src/composables/useFormatter.ts`. Символ резолвится из `companiesStore.getCurrentCompany?.currency_code` (ISO 4217: `AED`/`KZT`), форматируется `Intl.NumberFormat({style:'currency', currencyDisplay:'narrowSymbol'})` → даёт символ (₸ для KZT, AED для AED). Приоритет: explicit override > active company > `RUB`.
- Строка ИТОГО (`formattedTotalsRow` в `useReportPresentation.ts`) использует ТОТ ЖЕ форматтер `format(value, {type:'money'})` → тот же символ. Тело и итого шли через один путь.

### Реализовано (3 файла)
1. **`src/composables/useFormatter.ts`** — добавлена `formatCurrencySymbol(override?)`: резолвит ГОЛЫЙ символ валюты активной компании через `Intl.NumberFormat(...).formatToParts(0)` → `type==='currency'` part (тот же источник, что и money-ячейки, символ не может разойтись). Fallback на ISO-код при неизвестной валюте. Экспортирована из composable.
2. **`src/api/types/reports.ts`** — в `ReportColumnDto` добавлены 2 поля: `currency_in_header?: boolean` и `currency_suffix?: string | Record<string,string>` (per-unit суффикс типа `/м²`, локализуемый).
3. **`src/pages/ReportPage/composables/useReportPresentation.ts`**:
   - `PresentationColumn` получил `currencyInHeader?: boolean` + `currencySuffix?: string | null`.
   - В маппинге колонок: `currencyInHeader` активируется ТОЛЬКО если `resolvedType === 'money'` (защита от флага на text/number). К `header` добавляется `, {символ}{суффикс}` динамически через `formatCurrencySymbol()`.
   - `formattedTableData`: money-ячейка с флагом форматируется как `'number'` (голое число, без символа).
   - `formattedTotalsRow`: helper `withCurrencySuffix()` дописывает суффикс ПОСЛЕ money-форматтера (символ остаётся), `19 840 000 AED` → `19 840 000 AED/м²`.

### Поведение
- Тип колонки остаётся `currency` (форматтер/итого знают, что это деньги). Флаг переключает только МЕСТО символа, не тип.
- Ячейка: голое число. Заголовок: `Label, {символ}{суффикс}`. Итого: число + символ + суффикс.
- per-м² колонка: `currency_in_header: true` + `currency_suffix: "/м²"` → заголовок `Ст./м², AED/м²`, ячейка голое число, итого `... AED/м²`.

### Проверки
- `npm run type-check` — 0 ошибок (чисто).
- `npm run lint` — 0 errors, 1 warning (pre-existing, `src/plugins/persist.ts:91`, не мой файл).
- i18n не затронут (символ резолвится из Intl, не из локалей).
- Сидер НЕ трогал (зона report-author). Build/docker не запускал.

---

## 2026-05-26 — Фикс перекрытия sticky-заголовка "ID объекта" значением ячейки (регрессия/недофикс)

**Файл:** `front/src/pages/ReportPage/index.vue` (только `<style scoped>`).

### Симптом
При вертикальном скролле таблицы отчёта значение CRM-id ячейки (число, напр. `5383868`)
визуально перекрывало sticky-заголовок «ID объекта». Прошлая правка (thead `z-index:3`,
`.crm-id-cell-td` `z-index:0`) НЕ помогла.

### Корневая причина (НЕ z-index сам по себе, и НЕ мис-привязка inset:0)
Проверил гипотезы по очереди:
1. **Мис-привязка `inset:0`** — ОТМЕТАЕТСЯ: `.crm-id-cell-td` УЖЕ имел `position: relative`,
   значит containing block якоря `.crm-id-cell--link` (`position:absolute; inset:0`) — сама
   ячейка, якорь не растягивался за её пределы. (Тем не менее `position:relative` критичен
   и оставлен — без него inset:0 резолвился бы к table/scroll-wrapper и якорь физически
   залез бы в зону шапки.)
2. **Прозрачный фон шапки** — ОТМЕТАЕТСЯ: `$surface-50` = `#f9fafb`, полностью непрозрачный.
3. **Реальная причина = `z-index:0` на `<td>` создавал КОНКУРИРУЮЩИЙ stacking context.**
   Позиционированный `<td>` с любым целым `z-index` (в т.ч. `0`) создаёт новый stacking
   context. Абсолютный якорь внутри (`z-index:auto`) красится как часть ЭТОГО контекста.
   В скроллируемой таблице этот promoted-слой мог рисоваться поверх sticky-шапки. То есть
   проблема была не «у шапки z-index маловат», а «тело создало свой слой, который ездит
   поверх». z-index:3 на шапке этого не лечил, потому что сравнение шло между промотнутым
   слоем тела и шапкой нестабильно.

### Что поменял
1. **`.crm-id-cell-td`:** убрал `z-index: 0`, оставил `position: relative`. Теперь `<td>` НЕ
   создаёт stacking context (`z-index: auto`) → абсолютный якорь красится в нормальном
   порядке документа, ниже позиционированной sticky-шапки. `position:relative` сохранён как
   containing block для `inset:0` (scope якоря строго к ячейке).
2. **`.p-datatable-thead > tr > th`:** поднял `z-index` 3 → **11** (с запасом выше tfoot
   `z-index:1` и любого случайного стекинга в теле). Непрозрачный фон `$surface-50` оставлен.

### Почему теперь не перекрывает
- Тело больше не промоутит якорь в отдельный слой (нет конкурирующего stacking context).
- Sticky-шапка — единственный позиционированный элемент с высоким z-index (11) и непрозрачным
  фоном → всегда красится поверх прокручивающихся строк.
- Horizontal frozen/sticky-колонок в таблице НЕТ (только `position:sticky` на `th` сверху и
  `tfoot td` снизу) — пункт про sticky-corner неприменим, «Оплачено/К оплате» не закреплены.

### Не сломано
- `.crm-id-cell--link`: центрирование контента (`align-items:center`), `gap:0.3rem` иконки,
  re-applied padding, hit-target на всю ячейку — не тронуты.
- Горизонтальный скролл (`.p-datatable-wrapper overflow:auto`) — не тронут.
- `currency_in_header` рендер заголовка (template `col.header`) — не тронут.

### Проверки
- `npm run type-check` — 0 ошибок (чисто). Правка только в CSS.
- Build/docker НЕ запускал (по инструкции).

---

## 2026-05-26 — DashboardPage: donut overflow + chart tooltip i18n

### БАГ 1: "Структура фонда" (donut) наслаивался на соседний виджет
Корневая причина (фронт): рендер pie/doughnut в `WidgetChartCard.vue` не был гарантированно
ограничен боксом карточки. Три слабых места: (а) легенда `bottom:0` без `type:'scroll'` —
длинный список статусов растягивался горизонтально и выходил за пределы canvas; (б) для `pie`
были включены outside-labels + labelLine (вылезают за радиус); (в) `.widget-card__body`/`__chart`
без `overflow:hidden` + `min-width:0` — при reflow grid-layout-plus (position:absolute item)
ECharts мог измерить устаревший больший размер и canvas рисовался шире карточки.

Фикс (`WidgetChartCard.vue`):
- legend → `type:'scroll'` (pie/doughnut + multiSeries bar/line) + `formatter` обрезает entry >22 симв.
- donut radius `55/75%`→`48/68%`, pie `0/70%`→`0/62%`, center `46%`→`44%` (резерв под легенду).
- pie labels → `position:'inside'`, `labelLine:false` (donut labels уже были off).
- tooltip → `confine:true` на всех типах (тултип не вылезает за контейнер).
- CSS: `.widget-card__body { overflow:hidden; min-width:0 }`, `.widget-card__chart { position:absolute; inset }` — жёсткий backstop, canvas физически не может закрасить соседа.

### БАГ 2: тултипы чартов на русском в EN-версии ("Лидов 9")
Корневая причина: подпись серии в тултипе = `series.name`, который приходит С БЭКЕНДА.
`GET /api/widgets/{id}/data` кладёт в `datasets[].label` строку `config.chart.label` как есть
(`WidgetDataService.php:465` — `$datasetLabel = $chartConfig['label'] ?? ...`). В сидере
(`WidgetSeeder.php`) `chart.label` — ПЛОСКАЯ русская строка (`'Лидов'`, `'Объектов'`, `'Выручка'`,
`'Сделок'`, `'Сумма'`, ...). Локализовать произвольную авторскую строку на фронте нельзя.

Фронт-часть: `resolveSeriesLabel()` (`chartFormatters.ts`) УЖЕ умеет принимать `chart.label` как
объект `{ru, en}` и выбирать по локали (строки 333-337). Технические алиасы (total/cnt) тоже уже
локализованы. Хардкоженых русских строк в `chartFormatters.ts` нет. То есть фронт готов — править
ничего не пришлось, кроме подтверждения контракта.

ТРЕБУЕТСЯ НА БЭКЕНДЕ/В СИДЕРЕ (делегировать): сделать `widgets.config.chart.label` локализованным
объектом `{ru, en}` вместо плоской строки во всех виджетах `WidgetSeeder.php` (поле `chart.label`).
Тогда фронт подхватит автоматически. Доп.: `chart.others_label` ('Другие') тоже плоская строка и
попадает в data-label категории "Другие" в тултипе — её локализация тоже backend-сторона
(`WidgetDataService` собирает label "Другие" из конфига). Тип `WidgetDatasetDto.label: string`
в `api/types/widgets.ts` при этом стоит расширить до `string | LocalizedText` (косметика, не блокер —
jsonb-объект и так проходит через as-is passthrough).

### Проверки
- `vue-tsc --noEmit` — 0 ошибок.
- `eslint` (WidgetChartCard.vue, chartFormatters.ts) — 0 warnings.
- i18n ключи не менялись (локализация серий — backend), симметрия ru/en не затронута.
- Build/docker НЕ запускал (по инструкции).

---

## 2026-05-26 — Тип `WidgetDatasetDto.label`: `string` → `LocalizedText`

**Контекст:** бэкенд теперь может отдавать локализованный объект `{ru, en}` в `datasets[].label` (passthrough из widget config `chart.label`), а не только технический алиас-строку. `resolveSeriesLabel` уже умел обрабатывать объект, но TS-декларация была неточной (`string`).

**Использованный тип:** `LocalizedText` из `@/shared/types` (`type LocalizedText = string | Record<string, string>`) — тот же тип, что используется для всех остальных `{ru,en}`-полей в проекте (reports `header`/`label`, widget `name` и т.д.). Консистентно, без введения нового union.

**Затронутые файлы:**
- `front/src/api/types/widgets.ts` — `WidgetDatasetDto.label: string` → `LocalizedText` (+ doc-комментарий).
- `front/src/entities/widget/types.ts` — `WidgetDataset.label: string` → `LocalizedText` (entity-mirror; маппер `mapWidgetDataDtoToData` прокидывает `datasets` напрямую, поэтому тип должен совпадать).
- `front/src/utils/chartFormatters.ts` — `resolveSeriesLabel(rawLabel)` принимает `LocalizedText` вместо `string`; добавлен helper `pickLocalized()`; ветка «rawLabel — объект» резолвит через локаль вместо возврата как есть.
- `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue` — series `name` теперь через `resolveSeriesLabel(ds.label, props.item.config, locale)` вместо сырого `ds.label` (ECharts series.name ждёт строку).
- `front/src/components/chat/WidgetVariantCard.vue` — то же: series `name` через `resolveSeriesLabel(ds.label, props.variant.config, locale)`.

`WidgetChartCard.vue` правок не потребовал — уже вызывал `resolveSeriesLabel(ds.label, ...)`, который теперь принимает union. `ReportService.ts` и `useWidgetGenerationModalChat.ts` не затронуты (там другие `label`: report-датасеты и variant-proposal).

### Проверки
- `npm run type-check` (vue-tsc) — **0 ошибок**.
- `npm run lint` — 0 errors (1 pre-existing warning в `plugins/persist.ts`, файл не трогал).
- i18n не менялся (локализация серий — backend), симметрия ru/en не затронута.
- Build/docker НЕ запускал (по инструкции).
