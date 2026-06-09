# Frontend changes log (08-05-2026 → 15-05-2026)

> Сводный журнал фронтовых изменений за время отпуска frontend-specialist'а. Это **финальная версия** к возвращению — всё ниже отражает состояние на 2026-05-15.
> Читается сверху вниз: TL;DR → новые/изменённые компоненты → канон/анти-паттерны → tech debt → подробная хронология.

**Содержание:**

1. [Summary by feature](#1-summary-by-feature) — что появилось и изменилось по фичам
2. [Канонические паттерны и анти-паттерны](#2-канонические-паттерны-и-анти-паттерны) — на какие грабли наступали
3. [Known limitations / tech debt](#3-known-limitations--tech-debt) — нерешённое
4. [Detailed chronology](#4-detailed-chronology) — хронология по датам (новое сверху)
5. [Final files touched](#5-final-files-touched) — итоговый список затронутых файлов

---

## TL;DR — что вышло за период (08–16.05.2026)

- **PaymentScheduleCell** — новый mini-table renderer для типа колонки `payment_schedule` (header row + summary + expandable items), + tfoot totals с absolute-positioned label'ами и `:has()`-padding-trick. Самая крупная фича.
- **AsyncSelectFilter** — новый server-side search фильтр (single + multi-select), с lazy-load и синтетической записью для не-загруженного выбранного значения.
- **`filter_default` из metadata** — backend pre-fills фильтры; первый запрос делается дважды если дефолты не пустые.
- **OverlayBadge на кнопке Filters** — счётчик активных фильтров; кнопка стала icon-only после провала вариантов с inline-Badge.
- **Datepicker fixes** — формат `dd.mm.yy` + симметричная сериализация дат в локальной TZ (без `.toISOString()` и `new Date('YYYY-MM-DD')`).
- **Overdue badge на колонках даты** — inline-flex row, лейбл `{days}д` / `{days}d` (сидер ID 17, cross-file).
- **Sortable стрелки** на dot-path / link-колонках — снят guard на фронте, backend стал auth-of-truth.
- **Link cells** — `label_fallback` (muted italic для пустых лейблов), `label_lines` (полная + упрощённая форма), brand-color (`--app-action-primary-bg`), пустые ссылки больше не рендерят `<a>`/`<span>`.
- **Option-маппинг** на text-колонках — `options: { key: { ru, en } }`, реактивен на смену локали (используется в Актах сверки / Непроданных — Тип объекта / Статус).
- **Grouped reports — lazy drill-down** — children больше не приходят inline; новый endpoint `/group-rows`, composable `useReportGroupDrillDown`, spinner/error/«Load more» states.
- **Vue DevTools off в prod** — `vueDevTools()` теперь только при `command === 'serve'`.
- **Корневой URL `/` → `/reports`** — добавлен явный route-redirect для `/`, удалена расходящаяся ветка `getDefaultRoute` в `bootstrapApp` (admin/superadmin раньше летели на `/company`). Единое поведение для всех ролей.
- **Server-side active company** — `setActiveCompany` теперь шлёт `POST /api/active-company/{id}` (через `companiesStore.switchActiveCompany`), source-of-truth = `users.active_company_id`. `?company_id=` query-param выпилен из `GET /api/reports`, `GET /api/reports/{id}/group-rows`, `GET /api/users` — backend resolves через middleware. Bootstrap читает `user.active_company_id` после `GET /api/user` как preferred id для reconcile.

---

## Новые / изменённые компоненты и файлы

Quick-jump для понимания «что вообще трогалось»:

- **NEW** `front/src/pages/ReportPage/components/PaymentScheduleCell.vue` — mini-table cell renderer (header / summary / items, expandable).
- **NEW** `front/src/components/filters/AsyncSelectFilter.vue` — server-side search фильтр (single/multi).
- **NEW** `front/src/assets/styles/_payment-schedule-footer.scss` — global unscoped SCSS для tfoot totals (см. [секция 2.1](#21-глобальный-scss-для-primevue-headerfooter-customizations--да-scoped-deep-через-wrapper--нет)).
- **NEW** `front/src/pages/ReportPage/composables/useReportGroupDrillDown.ts` — per-group state machine для lazy children.
- **CHANGED** `front/src/pages/ReportPage/index.vue` — cell-renderer ветки (link / payment_schedule / footer-slot), expansion-слот для group-rows.
- **CHANGED** `front/src/pages/ReportPage/composables/useReportPresentation.ts` — добавлены `rawType?`, `FooterCell.isPaymentSchedule/paidTotal/dueTotal`, `label_fallback` priority chain, `resolveOptionLabel`, удалены `ABSOLUTE_FOOTER_LABEL_TYPES` / `hasAbsoluteFooterLabelColumn`.
- **CHANGED** `front/src/pages/ReportPage/composables/useReportPageData.ts` — `activeFiltersCount`, `buildDefaultFilters` + второй `fetchReport()` при не-пустых defaults, `resetAllGroupStates` на фильтр/сорт.
- **CHANGED** `front/src/entities/report/filters.ts` — типы `FilterDefault*`, `AsyncSelectFilterConfig`, `FilterDefaultAsyncSelectMultiple`, helper `buildDefaultFilters`.
- **CHANGED** `front/src/api/reports.ts` — `fetchFilterOptions(endpoint, q, limit?)`, `fetchGroupRows(...)`, удалён duplicate re-export `FetchGroupRowsOptions`.
- **CHANGED** `front/src/api/types/reports.ts` — `options?` на `ReportColumnDto`, `label_fallback?`, `label_lines?`, `rawType?` пробрасывание, `ReportGroupRowDto` без `children[]`, новые `GroupRowsResponseDto` / `GroupRowsMetaDto`.
- **CHANGED** `front/src/components/filters/DateRangeFilter.vue` — `date-format="dd.mm.yy"`, симметричные format/parse в локальной TZ.
- **CHANGED** `front/src/assets/styles/main.scss` — импорт `_payment-schedule-footer.scss`.
- **CHANGED** `front/vite.config.ts` — `vueDevTools()` только при `command === 'serve'`.
- **CHANGED (cross-file, backend)** `src/database/seeders/ReportSeeder.php` — overdue badge label сокращён до `{days}д` / `{days}d` (ID 17). Не правка фронта, но связанная.

Полный список затронутых файлов — в конце документа, [секция 5](#5-final-files-touched).

---

## 1. Summary by feature

### 1.1. Reports / data layer

#### Async select-фильтр (server-side search)
- **Что:** новый тип фильтра `async_select` — PrimeVue `Select` с `@filter` debounce 300 ms, GET `{endpoint}?q=...&limit=...`, lazy-load на `@show`, синтетическая запись для выбранного-но-неподгруженного значения.
- **Multi-select вариант:** `config.multiple: true` → рендерится `<MultiSelect>` (chip display), payload отдаётся как `string[]` (паттерн `has_any_pivot`).
- **Реализация:** `front/src/components/filters/AsyncSelectFilter.vue` (новый), `entities/report/filters.ts` (`AsyncSelectFilterConfig`, `FilterDefaultAsyncSelect`, `FilterDefaultAsyncSelectMultiple`), `api/reports.ts` (`fetchFilterOptions`).
- **Подводный камень (исправлен):** значение по умолчанию не отображалось до открытия dropdown — исправлено через `selectedLabel` ref + `displayedOptions` computed (синтетическая запись `{ value, label: selectedLabel ?? value }` если выбранное значение ещё не в `remoteOptions`).

#### `filter_default` из metadata
- **Что:** backend в `filters_available[field].default` возвращает дефолтное значение фильтра. Frontend на первой загрузке распаковывает их в `localFilters` и `currentFilters`, и второй запрос идёт уже с дефолтами.
- **Реализация:** `entities/report/filters.ts` (типы `FilterDefault*`, helper `buildDefaultFilters`), `pages/ReportPage/composables/useReportPageData.ts` (повторный `fetchReport()` если дефолты не пустые).
- **Reset:** `resetFilters` ставит `{}` (не дефолты) — «reset = показать всё».

#### Локализация option-маппинга на text-колонках
- **Что:** backend на колонках `type: text` возвращает `options: { key: { ru, en } }`. Фронт мапит сырое значение → локализованный label при формате ячейки.
- **Реализация:** `api/types/reports.ts` (`options?: Record<string, string | { ru, en }>` на `ReportColumnDto`), `useReportPresentation.ts` (helper `resolveOptionLabel`, реактивен на `locale.value` через computed).
- **Цепочка fallback:** `options[value][locale]` → `options[value]['en']` → первый value → сырое значение.

#### Badge со счётчиком на кнопке «Фильтры»
- **Финальный паттерн:** icon-only `<Button icon="pi pi-filter|pi-filter-fill">` обёрнут в PrimeVue `<OverlayBadge :value="activeFiltersCount" severity="danger">` (рендерится только при `activeFiltersCount > 0`). `v-tooltip.bottom` сохраняет действие-label на hover, `aria-label` — для accessibility.
- **Helper:** `activeFiltersCount` в `useReportPageData.ts` — пропускает `null`/`''`/пустые массивы и range-объекты со всеми `null` bounds.
- **Промежуточные попытки (откатано):** inline `<Badge>` в default-слоте → текст «Фильтры» клипался (overflow:hidden от Aura) → `:deep(.p-button) { overflow: visible }` → кнопка всё равно вылетала из flex-ряда. Финал: icon-only снимает проблему по корню.

#### Datepicker fixes
- **Формат:** `date-format="dd.mm.yy"` на обоих `Calendar` в `DateRangeFilter.vue` (вместо JS-default `Sun May 31 2026`).
- **Timezone off-by-one (исправлен):** в `formatDateValue` / `parseDateValue` отказались от `toISOString()` и `new Date('YYYY-MM-DD')` (UTC midnight). Сериализация и парс теперь в локальной TZ: send — `getFullYear()/getMonth()/getDate()` руками, receive — `new Date(y, m-1, d)` при матче `/^\d{4}-\d{2}-\d{2}$/`. Round-trip симметричный.

#### Overdue badge на колонке даты
- **Сидер (cross-file):** в `src/database/seeders/ReportSeeder.php` отчёт ID 17 («Дебиторская задолженность») — label сокращён `'Просрочено {days} д.' → '{days}д'` (`'Overdue {days}d' → '{days}d'`). Severity=danger сам по себе сигнализирует «overdue», слово было избыточным.
- **Layout:** `<div class="badge-cell">` оборачивает дату и badge. Итог — **inline-flex row** (`flex-direction: row; align-items: center; gap: 0.35rem`). Промежуточно пробовали `flex-direction: column` (badge под датой) — откатили по фидбеку «выглядит disconnected».

#### Sortable стрелки для dot-path / link-колонок
- **Что:** в `useReportPresentation.ts` снят guard `!col.field.includes('.')` — backend теперь сам решает sortable через `col.sortable` (умеет JOIN по dot-path и сорт по `label_field`). Guard `!col.expression` оставлен — expression-колонки сортируем PHP-fallback'ом, не SQL'ом.

#### `label_fallback` на link-колонках
- **Что:** новый optional ключ `label_fallback?: string | Record<string, string>` в `ReportColumnDto`. Когда `label_field` / `label_lines` пусты — рендерится fallback-текст с muted цветом и italic.
- **Priority:** `label_lines (non-empty)` → `label_field (non-empty)` → `label_fallback` → пустая ячейка.
- **CSS:** `.link-cell-label--fallback { color: var(--app-text-muted); font-style: italic }`.
- **Также:** пустые link-cells (без href/label) теперь не рендерят пустой `<a>` / `<span>` — вся пара под `<template v-if="ref.label">`.

#### `label_lines` на link-колонках (полная и упрощённая формы)
- **Полная форма:** `[{ prefix: {ru,en}, field, default: {ru,en} }]` → рендерится `"prefix: value || default"` в несколько строк через `\n`. CSS `white-space: pre-line` на `.link-cell-label` визуализирует переносы.
- **Упрощённая форма:** `[{ field }]` без `prefix`/`default` — теперь поддерживается (раньше throw'ало TypeError). `ReportLabelLineDto.prefix` / `.default` стали optional, helper `buildLabelLinesLabel` бранчует по `line.prefix !== undefined`.

#### Brand-цвет для link-cells
- **Что:** `a.link-cell-label` → `color: var(--app-action-primary-bg)` (`#4a67a3`, тот же токен что primary buttons). Hover → `var(--app-action-primary-hover)` (`#334d8a`) + underline. `span.link-cell-label` — без цвета, только `white-space: pre-line`.
- **Не путать:** НЕ `--app-primary-color` (`#172747`, слишком тёмный, неотличим от обычного текста).

### 1.2. PaymentScheduleCell (новый компонент + footer-totals)

Это самая крупная фича периода — отсюда основная часть итераций.

#### Сам cell (компонент)
- **Где:** `front/src/pages/ReportPage/components/PaymentScheduleCell.vue` (новый).
- **Props:** `value: PaymentScheduleValue` (`{ paid_total, due_total, items: [{date, paid, due}] }`), `cellId: string`.
- **UI:** всегда видимая summary-строка (toggle icon + label «Сверка»/«Total» + paid_total + due_total). Click → expand/collapse детальных строк (date `dd.MM.yyyy` + paid + due). `aria-expanded` / `aria-controls`.
- **Header row:** добавлен над summary — три колонки («Дата оплаты», «Оплачено», «К оплате» / «Payment date», «Paid», «Due»). Сетка `1rem 1fr 1fr 1fr` мирроит summary/items. `aria-hidden="true"`.
- **min-width:** `24rem` на `.payment-schedule-cell` — не даёт мини-таблице схлопнуться (`.p-datatable-table-container` имеет `overflow: auto` → горизонтальный скролл вместо grid breakage).
- **Date parsing:** `YYYY-MM-DD` режется руками, БЕЗ `new Date(string)` — избегаем UTC shift.
- **Wiring в ReportPage:** в `useReportPresentation.ts` добавлен `rawType?: string` на `PresentationColumn` (= `col.type`), `resolveColumnType` для `'payment_schedule'` возвращает `'string'` (formatter не лезет в объект). В `index.vue` — `v-if="col.rawType === 'payment_schedule'"` ветка в обоих body-слотах (flat + grouped children), рендерит `<PaymentScheduleCell :value="tableData[rowIndex][col.field]" />` (сырое значение, не из `formattedTableData`).

#### Footer totals (в табличном tfoot, не внутри ячейки)
- **Финальное состояние:** в `#footer` слоте Column для payment_schedule колонок рендерится мини-структура из двух пар: лейбл («Оплачено» / «К оплате») + число.
  - Лейбл — `.ps-footer-label`, абсолютно позиционированный (`position: absolute; top: -0.9rem; left: 0; right: 0`), `font-size: $font-size-sm`, `color: $surface-500`, `font-weight: 400`, `line-height: 1`, `white-space: nowrap`, `text-align: left`.
  - Пара — `.ps-footer-pair`, `position: relative; display: flex; flex-direction: column; gap: 2px`. Резерв места под absolute-лейбл даёт `padding-top` на `td` через `:has()`, не на pair'е (см. ниже).
  - Строка — `.ps-footer-row`, `position: relative; display: flex; justify-content: space-evenly; align-items: center; gap: 0.75rem`.
  - Значение — `.ps-footer-value`, `font-weight: $font-weight-semibold; line-height: 1.2`.
- **Padding td:** через `:has()` — `.p-datatable-tfoot tr:has(.ps-footer-row) > td { padding-top: 1.4rem; padding-bottom: 1rem; vertical-align: middle }`. Падит **все** td футер-строки, чтобы соседние ячейки («Итого», deal_sum) выровнялись по высоте.
- **Top лейбла:** `-0.9rem` (тянет лейбл вверх в padding-top зону td — лейбл не оверлапит число, но и не «висит» слишком высоко).
- **Файл:** `front/src/assets/styles/_payment-schedule-footer.scss` (новый, global SCSS, импортирован из `main.scss`). НЕ scoped — это критично, см. секцию [Канонические паттерны](#2-канонические-паттерны-и-анти-паттерны).
- **Колор label:** `$surface-500` (на один тон светлее `$surface-600`). Токена `--app-text-disabled` не существует — `$surface-400` был бы «barely visible», `$surface-500` — оптимальный muted.
- **i18n:** ключи `paymentSchedule.footer.paidLabel` / `paymentSchedule.footer.dueLabel` («Оплачено» / «К оплате» / «Paid» / «Due», без двоеточий). `paymentSchedule.headers.{date,paid,due}` — для header-row внутри cell.

#### Helper для footer-cells
- В `useReportPresentation.ts` — экспортируемый `FooterCell` тип расширен полями `isPaymentSchedule?`, `paidTotal?`, `dueTotal?`. Computed `footerCells` ловит `rawType === 'payment_schedule'` и читает `report.value.totals.paid_total` / `.due_total` (backend выдаёт их когда в конфиге `expose: ['paid_total','due_total']` + `totals: [...]`).
- В `index.vue` — helper `getFooterLabel(cell)` бранчует на `cell.isPaymentSchedule`. Если обе totals `null` → `''` (cell пустой, бэк не прислал).

### 1.3. Grouped reports — lazy drill-down

- **Контракт:** `GET /api/reports/{id}` больше не возвращает `children[]` внутри group-row. Вместо этого `children_count: number`, `has_children: boolean`. Дочерние строки — `GET /api/reports/{report}/group-rows?group_key=...&page=...&per_page=50`.
- **Реализация:**
  - `api/types/reports.ts` — `ReportGroupRowDto` (без `children`, с `children_count`, `has_children`), новые `GroupRowsResponseDto`, `GroupRowsMetaDto`.
  - `api/reports.ts` — `FetchGroupRowsOptions` (interface), `fetchGroupRows()` метод.
  - `entities/report/types.ts` — `ReportGroupRow` обновлён.
  - `pages/ReportPage/composables/useReportGroupDrillDown.ts` (новый) — per-group state (loading, error, rows, page, lastPage, loadingMore), API: `onRowExpand`, `onRowCollapse`, `loadMoreGroupRows`, `resetAllGroupStates`, `hasMore`, `getGroupState`.
  - `useReportPresentation.ts` — guard `isGroupRow`: `'children' in row` → `'has_children' in row`.
  - `index.vue` — expansion-слот: `ProgressSpinner` при load, inline error при fail, child DataTable при success, кнопка «Load more» когда `meta.last_page > 1`. Фильтры/сорт → `resetAllGroupStates`.
- **i18n:** `group.load_more`, `group.loading_error` (ru + en).
- **Fix duplicate export:** в `api/reports.ts` `FetchGroupRowsOptions` был экспортирован дважды (interface + re-export type) → TS2484 ломал prod build. Убрали из re-export блока, оставили `export interface`.

### 1.4. Build / Tooling

- **Vue DevTools не попадают в prod:** `vite.config.ts` — `vueDevTools()` обёрнут в `command === 'serve' ? [vueDevTools()] : []`. Проверено grep'ом `__VUE_DEVTOOLS_GLOBAL_HOOK__` / `vue-devtools` / `vite-plugin-vue-devtools` / `__VUE_PROD_DEVTOOLS__` в `dist/assets/*.js` — 0 совпадений. `__INTLIFY_PROD_DEVTOOLS__` в бандле — это vue-i18n runtime, не связано.

### 1.5. Кросс-файловое (фронт + сидер)

- **Overdue badge** — сидер ID 17 + template (см. выше).
- **PaymentScheduleCell footer empty (backend bug)** — диагностирована проблема в `ReportDataService::buildTotals()`: expose-alias поля `paid_total` / `due_total` отбрасываются вторым проходом потому что их нет в `columnMap`. Backend-фикс должен эмитить эти поля напрямую из `fieldAggregates`. Передано `macrodata-engineer`. **На фронте фикса нет** — код корректен.

---

## 2. Канонические паттерны и анти-паттерны

### 2.0. Текущее состояние стилей payment_schedule footer (canonical reference)

Точные значения «как сейчас» — чтобы не вычитывать историю проб. Источник истины — `front/src/assets/styles/_payment-schedule-footer.scss`.

| Свойство | Значение | Селектор |
| --- | --- | --- |
| `padding-top` (td) | `1.4rem` | `.p-datatable-tfoot tr:has(.ps-footer-row) > td` |
| `padding-bottom` (td) | `1rem` | `.p-datatable-tfoot tr:has(.ps-footer-row) > td` |
| `vertical-align` (td) | `middle` | `.p-datatable-tfoot tr:has(.ps-footer-row) > td` |
| Row layout | `display: flex; justify-content: space-evenly; gap: 0.75rem; align-items: center; position: relative` | `.ps-footer-row` |
| Pair | `position: relative; display: flex; flex-direction: column; gap: 2px` | `.ps-footer-pair` |
| Label `top` | `-0.9rem` | `.ps-footer-label` |
| Label color | `$surface-500` | `.ps-footer-label` |
| Label font-size / weight | `$font-size-sm` / `400` | `.ps-footer-label` |
| Label `white-space` / `text-align` | `nowrap` / `left` | `.ps-footer-label` |
| Value font-weight | `$font-weight-semibold` | `.ps-footer-value` |
| Cell min-width | `24rem` | `.payment-schedule-cell` (scoped в `PaymentScheduleCell.vue`) |

i18n ключи: `paymentSchedule.footer.{paidLabel,dueLabel}`, `paymentSchedule.headers.{date,paid,due}`, `paymentSchedule.{total,empty}`.

### 2.1. Глобальный SCSS для PrimeVue header/footer customizations — да; scoped `:deep()` через wrapper — нет

**Не делай:** scoped SCSS внутри `<style scoped>` с `:deep(.p-datatable-tfoot > tr > td)` через wrapper `<div :class="...">` — особенно если wrapper-`<div>` использует `display: contents` чтобы «остаться прозрачным для flex».

**Почему откатано (по фактам):**
1. `display: contents` wrapper рвёт цепочку flex-children (DataTable перестаёт быть прямым ребёнком `.data-section`, теряет `flex: 1; min-height: 0`, sticky footer уезжает). В финальной версии wrapper удалён — на DataTable классы вообще не вешаем.
2. Vue scoped CSS пришивает `data-v-*` к wrapper'у — `:deep()` traverse работает, но **только** через этот wrapper. Любая регрессия (wrapper случайно удалили, переместили `v-else-if` на `<DataTable>`) рушит селектор.
3. PrimeVue v4 не гарантирует приземление `class` через `:class` prop на `.p-datatable` root. На итерации 2026-05-15 DOM-snapshot пользователя подтвердил: класс `footer-with-absolute-labels` так и **не приземляется** на root, при этом `.ps-footer-*` рендерятся через `#footer` slot нормально.
4. `pt.root.class` с **объектом-кондишеном** `{ 'name': boolean }` НЕ работает в PrimeVue 4.5.4 — `_getPTClassValue` оборачивает в `{ class: { class: { ... } } }`, Vue `mergeProps` не разворачивает. Тестировалось — класс не приземляется. (Простой `pt.root.class: 'name'` строкой — приземлится, но всё равно см. п. 3, не на тот элемент что нужно.)

**Делай:**
- Глобальный SCSS-файл рядом с `assets/styles/`, импорт через `main.scss`.
- Селектор по уже существующим в DOM классам (`.ps-footer-row`, `.ps-footer-pair`, etc.) — они приходят из `#footer` слота `<Column>`, гарантированно в DOM.
- Padding td — через `:has()`: `.p-datatable-tfoot tr:has(.ps-footer-row) > td { ... }`. Селектор ограничивает сам себя (только консьюмеры `.ps-footer-row` подпадают), другие отчёты не задеваются.

**Файл-эталон:** `front/src/assets/styles/_payment-schedule-footer.scss`.

### 2.2. Date — никогда не `new Date('YYYY-MM-DD')` и не `.toISOString()`

`new Date("2026-05-31")` → UTC midnight → в UTC+ TZ при чтении назад получите `2026-05-30`. Аналогично `date.toISOString()` отдаёт UTC.

**Делай:**
- Send: `getFullYear() / getMonth()+1 / getDate()` руками.
- Receive ISO-date string: `new Date(y, m-1, d)` при матче `/^\d{4}-\d{2}-\d{2}$/`.
- `formatDateValue` и `parseDateValue` должны быть симметричными — всё в локальной TZ.

### 2.3. Frontend trust backend на sortable

Не дублируй на фронте правила «можно ли сортировать колонку X». Bакенд знает про JOIN'ы / expression'ы лучше. Frontend: `sortable: Boolean(col.sortable) && !col.expression`. Выкинуть guard `!col.field.includes('.')` — backend уже умеет dot-path JOIN.

### 2.4. Bootstrap utility-классы НЕ доступны в проекте

В проекте импортируется только `bootstrap-grid.min.css`. Классы вроде `.form-text`, `.text-muted`, `.text-body-secondary` **отсутствуют**. Используй локальные классы (`.ps-footer-label`) + SCSS через theme-токены (`$font-size-sm`, `$surface-600`).

### 2.5. Inline-button с overflow и flex-ограничениями — лучше icon-only + OverlayBadge

Кнопка с custom `#default` слотом (текст + badge) клипается из-за `overflow: hidden` Aura. Workaround `overflow: visible` ломает ripple containment и не решает overflow на flex-уровне. Чище — icon-only `<Button icon="pi pi-...">` + `<OverlayBadge>` wrapper + `v-tooltip` для action-label.

### 2.6. Footer cell `payment_schedule` — структурно

- Лейбл — `position: absolute; top: <отрицательное>` (поднят в padding-top зону td).
- Pair — `position: relative` (резерв места под лейбл даёт `padding-top` на td через `:has()`, не на pair'е).
- Row — `display: flex; justify-content: space-evenly` (раскладка двух пар по ширине td).
- Padding td — через `:has(.ps-footer-row)`, чтобы соседние td визуально выровнялись.
- Точные значения — [секция 2.0](#20-текущее-состояние-стилей-payment_schedule-footer-canonical-reference).

### 2.7. Stale prod bundle — `--no-cache` rebuild при сложных SCSS-перемещениях

При вынесении SCSS из scoped-стилей компонента в новый global file (как `_payment-schedule-footer.scss`) Vite иногда возвращает кэшированный `dist/assets/index-*.css` без новых правил → визуально кажется что «селектор не работает». Перед QA футера / новых global SCSS всегда сверить что `dist/` свежий (mtime + grep на новые классы). На `<LOCAL_STACK_DOMAIN>` ловилось — отсюда правило.

---

## 3. Known limitations / tech debt

### 3.1. Backend bug: `paid_total` / `due_total` не эмитятся в `totals` (BLOCKING)
- **Что:** для отчётов с `expose: ['paid_total','due_total']` + `totals: [...]` backend пропускает эти поля во втором проходе `buildTotals()` потому что их нет в `columnMap`.
- **Симптом:** футер payment_schedule отображается пустым (фронт корректно читает `report.totals.paid_total` / `.due_total`, но они приходят `null`).
- **Где фиксить:** `src/app/Services/MacroData/ReportDataService.php::buildTotals()` — после `if (!isset($columnMap[$field])) continue;` добавить ветку для expose-alias полей: эмитить `$totals[$field] = $fieldAggregates[$field]`.
- **Передано:** `macrodata-engineer`. На фронте фикса не требуется.

### 3.2. Footer absolute-labels: централизации больше нет (по необходимости — обобщить)
- **Контекст:** на итерациях 7–8 (см. хронологию 2026-05-15) существовал whitelist `ABSOLUTE_FOOTER_LABEL_TYPES` в `useReportPresentation.ts` + computed `hasAbsoluteFooterLabelColumn`. На итерации 13 (selector-only финал) обе сущности **удалены** — стиль не зависит от template-флага.
- **Импликация:** если появится второй тип колонки с absolute-labels футером — он будет писать свои классы `.xxx-footer-*` и свой SCSS-файл. Пока консьюмер один — `payment_schedule` — это не проблема. При двух+ — обобщить (общий класс `.absolute-footer-cell` + SCSS mixin).

### 3.3. AsyncSelectFilter — `value === label` assumption
- **Что:** синтетическая запись для не-загруженного выбранного значения использует `{ value, label: selectedLabel ?? value }`. Если для async_select поля backend пришлёт value, отличающийся от человеко-читаемого имени — на первой загрузке (до открытия dropdown) пользователь увидит `value` (например ID), а не имя. Сейчас MVP — для string-identity полей (имя контрагента = value).
- **Митигация:** `selectedLabel` обновляется опортунистически в `loadOptions` если ответ сервера содержит current value.
- **Long-term:** если потребуется async_select по числовому ID — добавить отдельный endpoint resolve `value → label` (single fetch на mount).

### 3.4. `pt.root.class` (PrimeVue 4.5.4) — не использовать с объектом-кондишеном
- Это уже зафиксировано в [2.1](#21-глобальный-scss-для-primevue-headerfooter-customizations--да-scoped-deep-через-wrapper--нет), но дублирую как явный red-flag для будущих ревью: если кто-то предложит «давайте через `pt.root.class` навесим класс на DataTable» — отказать, селектор-only через global SCSS — единственный надёжный путь в этой версии PrimeVue.

---

## 4. Detailed chronology

### 2026-06-02

#### Fix pre-existing ESLint `comma-dangle` warning в `persist.ts`

**Контекст:** CI/lint красился из-за давнего нарушения правила `comma-dangle` (стиль проекта — `always-multiline`).

**Проблема:** `front/src/plugins/persist.ts:91` — отсутствовала trailing comma после последнего аргумента многострочного вызова `Object.fromEntries(...)` (`.map((path) => [path, state[path]])`). ESLint: `Missing trailing comma`.

**Правка:** добавил trailing comma через `npm run lint:fix` (по конфигу проекта) → `.map((path) => [path, state[path]]),`. Чисто lint-фикс, никакого рефакторинга, других нарушений в файле нет.

**Результат:** `npm run lint` — clean (0 problems).

### 2026-05-28

#### Поддержка column type `custom_attribute` в `resolveColumnType`

**Контекст:** backend добавил новый column type `custom_attribute` — рендерит кастомные EAV-атрибуты MACRO как колонку отчёта (значение в ячейке — строка, EAV хранит varchar). В ответе `getData` колонка приходит с `type: "custom_attribute"` и опциональной подсказкой форматирования `value_type: "currency"|"number"|"date"|"string"`.

**Проблема:** `resolveColumnType` (`front/src/pages/ReportPage/composables/useReportPresentation.ts`) не имел кейса `custom_attribute` → тип уходил в `default: detectColumnType(field)` (эвристика по имени поля), игнорируя `value_type`. Числовые EAV-колонки (напр. площадь балкона/террасы у Apart Group, `value_type: 'number'`) рендерились как сырая строка `46.1` вместо форматированного `46,10`.

**Правка:** добавил `case 'custom_attribute'` в ту же группу, что `relation_aggregate` / `window_aggregate` — `return resolveColumnType(valueType, field)`. Механизм переиспользован 1:1: рекурсивный вызов резолвит `value_type` (currency→money, number→number, date→date, string→string) через основной switch; при отсутствии `value_type` рекурсия падает в `default` → `detectColumnType(field)` (тот же fallback, что у соседних агрегатных типов). Никакой новой логики.

**Затронуто:**
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `case 'custom_attribute'` в `resolveColumnType` + обновлён комментарий мотивации.
- `front/src/api/types/reports.ts` — doc-комментарий поля `ReportColumnDto.value_type` упоминает `custom_attribute` (тип поля `type?: string`, union не расширялся).

**Не трогал:** sortability определяется флагом `Boolean(col.sortable) && !col.expression` (backend = source of truth), не типом — `custom_attribute` сортируется автоматически. Фильтры: тип не приходит в `filters_available`, фильтр-панель его не касается. Структурного спец-кейса (как у `payment_schedule`) не требует.

**type-check:** зелёный (`npm run type-check`, host node — frontend-контейнер nginx-only).

#### Проброс `col.format` в number-форматтер (фикс trailing zeros)

**Контекст:** колонка «Балкон» отчёта Apart Group (`type: custom_attribute`, `value_type: number`, `format: '0.00'`) отдаёт сырое `'21.7000'`. Конфиг колонки задаёт `format: '0.00'` (ожидается 2 знака → `21,70`), но рендерилось `21,7` — trailing zeros терялись.

**Корень:** фронт вообще не читал `col.format` из API-ответа. `format(...)` в `useFormatter` для типа `number` шёл в `value.toLocaleString(locale)` без опций precision → `Decimal('21.7000').toNumber()` = `21.7` → `'21,7'`. Это общий gap форматтера number-колонок, не только нового `custom_attribute`.

**Правка (3 файла):**
- `front/src/api/types/reports.ts` — добавил `format?: string` в `ReportColumnDto` (passthrough из бэка).
- `front/src/composables/useFormatter.ts` — добавил опцию `decimals?: number` в `FormatOptions`; применяется **только** в `default`-ветке `formatNumber` (plain number) через `minimumFractionDigits = maximumFractionDigits = decimals` (фиксированная точность, trailing zeros сохраняются). Ветки money / area / percent / date не тронуты.
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — экспортирован helper `parseFractionDigits(format)` (считает символы после последней `.` в паттерне: `'0.00'`→2, `'0.000'`→3; нет точки / пустое / не-строка → `undefined`); `PresentationColumn.decimals` заполняется через `parseFractionDigits(col.format)`; в `formattedTableData` `decimals` форвардится в `format(...)` **только** когда `cellType === 'number'` (или undefined-fallback); в `formattedTotalsRow` тоталы number-колонок форматируются с той же точностью, чтобы футер совпадал с телом.

**Парсинг decimals:** `parseFractionDigits` берёт `lastIndexOf('.')` и считает оставшиеся символы; возвращает `undefined` если точки нет или знаков 0 — так «нет format» и «целочисленный format (`'0'`)» обрабатываются одинаково (runtime-default precision).

**Регрессия-guard:** `decimals` передаётся в форматтер ТОЛЬКО когда `format` реально задан (`parseFractionDigits` иначе `undefined`) И тип ячейки `number`. Двойная защита: (1) `parseFractionDigits` → `undefined` для колонок без `format`; (2) гейт на `cellType === 'number'` в body + `column?.type === 'number'` в тоталах не даёт `format` на money-колонке утечь в number-ветку. Money-формат (млн/млрд + валюта) и date-формат не тронуты — колонки без `format` (подавляющее большинство) рендерятся как раньше. Сосуществует с `value_type`-резолвом для `custom_attribute`: balcony (`custom_attribute` + `value_type=number` + `format='0.00'`) → `resolveColumnType` даёт `'number'`, `parseFractionDigits('0.00')` даёт 2 → `21,70`.

**type-check:** зелёный (`npm run type-check`, host node). Lint: чисто (единственный warning — pre-existing в `plugins/persist.ts`, не наш файл).

### 2026-05-18

#### Merge `origin/refactor/small-improvements` into `dev` (direct merge, user-requested)

**Контекст:** в ветке `origin/refactor/small-improvements` (15 коммитов от той же базы `ca8e265`, что и текущий dev) — альтернативный рефактор session/application-слоя, который параллельно с dev'ом переделывал ту же самую зону, но другим способом (вынес company-logic в отдельный `companySelectionService`, переименовал ряд функций, реорганизовал theme-папку, добавил `SESSION_FLOW.md` и `useLoginPage.ts`). Пользователь явно попросил влить как есть, понимая риск конкурирующего рефактора.

**Конфликты git (3 файла, разрешены):**

- `front/src/application/bootstrap/bootstrapApp.ts` — взял версию из ветки (лучше структурирована: extracted `resolveBootstrapRequestContext`, `authenticateWithIframeToken`, `redirectAfterAuthenticatedBootstrap`, `handleAuthenticatedBootstrapError`; вызывает новый `sessionCoordinator.initializeSession` который сам решает анонимная/authenticated сессия).
- `front/src/application/index.ts` — слил exports вручную: имена из ветки (`resetAuthenticatedSessionState`, `clearSessionState`) + сохранил `notifyCompanySwitchError` из dev (используется в `CompanySwitcher.vue`, ветка его удаляла как «лишний»).
- `front/src/application/session/sessionCoordinator.ts` — взял версию из ветки (новая архитектура с делегированием company-logic в `companySelectionService`, single-flight runtime через WeakMap по pinia вместо inline-state).

**Принятые из ветки структурные изменения:**

- **Новый файл** `front/src/application/company/companySelectionService.ts` — encapsulates company state (`activeCompanyId`, `hasCompanies`, `clear`, `reconcile`, `refreshCompanies`).
- **Rename** `front/src/application/scope/createCompanyScopeSync.ts` → `front/src/application/company/companySyncService.ts`.
- **Rename** `front/src/application/session/sessionState.ts` → `front/src/application/session/sessionStateService.ts`.
- **Rename** `front/src/pages/shared/useCompanyScopedPage.ts` → `front/src/pages/shared/useCompanySelection.ts`.
- **Function renames:** `resetAuthSessionState` → `resetAuthenticatedSessionState`, `resetUserScopedState` → `clearSessionState`. Все call-sites обновлены автоматически через auto-merge (`router/index.ts`, `stores/user.ts`, `application/auth/unauthorizedHandler.ts`, `components/ProfileMenu/ProfileMenu.vue`).
- **Новый composable** `front/src/pages/LoginPage/composables/useLoginPage.ts` — orchestration логин-страницы; `pages/LoginPage/index.vue` теперь тонкий.
- **Новый doc** `front/src/application/session/SESSION_FLOW.md` — 900+ строк описания session-flow архитектуры (single-flight, hydrated tokens, race conditions, mutation effects). Полезно для onboarding'а.

**Theme reorg (auto-merge, без конфликтов):**

- Плоская структура `front/src/theme/adapters/primevue/{buttons,colors,dataDisplay,feedback,forms,foundation,navigation,overlays,primitive,semantic,surfaces}.ts` → иерархическая `theme/adapters/primevue/{primitive/{colors,index},semantic/{buttons,dataDisplay,feedback,forms,foundation,index,navigation,overlays,surfaces}}.ts`.
- `theme/scss/index.scss` удалён (был duplicate).

**Семантический риск (без явной регрессии в типах, но стоит проверить QA):**

- В старом `sessionCoordinator` (dev) был метод `getPreferredCompanyId() = userStore.getUser?.active_company_id ?? companiesStore.getActiveCompanyId`. Это был дополнительный fallback на server-side `users.active_company_id` (фича из 2026-05-16, см. ниже).
- В новом `companySelectionService.reconcile()` (ветка) preferredId = только `companiesStore.getActiveCompanyId` (locally-persisted). Логика server-side switch в `companiesStore.switchActiveCompany` сохранена (она пишет `user.active_company_id ?? companyId` в `companiesStore.activeCompanyId`), но bootstrap-reconcile уже не consult'ит `user.active_company_id` напрямую.
- **Что проверить qa-tester'у:** супер-админ при перезаходе попадает на ту компанию, в которой он работал последний раз (по `users.active_company_id` от сервера, не по локальному localStorage). Если поведение разошлось — это известный риск этого мерджа.

**Валидация:**

- `npm run type-check` — clean (vue-tsc --build, 0 errors).
- `npm run build` — clean (только preexisting chunk-size warning на primevue bundle 934kB).
- `npm run lint` — 1 warning (preexisting `plugins/persist.ts:91` trailing comma, не из мерджа).

**Coomits:** один merge-commit (`--no-ff`, см. `git log --oneline -3` после мерджа). TypeScript-fixes не понадобились — все call-sites старых имён auto-merge перенёс корректно, потому что ветка переименовала их одновременно во всех файлах.

---

### 2026-05-16

#### Active company: server-side switch + middleware-driven scope (frontend task #4)

**Контекст:** бэк закатил полноценную «активную компанию» (см. PR backend; 361 тест зелёный). На сервере появилось:

- Колонка `users.active_company_id` (bigint FK, бэкфилл = `company_id`).
- Новый endpoint `POST /api/active-company/{id}` — валидирует `canAccessCompany`, пишет в БД, возвращает обновлённого user-DTO с `active_company_id` + объектом `active_company`.
- `ResolveActiveCompany` middleware на каждом authenticated request — кладёт active-company в request attribute; `ReportController` / `UserController` берут оттуда, **не из query**.
- `GET /api/user`, `POST /api/login`, `POST /api/iframe-auth` теперь возвращают `user.active_company_id` (int) + `user.active_company` (Company shape).
- `?company_id=` query-param в `GET /api/reports`, `GET /api/reports/{id}/group-rows`, `GET /api/users` бэк **игнорирует** (не ломает, но и не учитывает).
- Семантика: `GET /api/users` для superadmin теперь возвращает юзеров **только активной компании** (раньше — глобально).

Задача фронта — синхронизироваться: пробить server-side switch вместо чисто локальной мутации, перейти на серверный source-of-truth, выпилить устаревший query-param.

**Изменения (по слоям):**

1. **Контракты типов.**
   - **CHANGED** `front/src/api/types/users.ts` — `UserDto` получил поля `active_company_id: number | null` и `active_company: CompanyDto | null` (импорт `CompanyDto`).
   - **CHANGED** `front/src/entities/user/types.ts` — `User` получил те же поля (`active_company: Company | null`).
   - **CHANGED** `front/src/entities/user/mappers.ts` — `mapUserDtoToUser` теперь нормализует `active_company_id ?? null` и прогоняет `active_company` через `mapCompanyDtoToCompany`.
   - **CHANGED** `front/src/mocks/data.ts` — `mockCurrentUser` дополнен `active_company_id: 1` и стуб-объектом `active_company` (иначе MSW-моки ломали type-check).

2. **API слой — `switchActiveCompany` + выпил `?company_id=`.**
   - **CHANGED** `front/src/api/companies.ts` — новый метод `switchActiveCompany(id): Promise<UserDto>` (`POST /api/active-company/{id}`). Возвращает обновлённого user'а целиком — мы доверяем тому, что прислал сервер.
   - **CHANGED** `front/src/api/reports.ts`:
     - `fetchReports()` — больше не принимает / не аппендит `?company_id=`. На уровне интерфейса `ReportsApi.fetchReports(_companyId?: number)` параметр **сохранён** как `_`-prefix, чтобы не ломать сигнатуру вызова из `useScopedResource<number, ...>({ load: (companyId) => ... })` — он по-прежнему нужен **как reactive scope-key для перезагрузки**, но в HTTP-запрос больше не идёт. Комментарий в коде это явно объясняет.
     - `FetchGroupRowsOptions.company_id?: number` — удалено полностью; `fetchGroupRows` не аппендит его в URL.
   - **CHANGED** `front/src/api/users.ts` — `fetchUsers()` без params; сигнатура с `_companyId?` тоже сохранена для совместимости с `useScopedResource` (см. CompanyPage / `useCompanyPageData`).
   - Сервисный слой (`ReportService.fetchReports/fetchAllReports`, `UserService.fetchCompanyUsers`) **не правился** — он просто пробрасывает аргумент дальше; вызывающие composables передают `companyId` из `useScopedResource` для триггера, но фактически параметр идёт в noop.

3. **`stores/companies.ts` — server-side switch.**
   - Старый `setActiveCompany(id)` (чисто локальная мутация) **переименован** в `setActiveCompanyLocal` и помечен как internal (используется только в `clear()` и доступен для тестов / fallback'ов). Все внешние вызовы должны идти через новый action.
   - Новый async action `switchActiveCompany(companyId: number): Promise<boolean>`:
     1. **Single-flight guard:** ранний `return false`, если `isSwitching === true`. Защита от double-click на пункте дропдауна и от гонки между несколькими табами / vue-перерисовкой.
     2. No-op, если `activeCompanyId === companyId` (возвращает `true`).
     3. Validates через `canSelectCompany` (то же, что было) — на случай гнилого id в UI.
     4. `await companiesApi.switchActiveCompany(companyId)` → берёт `UserDto`.
     5. Прогоняет через `mapUserDtoToUser`, кладёт в `userStore.setCurrentUser(user)` — это и обновляет `user.active_company_id` для всего приложения.
     6. Записывает `this.activeCompanyId = user.active_company_id ?? companyId` (fallback на запрошенный id если бэк не вернул).
     7. На error: **ничего не мутирует локально** — selectedCompanyId в UI остаётся прежним (computed на `getActiveCompanyId`), откатывать UI вручную не нужно. Notification через `notificationCenter.error(...)`, ключи по статусу: 403 → `companies.switchForbidden`, 404 → `companies.switchNotFound`, прочее → `companies.switchFailed`. Если ключ не зарегистрирован — fallback на `normalizeApiError().message`.
     8. `isSwitching` сбрасывается в `finally`.
   - Новый getter `getIsSwitching: boolean` для шаблонов / disabled state.
   - Импорты: `companiesApi`, `mapUserDtoToUser`, `useUserStore`, `notificationCenter`, `getApiErrorStatus`, `normalizeApiError`, `i18n`. Циклических зависимостей нет (`user.ts` store не импортит `companies.ts`).

4. **i18n (RU + EN, симметрично).**
   - **CHANGED** `front/src/locales/{ru,en}.json` — новый namespace `companies`:
     - `switchForbidden` — «Нет доступа к этой компании» / «You do not have access to this company».
     - `switchNotFound` — «Компания не найдена» / «Company not found».
     - `switchFailed` — «Не удалось переключить активную компанию» / «Failed to switch the active company».

5. **`CompanySwitcher.vue` — wiring async + UX.**
   - `selectCompany` теперь `async`, обёрнут в `if (isSwitching.value) return` (защита от race). Сначала закрывает popover, потом `await companiesStore.switchActiveCompany(id)` (UX: popover не висит во время сетевого запроса — соответствует «оптимистическому» ощущению, плюс ошибка прилетит в виде глобальной toast'ы).
   - Computed `isSwitching = companiesStore.getIsSwitching` пробрасывается в шаблон. Если switch активен — пункты дропдауна получают класс `is-disabled` (opacity 0.6, `cursor: progress`, `pointer-events: none`). Защита от двойного клика, но без визуальной перестройки селектора (как и просили).
   - Никаких других визуальных правок (кнопка/иконка/типография не тронуты).

6. **`sessionCoordinator.ts` — server как source-of-truth.**
   - `getPreferredCompanyId()` теперь: `userStore.getUser?.active_company_id ?? companiesStore.getActiveCompanyId`. То есть после `refreshUser()` (внутри `initializeAuthenticatedSession`) у нас уже свежий `user.active_company_id`, и `reconcileActiveCompany` использует его как preferred → перекрывает persisted localStorage. localStorage `activeCompanyId` остаётся клиентским cache (для скорости отрисовки до загрузки `/api/user`), но больше не имеет приоритета над сервером.
   - `normalizeActiveCompany` / `canSelectCompany` в `shared/session/invariants.ts` остались **нетронутыми** — продолжают работать как safety net на случай рассинхрона (persisted id отсутствует в доступных, сервер ещё не ответил, и т.п.).
   - `bootstrapApp.ts` не правится — он уже вызывает `initializeAuthenticatedSession()` + `reconcileSession()`, цепочка естественно подхватывает новую логику.

**Что про страницу «все юзеры всех компаний»:** искал — её **нет**. Единственная страница, которая дёргает `userService.fetchCompanyUsers`, — `pages/CompanyPage/composables/useCompanyPageData.ts`, и она уже скоупится через `useScopedResource<number, User[]>({ scope: activeCompanyId, ... })`. Семантика «superadmin теперь видит юзеров только активной компании» совпадает с тем, как страница уже работала (она всегда скоупилась на active). Никаких изменений / флагов не требуется. Для глобального обзора superadmin переключает компанию через `CompanySwitcher` — стандартный flow.

**Iframe-flow:** не правлен. Бэк-бэкфилл `users.active_company_id = company_id` для всех существующих пользователей гарантирует, что у iframe-юзера (1 access) сервер всегда вернёт корректный `active_company_id`; селектор скрывается, и `switchActiveCompany` никогда не вызывается. Подтверждено чтением `bootstrapApp.ts` (iframe-ветка не трогает companies-store напрямую, только `userSessionService.loginWithIframeToken` → `setAuthSession`).

**Race conditions / edge cases (думал явно):**

- **Double-click на пункте дропдауна.** `isSwitching` guard перехватывает второй клик до сетевого запроса. Bonus: пункты получают `pointer-events: none` визуально.
- **Switch упал → пользователь жмёт другой пункт.** `isSwitching` уже сброшен в `finally`, второй запрос проходит. UI selection остался на старой компании (а сейчас идёт запрос на третью) — это корректно.
- **Сетевой response пришёл уже после того, как пользователь логаутнулся.** `setCurrentUser(user)` всё равно отработает, но `clearSession` в logout-flow всё перетрёт. Не сценарий для production, но safe.
- **404 на switch (компания удалена в другом табе).** Notification + локальный state нетронут. Следующий бутстрап (или явный refresh user) подхватит реальное состояние через `reconcileActiveCompany`.
- **Persisted `activeCompanyId` в localStorage расходится с server.** Безопасно: server теперь предпочитается в `getPreferredCompanyId`. Старый persisted id используется только до того, как `GET /api/user` отработает (т.е. fraction of a second на cold-start).

**Проверки:**
- `npm run type-check` — vue-tsc: 0 ошибок. Понадобилось дополнить `mockCurrentUser` (MSW-моки строго типизированы).
- `npm run lint` — 0 errors, 2 pre-existing warnings (`application/index.ts:2` и `plugins/persist.ts:91`), оба в файлах, которые мы не трогали. См. [memory: lint_preexisting].
- `npm run build` — production build проходит за ~10s, bundle-size warning только для `primevue` chunk (не наш).

**Риски:**
- Если бэк по какой-то причине **не** добавил `active_company_id` к `GET /api/user` (regression) — `getPreferredCompanyId()` упадёт в `companiesStore.getActiveCompanyId` (persisted), что эквивалентно старому поведению. Reconcile через `normalizeActiveCompany` всё равно сработает.
- `switchActiveCompany` не вызывает `sessionCoordinator.reconcileSession()` после успеха — но это и не нужно: мы уже записали `activeCompanyId` напрямую из server-DTO. Если у юзера сменился `company_accesses` (одновременно с switch) — reconcile прилетит на следующем bootstrap или явном `refreshUser`. Не критично, потому что бэк валидирует доступ перед switch'ем (`canAccessCompany`).
- Старый persisted id в localStorage у юзеров после деплоя — больше **не** перетирает server-side. Это intended; если кто-то жалуется «у меня после деплоя сбросилась компания» — посоветовать обновить страницу (server-side значение теперь is the truth).

**Что НЕ менялось:**
- Дизайн `CompanySwitcher` — только wiring + disabled state класс.
- Iframe-bootstrap / `loginWithIframeToken` — никак.
- `normalizeActiveCompany`, `canSelectCompany`, `resolveAllowedCompanyIds` в `shared/session/invariants.ts` — те же, остаются safety net.
- `useCompanyScopedPage` / `useScopedResource` — не правились; они продолжают работать как реактивный триггер на смену `activeCompanyId`.

### 2026-05-16

#### Корневой URL `/` всегда редиректит на `/reports`

**Контекст:** при заходе на `https://<DEV_DOMAIN>/` (без пути) поведение различалось по ролям — admin / superadmin попадали на `/company`, analyst / viewer на `/reports`. Пользователь явно запросил единое поведение: `/` → `/reports` для всех.

**Корень расхождения:** в `bootstrapApp.ts` была отдельная ветка `else if (initialPath === '/')` которая делала `router.push(getDefaultRoute(user.role))`. Для `superadmin` / `admin` `DEFAULT_ROUTE_BY_ROLE` = `/company`. Это перебивало любую логику Vue Router'а на пути `/` (роут для `/` отсутствовал, был только catch-all `/:pathMatch(.*)*` → `/reports`).

**Правки:**

- **CHANGED** `front/src/router/routes/base.ts` — добавлен в самом начале массива routes:
  ```ts
  { path: '/', redirect: '/reports' },
  ```
  Поставлен **перед** `/login` (порядок не критичен для path-match, но логически — корневой первым). Route-level redirect срабатывает **до** `beforeEach`-guard'а, поэтому если пользователь не залогинен — Vue Router сначала редиректит `/` → `/reports`, затем guard видит `requiresAuth: true` и кидает на `/login?redirect=/reports`. После логина `extractRedirect` вытащит `/reports` и `loginAndRedirect` подхватит — auth-цепочка не сломана.

- **CHANGED** `front/src/application/bootstrap/bootstrapApp.ts`:
  - Удалена ветка `else if (initialPath === '/')` (бывшие строки 86–90), которая делала `router.push(getDefaultRoute(user.role))` и расходилась с route-level redirect'ом.
  - Удалена ставшая ненужной переменная `const initialPath = requestedPath` (использовалась только в удалённой ветке).
  - Iframe-ветка `if (iframeToken && normalizedPathname === '/login')` (строки 79–85 до правки) **оставлена нетронутой** — она обрабатывает специальный случай, когда iframe-токен пришёл на `/login`, и нужно после успешного `loginWithIframeToken` сразу перейти на target route (по `redirectFromUrl?.sanitizedTarget`, либо `getDefaultRoute` как fallback).
  - Импорт `getDefaultRoute` оставлен — используется в той самой iframe-ветке.

**Что НЕ меняется:**
- `getDefaultRoute` / `DEFAULT_ROUTE_BY_ROLE` оставлены as-is — пригождаются для iframe-flow и могут понадобиться в будущем. Семантика «default route после логина» формально живёт, но фактически для логин-flow используется `extractRedirect(query)` (см. `LoginPage`), а bootstrap при заходе на `/` теперь полагается на route-level redirect.
- Catch-all `{ path: '/:pathMatch(.*)*', redirect: '/reports' }` оставлен — закрывает 404'ы.

**Поведение after the change:**
- `/` (anon) → route-redirect `/reports` → guard `requiresAuth` → `/login?redirect=/reports` → после логина `/reports`.
- `/` (auth, любая роль) → route-redirect `/reports` → guard пропускает → `/reports`. Bootstrap больше не делает явный `router.push` для `/`.
- `/?token=XXX` (iframe, anon) → bootstrap `loginWithIframeToken` → URL уже `/` после `history.replaceState` → mount() → route-redirect `/reports` → guard видит аутентифицированного юзера → `/reports`. **Не сломано.**
- `/login?token=XXX&redirect=/some-path` (iframe прямо на login) → iframe-ветка bootstrap'а отрабатывает как раньше (`router.replace(redirectFromUrl?.sanitizedTarget ?? ...)`).
- `/любой-несуществующий-путь` → catch-all → `/reports`. Без изменений.

**Проверки:** `npm run type-check` — зелёный (vue-tsc 0 errors). `npm run lint` — 0 errors, 2 warnings (оба в файлах, которые не трогались: `application/index.ts:2` и `plugins/persist.ts:91` — pre-existing).

**Риски:** минимальные. Единственное изменение поведения для конечных пользователей — admin / superadmin, заходящие на `/` напрямую (без пути), теперь попадают на `/reports` вместо `/company`. Это и есть запрошенный effect. Прямые ссылки на `/company` продолжают работать без изменений (роут на месте, guards те же).

### 2026-05-15

#### AI-chat: markdown-рендеринг, action-маркер CTA, axios timeout 600s

**Контекст:** три фронт-фикса по итогам QA AI-чата. Контракт action-маркера зафиксирован в `chats_frontend.md` §«Action-маркеры в ответах AI».

**1. Markdown-рендеринг ассистентских сообщений (`/ai-chat` и `/ai-reports`).**

До этого `ChatMessageBubble` рендерил `message.content` как plain-text с `white-space: pre-wrap` — заголовки (`### …`), таблицы (`| col | val |`), списки, **bold**, `code`, fenced code-blocks приходили сырым текстом в обеих `/ai-chat` и `/ai-reports`. Премиса задачи (что в одной странице работает, в другой нет) расходится с фактом — markdown не рендерился нигде. Поскольку `ChatPageShell → ChatMessageList → ChatMessageBubble` шарится между двумя страницами, фикс на уровне bubble закрывает обе.

- **NEW** `front/src/utils/markdown.ts`:
  - `renderChatMarkdown(content): string` — in-house markdown → safe HTML. Покрывает то, что реально продуцирует AI: `#/##/###` headings, GFM-tables (header + separator + body), unordered / ordered lists, fenced code blocks (любой язык), inline `` `code` ``, `**bold**`, `*italic*` / `_italic_`, `[text](url)` (только `http(s)://` / `mailto:`), paragraphs с `<br>` для single-line breaks.
  - **Safety:** весь сырой текст проходит `escapeHtml` (`&<>"'`), inline-code контент изолируется placeholder'ами перед bold/italic transforms (чтобы внутри `code` не сработало `**...**`). Ссылки с небелым scheme → текст без `<a>`. Никакого `eval` / `Function`. Применено `v-html` к строго whitelisted output.
  - `extractActionMarker(content): { cleanContent, marker | null }` — для пункта 2 (см. ниже).
  - **Зачем in-house:** проектная политика «не добавлять новых deps на фронте» (запрет на `marked` / `markdown-it`). Покрывает узкий случай — AI-output, не user-input.
- **CHANGED** `front/src/components/chat/ChatMessageBubble.vue`:
  - Для `role === 'assistant'` рендерим `v-html="renderedHtml"` (через `renderChatMarkdown`). Для `user` / `system` — плоский `<p>` с `pre-wrap` (без markdown — пользовательский ввод никогда не считается markdown'ом, плюс не нужно).
  - Scoped SCSS с `:deep()` для `.chat-md-h1/2/3`, `.chat-md-p` (preserve newlines + line-height 1.5), `.chat-md-list`, `.chat-md-pre` (subtle bg + monospace + horizontal scroll), inline `code` (rounded chip), `.chat-md-table` (border-collapse + surface-200 header bg + tight padding), `a` (primary color + underline).

**2. Action-маркер `redirect_to_report_generation` в `/ai-chat`.**

Бэкенд в `quick_qa` ответах может вложить fenced ```json``` block с `{ action: 'redirect_to_report_generation', prompt, label }`. Фронт парсит, скрывает JSON из визуала, рисует CTA-кнопку под ответом; по клику — создаёт `report_generation` чат, очередует rich-prompt как первое сообщение и редиректит в `/ai-reports`.

- **CHANGED** `front/src/utils/markdown.ts` — `extractActionMarker(content)`:
  - Сканирует все fenced ```json``` блоки через `JSON.parse` в try/catch (graceful).
  - Принимает только payload с `action === 'redirect_to_report_generation'` + непустые `prompt` / `label`. Иначе блок остаётся в тексте (рендерится как обычный код).
  - Возвращает `{ cleanContent: контент без распаршенного блока, marker }`. Двойные пустые строки после вырезания сжимаются.
- **CHANGED** `ChatMessageBubble.vue`:
  - Новый prop `enableActionMarker?: boolean` (default `false`). Если включён — парсинг идёт; иначе всё содержимое отдаётся в `renderChatMarkdown` как есть (per bot contract — парсим только в `quick_qa`).
  - Под текстом ответа рендерим PrimeVue `<Button size="small" severity="primary" :label="marker.label" icon="pi pi-sparkles" />` с локальным `isInvokingAction` (loading state). По клику — emit `action` с payload `{ marker, onComplete }`. Label приходит уже локализованным с бэка (по `user.locale`), фронт ничего не подставляет.
- **CHANGED** `ChatMessageList.vue` / `ChatPageShell.vue` — пробрасывают `enable-action-marker` prop вниз и `action` event наверх.
- **CHANGED** `front/src/pages/shared/useChatPage.ts` — новый `handleActionMarker({ marker, onComplete })`:
  1. `chatService.createChat('report_generation')` → новый чат.
  2. Оптимистично `chatsStore.prependChat(...)` + `setActive('report_generation', newChat.id)` (чтобы destination page его подхватил).
  3. `chatsStore.setPendingFirstMessage({ chatId, content: marker.prompt })` — one-shot store-entry для destination.
  4. `router.push({ name: 'AiReports' })`.
  5. `onComplete()` всегда в `finally` — кнопка не зависает loading на ошибке.
- **CHANGED** `front/src/stores/chats.ts`:
  - Добавлено `pendingFirstMessage: ChatPendingFirstMessage | null` (state), `setPendingFirstMessage()`, `consumePendingFirstMessage(chatId)`. Не персистится — store без `persist` plugin'а. Очищается в `clear()`.
- **CHANGED** `useChatPage.initScope()` (на любой `ChatType`): после `loadChat(activeChat.id)`, если `chatsStore.consumePendingFirstMessage(activeId)` вернул pending — вызывается `chat.sendMessage(pending.content)` (non-blocking; sendMessage сам делает оптимистический message + обрабатывает ошибки).
- **CHANGED** `AiChatPage/composables/useAiChatPage.ts` — экспорт `handleActionMarker`.
- **CHANGED** `AiChatPage/index.vue` — `<ChatPageShell enable-action-marker @action="handleActionMarker" ... />`. Для `/ai-reports` prop **не** включаем — там марке́р не появляется (он только в `quick_qa`).

**Почему не два POST'а из бабла:** держим в bubble только UI-логику (parse + emit). Сетевая часть + roйт-навигация — в `useChatPage` (уже имеет `useServices` / `useRouter` / `chatsStore`). Альтернатива «`?prefill=` query» отвергнута — `prompt` может быть длинным rich-prompt'ом (десятки строк), URL-параметры — не место.

**3. Axios timeout 600s для chat-send.**

- **CHANGED** `front/src/api/chats.ts` — `CHAT_SEND_TIMEOUT_MS: 500_000 → 600_000`. Применяется per-request только к `POST /api/chats/{id}/messages` (через `{ timeout }` опцию `chatsApi.sendMessage`). Глобальный axios остаётся без timeout (как было). Комментарий обновлён: «10 minutes for long multi-tool AI flows (GLM-5 + retries)».

**Type-check + lint:** `npm run type-check` — без ошибок; `eslint` по 9 затронутым файлам — clean.

**Файлы (этой итерации):**
- **NEW** `front/src/utils/markdown.ts`
- **CHANGED** `front/src/components/chat/ChatMessageBubble.vue` (markdown render + CTA UI)
- **CHANGED** `front/src/components/chat/ChatMessageList.vue` (prop / event passthrough)
- **CHANGED** `front/src/components/chat/ChatPageShell.vue` (prop / event passthrough)
- **CHANGED** `front/src/pages/shared/useChatPage.ts` (handleActionMarker + consumePendingFirstMessage в initScope)
- **CHANGED** `front/src/pages/AiChatPage/composables/useAiChatPage.ts` (re-export handleActionMarker)
- **CHANGED** `front/src/pages/AiChatPage/index.vue` (enable-action-marker prop + @action handler)
- **CHANGED** `front/src/stores/chats.ts` (pendingFirstMessage state + set/consume actions)
- **CHANGED** `front/src/api/chats.ts` (CHAT_SEND_TIMEOUT_MS 500_000 → 600_000)

---

### 2026-05-15

#### Footer-labels: финальный layout (последний proven state)

**Файлы (финал):**
- `front/src/assets/styles/_payment-schedule-footer.scss`
- `front/src/pages/ReportPage/components/PaymentScheduleCell.vue`
- `front/src/pages/ReportPage/index.vue` (только cleanup wrapper'ов)
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` (удалён `ABSOLUTE_FOOTER_LABEL_TYPES` + `hasAbsoluteFooterLabelColumn`)
- `front/src/assets/styles/main.scss` (импорт)

**Финальные значения SCSS:**

```scss
// _payment-schedule-footer.scss

.p-datatable-tfoot tr:has(.ps-footer-row) > td {
  padding-top: 1.4rem;
  padding-bottom: 1rem;
  vertical-align: middle;
}

.ps-footer-row {
  position: relative;
  display: flex;
  gap: 0.75rem;
  justify-content: space-evenly;
  align-items: center;
}

.ps-footer-pair {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ps-footer-label {
  position: absolute;
  top: -0.9rem;     // тянет в padding-top зону td
  left: 0;
  right: 0;
  font-size: $font-size-sm;
  color: $surface-500;
  font-weight: 400;
  line-height: 1;
  white-space: nowrap;
  text-align: left;
}

.ps-footer-value {
  font-weight: $font-weight-semibold;
  line-height: 1.2;
}
```

```scss
// PaymentScheduleCell.vue (scoped)
.payment-schedule-cell { min-width: 24rem; /* fallback 24rem из расчёта 1rem toggle + 3×0.5rem gap + 3×~7em columns */ }
```

#### История проб footer-labels (хронологически)

1. **multi-line `:footer="string"` → `#footer` slot с 4 div** (`form-text` для muted) — `form-text` не работает (Bootstrap utility не подключен), заменено на `.ps-footer-label` + scoped SCSS с токенами `$font-size-sm` / `$surface-600` / literal `400`.
2. **Two-column layout** — `<div class="ps-footer-row">` с двумя `.ps-footer-pair` (label + value column).
3. **Baseline alignment fix** — `vertical-align: bottom` на `td`, `align-items: flex-end`, `justify-content: flex-end`. **Откатано** — глобальное `vertical-align: bottom` затрагивало все отчёты.
4. **Absolute-positioned labels** — лейбл `position: absolute; top: 0; left: 0`, pair `position: relative` + `padding-top: 1.15rem`. Двоеточия из labels убраны.
5. **Symmetric padding** для vertical-centering — `padding: 0.9rem 0` на pair (вместо `padding-top: 1.15rem`).
6. **space-evenly layout + bold values** — `justify-content: space-evenly` на row, `.ps-footer-value { font-weight: semibold }`.
7. **Universal concept** — `ABSOLUTE_FOOTER_LABEL_TYPES` whitelist + `hasAbsoluteFooterLabelColumn` computed + класс `.footer-with-absolute-labels` на DataTable, uniform td padding (`0.9rem` top, `0.45rem` bottom). **Класс не приземлялся**: `<DataTable :class="...">` через wrapper `<div>` → flex-children сломаны, footer уехал.
8. **Variant B: class напрямую на DataTable** (`:class` prop, без wrapper) — селектор `.p-datatable.footer-with-absolute-labels` в `:deep()`. **Класс всё равно не приземлялся** (PrimeVue v4 class-binding не гарантирует root).
9. **pt.root.class** — переключение на passthrough API. **НЕ работает** — `_getPTClassValue` оборачивает в `{ class: { class: { ... } } }`.
10. **display:contents wrapper** — `<div class="flat-table-wrapper" :class="{...}">` + `.flat-table-wrapper { display: contents }`. Работает, но fragile (data-v scope-id wrapper'а).
11. **Move к global SCSS** — `_payment-schedule-footer.scss` импортирован из `main.scss`, селекторы `.p-datatable.footer-with-absolute-labels ...` без scope.
12. **`:has()` для td padding** — `.p-datatable-tfoot tr:has(.ps-footer-row) > td { ... }`. Убирает зависимость от класса на DataTable вообще.
13. **Selector-only финал** — DOM-снэпшот пользователя подтвердил: `footer-with-absolute-labels` так и не приземляется. `.ps-footer-*` классы в DOM есть (рендерятся `#footer` slot'ом). Удалили класс целиком, селекторы по `.ps-footer-*`, padding td через `:has()`. `ABSOLUTE_FOOTER_LABEL_TYPES` константа удалена (была только для класса). **Текущий канонический паттерн.**
14. **Padding tweaks (финал-2)** — td padding `1.05rem/0.5rem` → `1.4rem/1rem`. Label color `$surface-600` → `$surface-500`. Min-width `24rem` на cell.
15. **Lift label closer to value** — `top: -1.4rem` → `top: -0.9rem` (метка ниже в padding-top зоне, ближе к значению).

#### Diagnosis: backend bug paid_total/due_total (2026-05-15)
- Backend не эмитит expose-alias поля в `totals` второго прохода `buildTotals()`. Frontend код корректен — фикс нужен в `ReportDataService.php`. Передано `macrodata-engineer`. См. [tech debt 3.1](#31-backend-bug-paid_total--due_total-не-эмитятся-в-totals-blocking).

#### Link column: `label_fallback`
- Новый optional ключ `label_fallback?: string | Record<string, string>` на `ReportColumnDto`. Helper `resolveLabelFallback()` (locale-aware) + `resolveChildLabelFallback()` для grouped children. `LinkRef` получил флаг `isFallback?: boolean`. CSS `.link-cell-label--fallback { color: var(--app-text-muted); font-style: italic }`. Backward-compatible: если `label_field` непуст → fallback игнорируется.

---

### 2026-05-14

#### PaymentScheduleCell: header row над summary
- Добавлен `<div class="ps-header">` (4-колоночный grid `1rem 1fr 1fr 1fr`) с заголовками «Дата оплаты» / «Оплачено» / «К оплате». `v-if="hasItems || formattedPaidTotal || formattedDueTotal"`. `aria-hidden="true"`. CSS: `font-size: $font-size-sm`, `font-weight: semibold`, `color: $surface-700`, `border-bottom: 1px solid $surface-200`.
- i18n: `paymentSchedule.headers.date/paid/due` (ru + en).

#### PaymentScheduleCell: новый renderer (фундамент)
- Новый компонент `front/src/pages/ReportPage/components/PaymentScheduleCell.vue`. Props: `value: PaymentScheduleValue`, `cellId: string`. Local `expanded` ref.
- Используется в обоих body-слотах ReportPage (flat + grouped children) через ветку `v-if="col.rawType === 'payment_schedule'"`.
- В `useReportPresentation.ts` добавлен `rawType?: string` на `PresentationColumn`, `resolveColumnType` для `'payment_schedule'` маппит в `'string'` (formatter не лезет в объект).
- i18n: `paymentSchedule.total` («Сверка»/«Total»), `paymentSchedule.empty` («Нет финок»/«No items»).

#### Link column: simple `label_lines` (без prefix/default)
- `ReportLabelLineDto.prefix` / `.default` стали optional. `buildLabelLinesLabel` бранчует на `line.prefix !== undefined`. Пустые строки фильтруются перед `\n`-join'ом. Backward-compatible с полной формой.

#### AsyncSelectFilter: multi-select (`multiple: true`)
- Поддержка `<MultiSelect>` (chip display) когда `config.multiple === true`. Payload — `string[]` (use case: `has_any_pivot`). Тип `FilterDefaultAsyncSelectMultiple` (`{ values: string[] }`). `selectedLabels` map сохраняет chip-labels через search-query changes. `onMultiSelect` эмитит `null` при clear (совместимо с resetFilters).

---

### 2026-05-12

#### Lazy drill-down для grouped reports
- Endpoint contract: `GET /api/reports/{id}` отдаёт group-rows без `children[]`, с `children_count` + `has_children`. Children — `GET /api/reports/{report}/group-rows`. Новый composable `useReportGroupDrillDown.ts` управляет per-group state. Expansion слот рендерит spinner / error / DataTable / «Load more» по состоянию. Фильтры/сорт сбрасывают все group-states.
- i18n: `group.load_more`, `group.loading_error`.

#### Duplicate export fix
- `FetchGroupRowsOptions` дублировался в `api/reports.ts` (interface + re-export type) → TS2484. Удалён из re-export блока.

#### `label_lines` (полная форма) для link-колонок
- `ReportLabelLineDto` interface. `buildLabelLinesLabel` собирает `"prefix[locale]: value || default[locale]"` через `\n`. CSS `.link-cell-label { white-space: pre-line }` визуализирует переносы. Мирор для grouped children в `getChildLinkRef`.

#### Vue DevTools off в prod
- `vite.config.ts` — `vueDevTools()` только при `command === 'serve'`. Проверено grep'ом в `dist/`.

#### Filters button: active-filters count badge (первая версия)
- `activeFiltersCount` computed в `useReportPageData.ts` (пропускает null/empty/zero-range). Badge внутри default-слота кнопки. **Позже заменён на icon-only + OverlayBadge** — см. ниже.

#### Date range filter: `dd.mm.yy` формат

#### Overdue badge: первая итерация (`column` layout)
- `<div class="badge-cell">` обёртка, `flex-direction: column; align-items: center`. **Позже откатано на `row`** — см. ниже.
- Cross-file: `src/database/seeders/ReportSeeder.php` ID 17 — label сокращён до `'{days}д' / '{days}d'`.

#### Filters button: icon-only + OverlayBadge (финальная версия)
- Заменил text-кнопку + inline Badge на icon-only Button + `<OverlayBadge>` wrapper. `v-tooltip.bottom` для action-label. Убран `overflow: visible` hack и mobile-width-100% media-query. Зачищен `.filter-count-badge` SCSS блок.

#### Filter defaults: pre-fill из `filters_available[field].default`
- Типы `FilterDefault{DateRange,NumberRange,Select,Multiselect,Text}` + `FilterDefault` union. Helper `buildDefaultFilters(filtersAvailable)`. На первой загрузке `useReportPageData.fetchReport` вызывает `buildDefaultFilters` и при наличии не-пустых дефолтов делает второй `fetchReport()` с ними.
- Reset → `{}` (не дефолты).

#### Sortable: dot-path / link columns
- Снят guard `!col.field.includes('.')` — `col.sortable` теперь auth-of-truth от backend'а.

#### Overdue badge: revert на `row` (финал)
- `column` (badge под датой) → `inline-flex row` (badge справа от даты, `gap: 0.35rem`). По фидбеку «выглядит disconnected». Финальное состояние.

#### Async select-фильтр (фундамент)
- Тип `async_select` в `ReportFilterType`. `AsyncSelectFilterConfig` (`search_endpoint`, optional `multiple`/`default`). `isReportFilterConfig` guard расширен. Helper `buildDefaultFilters` ветка для `async_select`. API: `fetchFilterOptions(endpoint, q, limit?)`. Компонент `AsyncSelectFilter.vue` (PrimeVue `Select` + lazy load на `@show` + 300ms debounce на `@filter`).

#### Wiring fix + Filters button text-clipping fix
- `getFilterComponent()` в `index.vue` пропустил `case 'async_select'` → компонент не рендерился. Добавлен импорт и кейс.
- Filters button text клипался (Aura `overflow:hidden` на root). Добавлен `:deep(.p-button) { overflow: visible }` под `.filter-toggle-wrap`. **Позже не нужно** — кнопка стала icon-only.

#### Date range timezone off-by-one fix
- См. [секция 2.2](#22-date--никогда-не-new-dateyyyy-mm-dd-и-не-toisostring) в каноне.

#### Link-cell colour: brand primary
- `a.link-cell-label` → `var(--app-action-primary-bg)` (`#4a67a3`). Hover → `var(--app-action-primary-hover)` (`#334d8a`) + underline.

#### Fix link-cell: empty cell when label is null/empty
- Оба render-блока (flat + grouped children) обёрнуты в `<template v-if="ref.label">`. Раньше рендерил пустой `<span>` / `<a>` для ~20% строк с пустым label.

#### Fix AsyncSelectFilter: selected value не показывается после Apply
- `options` ref → `remoteOptions`. Новый `selectedLabel: Ref<string|null>`. Computed `displayedOptions` инжектит `{ value, label: selectedLabel ?? value }` если выбранное значение не в `remoteOptions`. Watch'и на `modelValue` (clear → reset label) и `search_endpoint` (field change → reset label).

---

## 5. Final files touched

Сгруппировано по слоям. Список — для удобства быстрого ревью при возвращении.

**Pages / templates:**
- `front/src/pages/ReportPage/index.vue` — cell-renderer ветки для `link` / `payment_schedule`, `#footer` слот, expansion-слот для group-rows, фильтр-кнопка → icon-only + `<OverlayBadge>`, wiring `AsyncSelectFilter` в `getFilterComponent()`.
- `front/src/pages/ReportPage/components/PaymentScheduleCell.vue` — **NEW**. Mini-table renderer.

**Composables:**
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `rawType?`, `FooterCell.{isPaymentSchedule,paidTotal,dueTotal}`, `label_fallback` chain, `resolveOptionLabel`, sortable guard relaxation. Удалены `ABSOLUTE_FOOTER_LABEL_TYPES` / `hasAbsoluteFooterLabelColumn`.
- `front/src/pages/ReportPage/composables/useReportPageData.ts` — `activeFiltersCount`, `buildDefaultFilters` + второй `fetchReport()` при дефолтах, `resetAllGroupStates` на фильтр/сорт изменения.
- `front/src/pages/ReportPage/composables/useReportGroupDrillDown.ts` — **NEW**. Per-group state machine.

**Components:**
- `front/src/components/filters/AsyncSelectFilter.vue` — **NEW**. Single / multi server-side search.
- `front/src/components/filters/DateRangeFilter.vue` — `date-format="dd.mm.yy"`, симметричные format/parse в локальной TZ.

**Entities / API / types:**
- `front/src/entities/report/filters.ts` — `FilterDefault*` (`DateRange`, `NumberRange`, `Select`, `Multiselect`, `Text`, `AsyncSelect`, `AsyncSelectMultiple`) + union; `AsyncSelectFilterConfig`; `buildDefaultFilters` helper.
- `front/src/entities/report/types.ts` — `ReportGroupRow` без `children`, с `children_count` / `has_children`.
- `front/src/api/reports.ts` — `fetchFilterOptions(endpoint, q, limit?)`, `fetchGroupRows(...)`. Удалён duplicate re-export `FetchGroupRowsOptions`.
- `front/src/api/types/reports.ts` — `ReportColumnDto.{options?, label_fallback?, label_lines?}`, `ReportLabelLineDto` (с optional `prefix`/`default`), `ReportGroupRowDto`, `GroupRowsResponseDto`, `GroupRowsMetaDto`.
- `front/src/api/types/index.ts` — re-exports новых типов.

**Styles:**
- `front/src/assets/styles/_payment-schedule-footer.scss` — **NEW** (global unscoped). Точные значения — [секция 2.0](#20-текущее-состояние-стилей-payment_schedule-footer-canonical-reference).
- `front/src/assets/styles/main.scss` — импорт нового файла.

**i18n (RU + EN, симметрично):**
- `paymentSchedule.{total,empty}`, `paymentSchedule.headers.{date,paid,due}`, `paymentSchedule.footer.{paidLabel,dueLabel}`.
- `group.{load_more,loading_error}`.
- Filter button tooltip-key (icon-only вариант).

**Build / tooling:**
- `front/vite.config.ts` — `vueDevTools()` только при `command === 'serve'`.

**Cross-file (backend):**
- `src/database/seeders/ReportSeeder.php` — отчёт ID 17 «Дебиторская задолженность», overdue-badge label сокращён до `{days}д` / `{days}d`.

**Router / bootstrap (итерация 16.05.2026):**
- `front/src/router/routes/base.ts` — добавлен `{ path: '/', redirect: '/reports' }` в начало массива routes.
- `front/src/application/bootstrap/bootstrapApp.ts` — удалена ветка `else if (initialPath === '/')` + неиспользуемая `const initialPath`. Iframe-ветка для `/login` оставлена нетронутой.

**AI-chat (итерация 15.05.2026):**
- `front/src/utils/markdown.ts` — **NEW**. `renderChatMarkdown()` (in-house parser, no deps) + `extractActionMarker()`.
- `front/src/components/chat/ChatMessageBubble.vue` — `v-html` markdown для `role=assistant`, scoped `:deep()` стили `.chat-md-*`, PrimeVue CTA `<Button>` под маркер-payload, `enable-action-marker` prop, `action` emit.
- `front/src/components/chat/ChatMessageList.vue` — passthrough `enable-action-marker` prop / `action` event.
- `front/src/components/chat/ChatPageShell.vue` — passthrough `enable-action-marker` prop / `action` event.
- `front/src/pages/shared/useChatPage.ts` — `handleActionMarker({marker, onComplete})` (createChat → setActive + pending → router.push('/ai-reports')), consume `pendingFirstMessage` в `initScope`.
- `front/src/pages/AiChatPage/composables/useAiChatPage.ts` — re-export `handleActionMarker`.
- `front/src/pages/AiChatPage/index.vue` — `enable-action-marker` prop + `@action="handleActionMarker"`. `/ai-reports` намеренно НЕ включает prop.
- `front/src/stores/chats.ts` — state `pendingFirstMessage`, actions `setPendingFirstMessage` / `consumePendingFirstMessage`.
- `front/src/api/chats.ts` — `CHAT_SEND_TIMEOUT_MS: 500_000 → 600_000` (per-request для `sendMessage`).

**Active company server-side switch (итерация 16.05.2026, task #4):**
- `front/src/api/companies.ts` — добавлен `switchActiveCompany(id): Promise<UserDto>` (`POST /api/active-company/{id}`).
- `front/src/api/reports.ts` — `fetchReports` без `?company_id=`; `FetchGroupRowsOptions.company_id?` удалён, `fetchGroupRows` не аппендит его.
- `front/src/api/users.ts` — `fetchUsers` без `?company_id=`.
- `front/src/api/types/users.ts` — `UserDto.active_company_id: number|null`, `UserDto.active_company: CompanyDto|null`.
- `front/src/entities/user/types.ts` — `User.active_company_id`, `User.active_company`.
- `front/src/entities/user/mappers.ts` — `mapUserDtoToUser` нормализует `active_company` через `mapCompanyDtoToCompany`.
- `front/src/services/CompanyService.ts` — `switchActiveCompany(id): Promise<User>`.
- `front/src/stores/companies.ts` — переименован `setActiveCompany` → `setActiveCompanyLocal` (internal), новый async `switchActiveCompany(id)` (server-side + setCurrentUser + single-flight `isSwitching` flag), новый getter `getIsSwitching`.
- `front/src/components/Company/CompanySwitcher.vue` — `selectCompany` стал async + `await switchActiveCompany`, `isSwitching` computed → disabled-state класс `is-disabled` на пунктах. Дизайн не тронут.
- `front/src/application/session/sessionCoordinator.ts` — `getPreferredCompanyId` → `user.active_company_id ?? activeCompanyId` (server > persisted).
- `front/src/locales/{ru,en}.json` — namespace `companies` (`switchForbidden` / `switchNotFound` / `switchFailed`).
- `front/src/mocks/data.ts` — `mockCurrentUser` дополнен новыми полями (иначе MSW-тип ломается).

---

### 2026-05-17 — Post-vacation refactor: applying AUDIT-08-05.md findings

Цель — обнулить долг по аудиту до возвращения Жени. Findings ниже идут в порядке `BLOCKING → MEDIUM → COSMETIC`. Каждый блок проверен `npm run type-check` + `npm run lint` локально (host node — frontend контейнер nginx-only). Pre-existing warnings в `application/index.ts:2` и `plugins/persist.ts:91` не трогали — не наши.

#### Blocking

1. **Дедупликация link-cell logic между flat и grouped рендерами.** Добавил `buildLinkRef(col, row): LinkRef` в `useReportPresentation.ts` — единый source of truth для разрешения `href + label + isFallback`. Использует общие helper'ы `buildLabelLinesLabel` / `resolveLabelFallback` из того же composable. `linkRefs` computed (flat) теперь вызывает его в `.map()`, а `getChildLinkRef` в `index.vue` стал тонким wrapper'ом. Удалил локальные `resolveChildLabelFallback` и inline `buildLabelLinesLabel` (60 строк) в `index.vue`, заодно выкинул больше не нужный `useReportLink` import. Экспорт типов: `LinkRef` и `LinkRefColumn` (минимальный shape — `field + link_template? + label_field? + label_lines? + label_fallback?`).
2. **Store companies — i18n + notificationCenter side-effects наружу.** `stores/companies.ts.switchActiveCompany` теперь возвращает `CompanySwitchResult` discriminated union (`{ ok: true } | { ok: false; reason: 'in_progress' | 'invalid_target' | 'request_failed', status, error }`). Store больше НЕ импортирует `i18n` и `notificationCenter` — это нарушение слоёв. Создал `application/session/companySwitchNotifications.ts` с `notifyCompanySwitchError(status, fallback)` — он мапит 403 → `companies.switchForbidden`, 404 → `companies.switchNotFound`, остальное → `companies.switchFailed`. Re-export через `application/session/index.ts` и `application/index.ts` (barrel). `CompanySwitcher.vue` разбирает результат и сам зовёт `notifyCompanySwitchError` только при `reason === 'request_failed'` (in_progress / invalid_target — silent).
3. **`mocks/data.ts` `active_company` стуб.** Заменил огрызок `{ id, name, is_system }` на полноценный `mockCompany: CompanyDto` (с `crm_url: null`) и переиспользовал его в `mockCurrentUser.active_company`. MSW теперь врёт честно — shape совпадает с тем что отдаёт `CompanyResource` на бэке.

#### Medium

4. **`useReportGroupDrillDown.ts` — `getApiErrorStatus` вместо ручного cast.** Импорт из `@/utils/errors`. Логика статусов (400/403/404/422) сохранена 1-в-1.
5. **`AsyncSelectFilter` — миграция на `useAsyncResource`.** Заменил ручной `pending` ref + try/catch на `resource.run(() => fetchFilterOptions(...))`. `requestGate` внутри resource'а гасит stale-responses (race между debounce и переоткрытием dropdown теперь закрыт «бесплатно»). `pending` → `resource.loading`. Cleanup в `onUnmounted` оставлен — `clearTimeout` для debounce-таймера.
6. **`AsyncSelectFilterConfig.async?: boolean` — поле удалено.** Type-tag `'async_select'` уже несёт инфо. В коде / типах / сидерах потребителей не было (бэк не отправляет, фронт не читал).
7. **`useChatPage.handleActionMarker` — переиспользование `chat.createAndOpenChat`.** Расширил `createAndOpenChat` опциональным параметром `{ setCurrent?: boolean }` (default `true`). При `setCurrent: false` — не пишет `options.currentChat.value = chat`, только `prependChat + setActive` (это в точности то что нужно source-странице при action-marker flow). `handleActionMarker` теперь зовёт `chat.createAndOpenChat('report_generation', { setCurrent: false })`, получает `chatId` из обновлённой signature (`Promise<{ ok: true; chatId: number } | { ok: false }>`) и кидает pending message + router.push. Дублирование сошло на нет.
8. **`useChatPage.initScope` — фикс eventual-consistency race с pending message.** Логика поменялась: вместо «есть activeChat — забери pending по его id» теперь «забери pending без аргумента, и если он есть — `loadChat(pending.chatId)` + `sendMessage`». В store добавлен `consumePendingFirstMessage()` без аргумента (передаёт `chatId?: number` — обратная совместимость), при отсутствии аргумента возвращает текущий pending как есть. Теперь даже если backend ещё не вернул свежесозданный chat в list и `reconcileActiveChats()` обнулил `activeChat`, pending всё равно стрельнет.
9. **`'payment_schedule'` литерал — выведен в константу.** `PAYMENT_SCHEDULE_TYPE = 'payment_schedule' as const` в `useReportPresentation.ts`. Используется в composable (2 места) и в `index.vue` template (через computed-utility `isPaymentScheduleColumn(col)` экспортируемую из composable — template читает её без литерала).
10. **`api/reports.ts` / `api/users.ts` `_companyId?` параметр — удалён.** Реактивность scope в `useScopedResource` обеспечивается через `scope: Ref` параметр, не через сигнатуру loader'а — параметр был mock-ом для типов. Сигнатуры `fetchReports()` / `fetchUsers()` стали nullary. Соответствующие изменения в `ReportService.fetchReports / fetchAllReports`, `UserService.fetchCompanyUsers`, `useReportsPageData.fetchReports`, `useCompanyPageData.fetchUsers` — параметр `companyId` остался в сигнатурах page-composables (он нужен `useScopedResource.sync(companyId)` как scope-token), но в API-методах его больше нет.

#### Cosmetic

11. **`FilterPanel.vue` — удалён как orphan dead-code.** `grep -rn "FilterPanel" front/` нашёл только сам файл и упоминания в `AUDIT-08-05.md`. В `ReportPage/index.vue` логика рендера фильтров встроена inline (через `getFilterComponent(filterConfig.type)`), `FilterPanel` нигде не реэкспортируется и не lazy-import'ится. Удалил файл вместе с теперь-неиспользуемыми ключами `filterTitle`, `expand`, `collapse`, `apply`, `reset` из `components/filters/locale/{ru,en}.json` (остальные ключи — `selectMultiplePlaceholder`, `selectSinglePlaceholder`, `searchPlaceholder`, `dateFrom/dateTo`, `numberFrom/numberTo`, `textPlaceholder` — оставлены, ими пользуются `AsyncSelectFilter` / `SelectFilter` / `DateRangeFilter` / `TextFilter` / `NumberRangeFilter`).
12. **`OverlayBadge` — дубль `<Button>` объединён.** Шаблон `v-if/v-else` с двумя одинаковыми `<Button>` (только icon и tooltip отличаются по `filterCollapsed`) обёрнут в один `<Button>` под `<OverlayBadge :value="activeFiltersCount">` при `activeFiltersCount > 0`, иначе кнопка рендерится напрямую. Дубль свелся к одному template-блоку.
13. **`useReportPageData.fetchReport` — рекурсивный самовызов в явный двухэтапный flow.** Старый код звал `await fetchReport()` внутри `fetchReport()` для повторного запроса с defaults. Стало: `fetchReport()` делает один запрос; если в ответе впервые увидели `filters_available` и есть non-empty defaults — выставили `localFilters / currentFilters` и явно вызвали `applyDefaultsAndRefetch()`, которая сама зовёт `fetchReport()` ровно один раз. Поведение идентично, читается линейно.
14. **`notificationCenter` deep-import → barrel.** `stores/companies.ts` теперь импортит через `@/application` барреля, а не `@/application/notificationCenter`. (Фактически в #2 импорт удалён вообще — store больше не зовёт notificationCenter напрямую — но конвенция зафиксирована для будущих файлов).

Final state: vue-tsc 0 errors, lint clean (2 pre-existing warnings не наши). Журнал AUDIT-08-05.md обновлён — рядом с каждым исправленным пунктом стоит ✅ FIXED 2026-05-17.

---

## 2026-05-19 Fix iframe-auth on root path

- **Issue:** `POST /api/iframe-auth` on `/?token=...` succeeded (user authenticated, localStorage filled) but `app.mount` stuck on empty grey screen due to race between the router-level redirect `{ path: '/', redirect: '/reports' }` (`router/routes/base.ts`) and bootstrap's `router.push(getDefaultRouteForCurrentUser(...))` on `requestedPath === '/'`.
- **Fix:** `bootstrapApp.ts` → `redirectAfterAuthenticatedBootstrap` — added branch `if (request.iframeToken) { await router.replace(resolveIframeLoginTarget(request, userStore)) }` immediately after the existing `/login`-specific branch and before the generic `requestedPath === '/'` fallback. Any path that arrives with an iframe token now goes through `resolveIframeLoginTarget` consistently (previously only handled for `/login`).
- **Effect:** `router.replace('/reports')` jumps straight to the resolved target, sidestepping the intermediate `/` redirect entirely. `/reports?token=...` path is unaffected (iframeToken branch still resolves to `/reports` via `parseRedirectTarget(requestedPath)`).
- **Smoke:** vue-tsc clean, vite build OK, lint clean (2 pre-existing warnings unchanged). Local browser smoke skipped — no host dev server up; will be validated by qa-tester on dev after deploy.

---

## 2026-05-19 Fix iframe-auth on root path — round 2 (real root cause)

- **Why round 2:** Previous fix did NOT solve the redirect. User confirmed `/?token=...` still does not redirect to `/reports` after deploy.
- **Actual root cause:** `resolveIframeLoginTarget(request, userStore)` returns `'/'` (not `/reports`) for `/?token=...`. Trace:
  - `requestedPath` is `'/'` after the bootstrap strips `?token=...` from the URL.
  - `parseRedirectTarget('/')` → `{ iframeToken: null, sanitizedTarget: '/' }` (only `/login` returns `null` sanitizedTarget; root path returns `'/'`).
  - `'/'` is a truthy string so the `??` chain stops there and never falls through to `getDefaultRouteForCurrentUser(userStore)` (which would return `'/reports'` for analyst/viewer or `'/company'` for admin/superadmin).
  - The previous "fix" routed the iframe path through this function, but the function itself was producing `'/'` — so `router.replace('/')` re-enters the route-table redirect `{ path: '/', redirect: '/reports' }`, which fires `beforeEach` → `waitForBootstrapSession()` → deadlocks against the in-flight bootstrap promise. `router.isReady()` never resolves → `app.mount('#app')` never runs → grey screen.
- **Real fix:** `bootstrapApp.ts` → introduce `sanitizeRealTarget(target)` helper: returns `null` for falsy or `'/'`, otherwise returns the target as-is. Wrap both `redirectFromUrl?.sanitizedTarget` and `parseRedirectTarget(request.requestedPath)?.sanitizedTarget` with `sanitizeRealTarget(...)`. Now the `??` chain treats `'/'` as "no real target" and falls through to `getDefaultRouteForCurrentUser(userStore)` → `'/reports'` for analyst.
- **Why this is the right shape of fix:** `'/'` is never a meaningful destination — it always bounces back through the route table to whichever role default the user has. Hard-coding `'/' === no target` at the resolver layer is symmetric with the existing `/login === null` handling in `parseRedirectTarget` (`sanitizedTarget` is intentionally `null` for `/login` so it doesn't redirect-loop). The root path deserves the same treatment for the same reason.
- **Trace logs added (temporary, marked `TODO(2026-05-19): remove after verifying iframe auth redirect in prod console`):**
  - `[bootstrap] redirectAfterAuthenticatedBootstrap entry { hasIframeToken, normalizedPathname, requestedPath, userRole, isAuthenticated }`
  - `[bootstrap] branch: iframeToken + /login → router.replace <target>` / `[bootstrap] branch: iframeToken → router.replace <target>` / `[bootstrap] branch: requestedPath=/ → router.push <target>`
  - `[bootstrap] resolveIframeLoginTarget { requestedPath, normalizedPathname, redirectFromUrl, parsedFromRequestedPath, userRole, resolvedTarget }`
  - User will see these in DevTools Console immediately after `/?token=...` is opened post-deploy. Expected output for analyst: `resolvedTarget: '/reports'`, branch log `iframeToken → router.replace /reports`.
- **Smoke:** vue-tsc clean (0 errors), vite build OK, lint clean (1 pre-existing warning in `plugins/persist.ts:91`, not ours). Local browser smoke skipped — no live token to play through. Validation will be done by user observing the new console logs after deploy.
- **Cleanup:** After user confirms redirect works, remove the three `console.log` blocks and the `TODO(2026-05-19)` comments.

---

## 2026-05-19 Fix iframe-auth on root path — round 3 (real real root cause: bootstrap/router deadlock)

- **Why round 3:** Round 2 fixed `resolveIframeLoginTarget` so it returns `'/reports'` instead of `'/'` — console logs confirmed this works. BUT the page is still grey: `router.replace('/reports')` is called, browser URL stays at `/`, app never mounts. The fix that resolved one symptom uncovered the original symptom (deadlock) that round 1's symptom (`'/'` redirect loop) was masking.
- **Actual actual root cause:** bootstrap awaits `router.replace(...)` inside the very promise that the router's `beforeEach` guard is waiting on:
  1. `main.ts` calls `bootstrapApp(...)` → assigns the resulting promise to both `bootstrapPromise` and `setBootstrapSessionPromise(...)`.
  2. Inside `bootstrapApp` → `redirectAfterAuthenticatedBootstrap` does `await router.replace('/reports')`.
  3. `router.replace` triggers `beforeEach` (`router/index.ts:41`) which calls `await waitForBootstrapSession()` (`sessionGate.ts:7-13`).
  4. `waitForBootstrapSession` awaits `bootstrapSessionPromise` — i.e. the very promise that is currently `await`ing `router.replace`.
  5. Deadlock. `router.isReady()` never resolves → `main.ts:106 await router.isReady()` → `app.mount('#app')` never fires → grey screen.
  - This was masked previously because for `/reports?token=...` the iframe branch resolves to `/reports` which matches the URL → vue-router short-circuits, no actual navigation, no guard, no deadlock. For `/?token=...` (or any path where the resolved target differs from the URL) the deadlock fires.
- **Real fix (variant A, fire-and-forget):** Removed `await` from all three `router.replace`/`router.push` calls inside `redirectAfterAuthenticatedBootstrap`, plus the one inside `handleAuthenticatedBootstrapError`. `bootstrapApp` now returns immediately after dispatching the navigation; the guard's `waitForBootstrapSession()` then unblocks, navigation completes on its own, `router.isReady()` resolves, `app.mount('#app')` runs.
- **Why variant A and not B (pendingTarget plumbed through state) or C (guard short-circuit):**
  - Variant B requires plumbing `pendingRedirectTarget` through a store/module var and calling `router.replace(target)` from `main.ts` after `app.mount` — more code, more coupling, and we'd still need to think about what happens to the guard during `app.mount`'s initial navigation.
  - Variant C (teach the guard to detect "in-bootstrap navigation" and skip waiting) is fragile — depends on identifying which navigations originated from bootstrap, and silently bypassing the guard is a footgun for future code.
  - Variant A is two-line surgical and the right semantic shape: bootstrap's job is to prepare state and dispatch the initial route, not to babysit the navigation. The guard already correctly waits for bootstrap; bootstrap shouldn't wait for navigation that waits for bootstrap.
- **Diff (semantic):**
  - `bootstrapApp.ts` → 3× `await router.replace(target).catch(() => {})` → `router.replace(target).catch(() => {})`; 1× `await router.push(target).catch(() => {})` → `router.push(target).catch(() => {})` (in `redirectAfterAuthenticatedBootstrap`).
  - `bootstrapApp.ts` → 1× `await router.push('/login').catch(() => {})` → `router.push('/login').catch(() => {})` in `handleAuthenticatedBootstrapError` (same deadlock potential for the unauthorized-during-bootstrap path).
  - Added a multi-line `IMPORTANT:` comment block above the first branch explaining the deadlock and why navigations must not be awaited.
  - Added `console.log('[bootstrap] router.replace dispatched (fire-and-forget)')` (or `.push`) after each dispatch — gives the user a clear console signal that bootstrap exited cleanly after firing the nav. Kept previous TODO-marked logs.
- **Trace through 4 scenarios:**
  1. `/?token=X` (baseline bug) — iframe POST OK → URL rewritten to `/` → iframe branch resolves target to `/reports` (round 2 fix) → fire-and-forget `router.replace('/reports')` → bootstrap returns → guard unblocks → navigation completes → mount on `/reports`. **FIXED.**
  2. `/reports?token=X` (was working, still works) — same flow, target = `/reports`. Vue-router dedupes the `replace('/reports')` against the initial URL or runs it through the same unblocked guard. Mount on `/reports`. **OK.**
  3. `/` no token, unauthenticated — `authenticateWithIframeToken` no-op → `initializeSession` anonymous → `redirectAfterAuthenticatedBootstrap` NOT called (guarded on `isAuthenticated`) → bootstrap returns clean → router initial nav to `/` → route table redirect `/ → /reports` → guard sees `requiresAuth + !isAuthenticated` → `buildLoginRedirect('/reports')` → mount on `/login?redirect=/reports`. **OK.**
  4. `/` post-login via UI — bootstrap already finished long ago; login page calls `router.push(getDefaultRoute(user.role))` synchronously after `userSessionService.login(...)`. No bootstrap in flight, no deadlock. Mount on `/reports`. **OK.**
- **Potential regressions:** None identified. The only behavior change is that `bootstrapApp` no longer waits for the navigation it dispatches. Anything downstream that previously relied on "after `bootstrapApp` resolves the URL has already been replaced" would break, but `main.ts` only awaits `bootstrapPromise` to then call `await router.isReady()` — which still waits for the navigation, just via a different path (vue-router's own readiness instead of an explicit await in bootstrap). Same effective ordering at `app.mount`.
- **Did NOT remove the round-2 `sanitizeRealTarget` or other TODO-2026-05-19 console.logs** — they're still useful to confirm the fix in prod console. Cleanup pass (all three log groups + comment + `sanitizeRealTarget` rationale comment trimmed) deferred until user confirms scenario 1 works after deploy.
- **Smoke:** vue-tsc clean (0 errors), vite production build OK (`✓ built in 7.74s`), lint clean (1 pre-existing warning in `plugins/persist.ts:91`, not ours). Local browser smoke skipped — needs live token from the iframe-issuer. Main-session will commit + push; qa-tester validates after dev autodeploy.

---

## 2026-05-20 GenerateReportTile — Fixup #3 (a11y focus-indicator)

- **What:** `GenerateReportTile.vue` — split `:hover` and `:focus-visible` (previously sharing one block with `outline: none`). `:focus-visible` now has explicit `outline: 2px solid $danger; outline-offset: 2px;` (brand red, matches the tile's red theme). Hover behavior unchanged.
- **Why:** keyboard users hitting Tab onto the tile got hover-styling (translateY + shadow) but no system focus-ring — borderline WCAG 2.1 AA. Explicit outline is unambiguous.
- **Smoke:** vue-tsc clean, vite production build OK (`✓ built in 8.15s`).

---

## 2026-05-24 — Удалён grouped/drill-down ВИД со страницы отчёта

**Контекст / задача владельца:** страница отчёта `/reports/:id` должна ВСЕГДА рендерить плоскую таблицу (как «Свод по проектам»). Grouped-вид (раскрывающиеся группы с шапками-агрегатами и кнопкой «Развернуть группу» + lazy drill-down дочерних строк) полностью убран из UI. Колонки / итоговая строка / dashboard / фильтры / preferences не затронуты — речь только про grouped-путь.

**Что сделано:**

- `pages/ReportPage/index.vue`:
  - Удалена grouped DataTable-ветка (master/detail expansion, expander column, `ReportGroupHeader`, expansion-слот с loader/error/children/load-more). Остался единственный flat-DataTable: `v-if="formattedTableData && formattedTableData.length > 0"` (раньше было `v-else-if="!isGrouped && ..."`).
  - Из `ColumnManagerPopover` v-if убран `&& !isGrouped`.
  - Удалены grouped-обработчики: `expandedRows` ref, `onRowExpand`/`onRowCollapse`, watch на `[currentFilters, currentSort]` с `resetAllGroupStates()`, watch на `meta.group_by`/`config.group_by` с `collapsed_by_default`, `getChildLinkRef`, `getChildBadge`.
  - Убран вызов `useReportGroupDrillDown` целиком (`getGroupState`, `loadMoreGroupRows`, `hasMore`, …).
  - Из destructure `useReportPresentation` убраны `isGrouped`, `groupRows`, `buildLinkRef`, `formatAggregate`, `formatGroupChildren`.
  - `useColumnOrder(reportId, tableColumns, isGrouped)` → передаётся стабильный `const columnOrderDisabled = ref(false)` (сигнатура composable `disableWhen: Ref<boolean>` сохранена, внутренности не трогали). Guards `if (isGrouped.value) return` убраны из `onColumnReorder` / `onColumnManagerReorder`; в `reorderableColumnsEnabled` убрана grouped-ветка.
  - Удалены импорты: `ProgressSpinner`, `ReportGroupHeader`, `useReportGroupDrillDown`, типы `LinkRefColumn` / `ReportTableRow` (стали неиспользуемыми).
  - Удалён мёртвый SCSS-блок `.group-expansion` (loader / error / load-more).

- `pages/ReportPage/composables/useReportPresentation.ts`:
  - Удалены `isGrouped`, `groupRows` computed'ы и helper'ы `formatAggregate`, `formatGroupChildren`, `resolveAggregateValue`. Из return убраны `isGrouped`, `groupRows`, `formatAggregate`, `formatGroupChildren`.
  - `isGroupRow` оставлен как **защитный** предикат (теперь возвращает `boolean`, не type-guard на `ReportGroupRow`). `tableData` фильтрует группо-строки (`group_key`/`group_meta`) из плоского датасета — если бэкенд вдруг вернёт grouped-rows, они отбрасываются, плоская таблица не ломается.
  - Импорт `ReportGroupRow` из `@/entities/report` убран.

- **Удалены файлы:**
  - `pages/ReportPage/composables/useReportGroupDrillDown.ts`
  - `pages/ReportPage/components/ReportGroupHeader.vue`

- **i18n:** удалён блок `group.*` (expand / collapse / items_count / overdue_count / load_more / loading_error) из `pages/ReportPage/locale/{ru,en}.json`. Проверено grep'ом — ключи `group.*` больше нигде не используются. RU↔EN симметрия сохранена (diff чист).

**Намеренно НЕ тронуто:**
- API-метод `reportsApi.fetchGroupRows` + DTO `ReportGroupRowDto` / `ReportGroupRow` в `api/types/reports.ts` / `entities/report/types.ts` — backend endpoint `/api/reports/{id}/group-rows` остаётся, типы низкоуровневые и безвредные. Убран только UI-потребитель.
- `buildLinkRef` остаётся внутренним helper'ом `useReportPresentation` (используется computed'ом `linkRefs` для flat-ссылок) — просто больше не экспортируется наружу в index.vue.

**Проверки:**
- `npm run type-check` — чисто (vue-tsc --build без ошибок).
- `npm run lint` — 0 errors, 1 warning (pre-existing `comma-dangle` в `src/plugins/persist.ts`, файл не трогали).
- i18n diff ru↔en — нет orphan-ключей, `group.*` отсутствует в обоих.

---

Frontend specialist on vacation. All front/ changes logged here.

## 2026-05-25 — Меню действий на дашборде + удаление виджетов из библиотеки

Две связанные фичи по образцу `ReportActionsMenu.vue`.

### Фича 1 — Меню «три точки» на странице дашборда

Кнопка `pi-ellipsis-v` в `header-right` страницы `/dashboards/:id`, рядом с «+ Добавить виджет» и `PeriodPicker`. По клику — Popover (по образцу ReportActionsMenu, без пункта «Редактировать с AI»):
- Инфо-блок: автор (name + email-tooltip), дата создания (`format(_, {type:'datetime'})`), статус (системный `Tag` info / опубликован `Tag` success / приватный `Tag` warn).
- «Добавить виджет» — дублирует header-кнопку (emit `add-widget` → `openLibrary`); показывается при `canAddWidget` (= `isEditable`).
- Publish / Unpublish — toggle по `is_published`; видим admin/superadmin, скрыт для системных. После toggle backend возвращает payload БЕЗ widgets → обновляем локальный `dashboard.isPublished` через emit `published-changed`.
- Удалить — owner / admin(компания) / superadmin, скрыт для системных и viewer (capability `canDeleteDashboard`). Confirm (PrimeVue `DeleteConfirmModal`) → `DELETE /api/dashboards/{id}` → `router.push('/dashboards')`.

### Фича 2 — Удаление виджетов из библиотеки

Кнопка `pi-ellipsis-v` в углу каждой карточки в `WidgetLibraryModal` (видна на hover/focus-within). Видна только для удаляемых виджетов: свой / admin(компания) / superadmin, скрыта для системных и viewer (capability `canDeleteWidget`). Пункт «Удалить» в Popover.
- Confirm-диалог: если `used_in_dashboards_count > 0` — плюрализованный текст «Виджет используется в N дашбордах. Удалить из всех и убрать?»; иначе обычное подтверждение.
- `DELETE /api/widgets/{id}?force=true` (force всегда — каскадный detach). При успехе — карточка убирается из списка (splice), toast. 409 обрабатывается gracefully (на случай гонки attach).
- Card emit `delete` → `WidgetLibrarySection` → `WidgetLibraryModal` (модалка владеет confirm + API + рефрешем списка).

### Файлы
- `front/src/api/types/dashboards.ts` — `DashboardDto`: добавлены `created_at`/`updated_at`; `widgets` сделан опциональным (publish/unpublish возвращают payload без widgets).
- `front/src/entities/dashboard/{types,mappers}.ts` — `Dashboard`: `createdAt`/`updatedAt`.
- `front/src/api/dashboards.ts` — `publishDashboard` / `unpublishDashboard` (POST .../publish|unpublish).
- `front/src/api/widgets.ts` — `deleteWidget(id, {force})` → `?force=true` query.
- `front/src/services/DashboardService.ts` — `publishDashboard` / `unpublishDashboard`.
- `front/src/services/WidgetService.ts` — `deleteWidget(id, {force})` проброс.
- `front/src/shared/auth/capabilities.ts` — добавлен `canManageDashboardPublication` (alias логики `canPublishDashboard`, имя симметрично `canManageReportPublication`); `canDeleteDashboard` / `canDeleteWidget` уже существовали — переиспользованы.
- `front/src/pages/DashboardPage/components/DashboardActionsMenu.vue` — НОВЫЙ компонент.
- `front/src/pages/DashboardPage/index.vue` — смонтирован `DashboardActionsMenu` + handler `onPublishedChanged`.
- `front/src/pages/DashboardPage/components/WidgetPreviewCard.vue` — три точки + Popover «Удалить», capability-гейт, emit `delete`.
- `front/src/pages/DashboardPage/components/WidgetLibrarySection.vue` — проброс emit `delete`.
- `front/src/pages/DashboardPage/components/WidgetLibraryModal.vue` — confirm-диалог удаления + flow (force, splice, toast, 409-guard).
- `front/src/pages/DashboardPage/components/locale/{ru,en}.json` — namespace `actionsMenu.*` (дашборд) + `library.cardMenu/delete/delete_confirm_*/toast_*`.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто (кроме pre-existing `persist.ts:91` comma-dangle, вне зоны правки).
- i18n RU↔EN — симметрия по `components/locale` подтверждена скриптом (0 ключей без пары).

### Риски
- `DashboardActionsMenu` рендерится внутри `v-else-if="dashboard"`, проп `:dashboard` non-null в этой ветке (vue-tsc принял narrowing).
- Backend-контракт `created_at` в show-ответе дашборда предполагается готовым — если поле не приходит, строка «Создан» просто не отрисуется (guard на falsy).
- Карточки виджетов: `@click.stop` на три-точки и `@keydown.enter/space.stop` на кнопке защищают от случайного pick при работе с меню.

---

## Итерация 2026-05-25 — пачка правок по отчётам (по явной просьбе владельца)

Зона — `front/`. Backend-контракты (per-user order, `PUT /api/reports/order`, top-level `totals`, `is_crm_id`, дефолт per_page=100) уже реализованы и работают локально.

### #1 — Лоадер async-фильтров
- `front/src/components/filters/AsyncSelectFilter.vue` — добавлен `#empty` слот для Select и MultiSelect: пока запрос опций в полёте (или до первого ответа эндпоинта) показывается `ProgressSpinner` + «Загрузка...», «Нет доступных опций» — только ПОСЛЕ завершения запроса. Флаг `firstLoadComplete` (set в `finally` загрузки, reset при смене `search_endpoint`); computed `showLoadingState = loading || !firstLoadComplete`. Добавлен импорт `primevue/progressspinner` + scoped-стиль `.async-select-loading`.
- `front/src/components/filters/locale/{ru,en}.json` — `loadingOptions`, `noOptions`.

### #5 — Формат дат dd.mm.yyyy
- `front/src/composables/useFormatter.ts` — `formatDate()` теперь собирает строку детерминированно через `formatToParts` (база `en-GB` как стабильный числовой источник) → всегда `dd.mm.yyyy` (+ `HH:mm` для datetime, 24h), независимо от UI-локали (раньше `en-US` дал бы `mm/dd/yyyy`). Таймзона по-прежнему учитывается. Дополнительно: в `format()` ветка `date/datetime` поднята ВЫШЕ ветки `isNumeric` — ISO-дата `2026-05-12` (только цифры+дефисы) раньше ловилась как numeric и ломалась.

### #9 — Дефолт пагинации 100
- `front/src/pages/ReportPage/composables/reportPageState.ts` — `DEFAULT_ROWS_PER_PAGE` 50 → 100.
- `front/src/pages/ReportPage/index.vue` — `rowsPerPageOptions` `[25,50,100]` → `[25,50,100,200]`; fallback `|| 50` → `|| 100` в Paginator.

### #2 — Drag-n-drop порядка отчётов
- `front/src/api/types/reports.ts` — `UpdateReportsOrderRequest` / `UpdateReportsOrderResponse`.
- `front/src/api/types/index.ts` — реэкспорт.
- `front/src/api/reports.ts` — `updateReportsOrder(order): PUT /api/reports/order`.
- `front/src/services/ReportService.ts` — `updateReportsOrder(order): Promise<number[]>` (возвращает подтверждённый бэком порядок).
- `front/src/pages/ReportsPage/composables/useReportsPageData.ts` — `reorderReports(orderedIds)`: оптимистично переставляет `reports.value`, шлёт PUT, на ошибке откатывает + toast. Guard: применяется только если переставленный список покрывает все текущие отчёты.
- `front/src/pages/ReportsPage/index.vue` — список обёрнут в `<draggable>` (vuedraggable, уже в проекте — эталон `ColumnManagerPopover.vue`); `display:contents` на draggable, грид на `.reports-grid-wrap`; `GenerateReportTile` вынесен из draggable (всегда последний, не перетаскивается). `onReorderChange` вычисляет новый id-порядок из `moved.oldIndex/newIndex`. Ghost/chosen-классы + grab-курсор.
- `front/src/locales/{ru,en}.json` — `errors.reorderFailed` (в глобальный `errors`-блок, т.к. `useLocalI18n` мержит shallow — локальный `errors` затёр бы `networkError`).

### #4 — Столбец ID + иконка внешней ссылки (is_crm_id)
- `front/src/api/types/reports.ts` — `ReportColumnDto.is_crm_id?: boolean`.
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `PresentationColumn.is_crm_id` + проброс в маппинге колонок.
- `front/src/pages/ReportPage/index.vue` — для `col.is_crm_id` link-ячейка рендерит ID-значение + маленькую `pi-external-link` (top-right, `<a target=_blank>`, keyboard-focusable, tooltip «Открыть в CRM»). href берётся из существующего link-механизма (`getLinkRefByField`). Scoped-стиль `.crm-id-cell`.
- `front/src/pages/ReportPage/locale/{ru,en}.json` — `openInCrm`.

### #6/#7/#10b — Строка ИТОГО для не-currency колонок
- `front/src/pages/ReportPage/composables/useReportPresentation.ts` — `formattedTotalsRow` различает семантику по типу колонки: `link`/`string`/(нет колонки) → COUNT через новый хелпер `formatCountTotal` (голое целое, без группировки и валюты, напр. `1293`); `number`/`area` → sum через `format()` (группировка/округление площади); `currency` → money (как было). `footerCells` уже распространял footer на все колонки — теперь он наполняется и для link/text.

### #11 + #10a — Ключевые фильтры в хедере отчёта
- `front/src/pages/ReportPage/index.vue` — под заголовком (вне панели фильтров) computed `headerFilterSummary`: date_range → «С dd.mm.yyyy ПО dd.mm.yyyy» (один чип, без лейбла; relative-токены `today`/`-N days` резолвятся как в DateRangeFilter); одиночные entity-фильтры (select → лейбл из options; async_select single / text → raw value) → чип «лейбл: значение». Multiselect и number_range намеренно пропущены. Заголовок обёрнут в `.report-title-block`; стили `.report-header-filters`.
- `front/src/pages/ReportPage/locale/{ru,en}.json` — `headerFilters.{dateRange,dateFrom,dateTo}`.

### #13 — Шестерёнка в тулбаре
- `front/src/components/ProfileMenu/ProfileMenu.vue` — в compact (Toolbox) режиме аватар-инициал заменён на глиф `pi pi-cog`; в развёрнутом меню инициал сохранён. Поведение/меню не тронуты.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто (кроме pre-existing `persist.ts:91` comma-dangle, вне зоны правки).
- i18n RU↔EN — симметрия по `filters/locale`, `ReportPage/locale`, глобальным `locales` подтверждена скриптом (0 ключей без пары).

### Риски / места где контракт мог не сойтись
- **#11 async_select / text лейблы:** в applied-фильтрах хранится только raw value (id), не label. Для async_select single чип покажет id, а не человекочитаемое имя контрагента — резолвинг лейбла потребовал бы доп. запрос или хранения выбранного лейбла в state отчёта. Если для «Актов сверки» нужен именно человекочитаемый контрагент — нужен backend-лейбл в applied-фильтрах либо отдельный resolve (флагую).
- **#6 count vs sum:** различение опирается на тип колонки (link/text → count, number/area → sum). Если бэк отдаст `totals` для колонки, которой нет в `columns` (column == null), она трактуется как count. Для declared-колонок это корректно.
- **#5:** все date/datetime отчётные колонки теперь dd.mm.yyyy в обеих локалях. Графики (`chartFormatters.ts`) НЕ тронуты (там свой long-формат для осей).
- **#2 drag:** vuedraggable формально в стоп-листе агента, но уже активно используется в `ColumnManagerPopover.vue` — переиспользован тот же подход, новых зависимостей не добавлено. Клик по карточке (open) сохраняется — sortable перехватывает только drag-жест.

---

## Итерация 2026-05-25 (вторая) — фиксы по фидбеку владельца + QA

Зона — `front/`. Два фикса: #4 (вся ячейка ID кликабельна) и #11 (человекочитаемый контрагент в хедере — закрывает FAIL и ранее зафлаганный риск #11 из первой итерации 25.05).

### #4 — Кликабельна ВСЯ ячейка ID (не только иконка)
- `front/src/pages/ReportPage/index.vue` — для `col.is_crm_id` link-ячейка теперь оборачивает ВЕСЬ контент в `<a target="_blank">` (класс `.crm-id-cell--link`), а не только иконку. Клик в любом месте ячейки открывает CRM-объект. Маленькая `pi-external-link` (`.crm-id-cell__icon`) осталась как визуальный маркер ВНУТРИ `<a>`. Когда href не резолвится — рендерится plain `<span class="crm-id-cell">` без ссылки. Tooltip «Открыть в CRM» переехал на сам `<a>`.
- SCSS: `.crm-id-cell--link` наследует цвет body-текста (ID читается как обычное значение, а не синяя ссылка); тинтуется только иконка; hover → underline + accent на иконке; `:focus-visible` outline. Старый `.crm-id-cell__link` (icon-only `<a>`) удалён. Внутри `:deep()` использован явный `.crm-id-cell__icon` (без BEM-вложенности `&__icon`) — иначе Sass-блокер сборки, как в прошлой итерации.

### #11 — Человекочитаемый контрагент в хедере «Акты сверки» (закрытие QA FAIL)
Корень FAIL: в applied-фильтрах хранится только opaque id, человекочитаемый лейбл async_select жил ТОЛЬКО внутри `AsyncSelectFilter` (`selectedLabel`). Хедер показывал raw id (или пусто). Резолв:
- `front/src/components/filters/AsyncSelectFilter.vue` — новый emit `update:selectedLabel: [value, label]` (только single-select). Эмитится: (1) в `onSelect` при выборе пользователем; (2) в `commit`-колбэке `loadOptions` при поздней резолюции лейбла для значения, выставленного извне (backend default / восстановленный фильтр). Multi-select не эмитит (хедер пропускает массивы).
- `front/src/pages/ReportPage/composables/useReportPageData.ts` — новый ref `asyncSelectLabels: Record<field, label>` (кэш лейблов async_select single), добавлен в return и в вызов `resetReportPageState`.
- `front/src/pages/ReportPage/composables/reportPageState.ts` — `asyncSelectLabels` в `ResetReportPageStateOptions` + сброс `{}` при смене route.params.id.
- `front/src/pages/ReportPage/composables/useReportPageActions.ts` — action `setAsyncSelectLabel(field, label)` (delete при пустом лейбле); `resetFilters` очищает `asyncSelectLabels`.
- `front/src/pages/ReportPage/composables/useReportPage.ts` — проброс `asyncSelectLabels` в `useReportPageActions`.
- `front/src/pages/ReportPage/index.vue` — `@update:selected-label` на dynamic filter-component → `setAsyncSelectLabel(field, label)`; в `headerFilterSummary` ветка `async_select` читает `asyncSelectLabels.value[field] ?? String(rawValue)` (fallback на id только если лейбл ещё не резолвлен). `select`-ветка не тронута (уже резолвит через `resolveSelectLabel` по options). Чип показывает «{лейбл фильтра}: {имя контрагента}». Date_range-логика не тронута.

**Как резолвится лейбл контрагента:** `select` → из `config.options` по value (как было). `async_select` → лейбл выбранной опции, который `AsyncSelectFilter` уже держит во внутреннем `selectedLabel`; поднят наружу через новый emit `update:selectedLabel` и закэширован в `asyncSelectLabels` на уровне состояния отчёта. Хедер читает кэш реактивно. Никаких доп. сетевых запросов — лейбл берётся из той же опции, которую пользователь выбрал в дропдауне.

### i18n
- Новых ключей НЕ добавлено. #4 переиспользует `openInCrm`; #11 — лейбл фильтра берётся из `config.label` отчёта (не из vue-i18n), имя контрагента приходит из данных. RU↔EN симметрия не затронута.

### Проверки
- `npm run type-check` — чисто (vue-tsc --build без ошибок).
- `npm run lint` — 0 errors, 1 warning (pre-existing `persist.ts:91` comma-dangle, файл не трогали).
- `npm run build` — успешно (SCSS-блокера нет, `:deep(.crm-id-cell__icon)` явный).

### Риски
- **#11 поздняя резолюция:** если фильтр восстановлен/задефолчен и пользователь НЕ открывал дропдаун, лейбл резолвится только когда `loadOptions` отработает (при первом открытии дропдауна) → до этого чип покажет id. Для основного flow (пользователь сам выбирает контрагента) лейбл доступен сразу из `onSelect`. Для «Актов сверки» с ручным выбором — покрыто.
- **#4:** для строк без резолвнутого href ячейка рендерится как plain span (не ссылка) — поведение корректное, иконка не показывается.

---

## Итерация 2026-05-25 (третья) — #4 round 2: вся ПЛОЩАДЬ ячейки ID кликабельна (overlay)

Зона — `front/`. Повторный фикс #4 после QA-FAIL: предыдущий заход обернул контент в `<a>`, но ссылка была `display: inline-flex` и shrink-wrap'нута к тексту — занимала ~67×17px из ячейки ~95×68px. Клик в пустую зону (углы/паддинг) НЕ открывал CRM — `elementFromPoint` в правом-нижнем углу возвращал голый `<td>`, а не ссылку.

### Выбран вариант A (overlay)
Чище в текущей разметке: видимое содержимое ссылки остаётся компактным (ID слева, иконка-маркер top-right), а кликабельная зона расширяется псевдоэлементом `::after` до границ всего `<td>`.

### Изменения
- `front/src/pages/ReportPage/index.vue`
  - **Template:** на `<Column>` добавлен `:body-class="col.is_crm_id ? 'crm-id-cell-td' : undefined"` — PrimeVue 4.5 вешает этот класс на `<td>` колонки (prop `bodyClass`, подтверждён в `primevue/column/index.d.ts`). На wrapper-`<div v-else>` добавлен класс `crm-id-cell-wrapper` для `col.is_crm_id`. Разметка `<a>` / `<span>` не менялась.
  - **SCSS:**
    - `:deep(.crm-id-cell-td) { position: relative }` — td становится positioning-anchor для overlay.
    - `:deep(.crm-id-cell-wrapper) { display: block }` — wrapper не shrink-wrap'ится (не обязательно для overlay, но убирает inline-зазоры).
    - `:deep(.crm-id-cell--link) { &::after { content:''; position:absolute; inset:0; z-index:1 } }` — overlay покрывает весь td. `<a>` и wrapper остаются `static`, поэтому `::after` ссылки якорится к ближайшему positioned-предку = `<td>` (`.crm-id-cell-td`). `inset:0` строго внутри td → не перехватывает клики соседних ячеек/строк.
    - `.crm-id-cell__icon` переведена в `position:absolute; top:.15rem; right:.15rem; z-index:2; pointer-events:none` — пиннится в правый верхний угол ячейки как маркер, рисуется ПОВЕРХ overlay (z-index:2), но `pointer-events:none` => не крадёт клик у overlay. Значение ID остаётся слева в потоке.

### Почему теперь покрыта вся площадь
`elementFromPoint` в любой точке td (включая углы/паддинг) попадает на `::after` ссылки (`z-index:1` над фоном td) → клик роутится на `<a target="_blank">` → CRM открывается в новой вкладке. Иконка `pi-external-link` рисуется поверх (`z-index:2`), но прозрачна для кликов (`pointer-events:none`). Sort на заголовке (`<th>`) и hover строки (`<tr>`) не затронуты: overlay живёт только в `<td>` тела, на header не распространяется; transparent `::after` не мешает hover-фону строки.

### Проверки
- `npm run type-check` — чисто (vue-tsc --build без ошибок).
- `npm run lint` — 0 errors, 1 warning (pre-existing `persist.ts:91` comma-dangle, файл не трогали).
- `npm run build` — успешно. В собранном CSS подтверждено: `.crm-id-cell-td{position:relative}`, `.crm-id-cell--link:after{content:"";position:absolute;inset:0;z-index:1}`, `.crm-id-cell__icon{position:absolute;top:.15rem;right:.15rem;z-index:2;...;pointer-events:none}`. SCSS-блокера нет — внутри `:deep()` использованы явные классы (`.crm-id-cell__icon`), без `&--`/`&__` BEM-вложенности.

### Риски
- Overlay `inset:0` строго внутри td — соседние ячейки/строки не перехватываются. Подтверждено компилированным CSS.
- Для строк без резолвнутого href рендерится plain `<span class="crm-id-cell">` (без `--link`, без overlay, без иконки) — клика нет, корректно.

---

## Итерация 2026-05-25 (четвёртая) — #4 round 3: вся ПЛОЩАДЬ ячейки ID кликабельна (stretched `<a>`)

Зона — `front/`. Повторный фикс #4 после второго QA-FAIL подряд. Round 2 ставил overlay-псевдоэлемент `::after { inset:0 }` на `<a>` (`inline-flex`), рассчитывая что он якорится к `<td>` с `position:relative`. На практике углы/пустые зоны ячейки по-прежнему возвращали голый `<td>` при `elementFromPoint` — overlay не покрывал весь td.

### Выбранный подход (альтернатива из ТЗ, не overlay)
Растягиваю **сам `<a>`** на весь `<td>`, без псевдоэлемента-посредника. Так `elementFromPoint` в ЛЮБОЙ точке td возвращает физически сам `<a>`, а не пустоту над фоном.
- `<td>` (`crm-id-cell-td`) — positioning-context (`position:relative`) + `padding:0 !important` (обнуляем cell-паддинг, чтобы якорь дотягивался до буквальных углов; паддинг возвращается внутрь якоря).
- wrapper-`<div>` (`crm-id-cell-wrapper`) — `display:contents`, схлопывается, чтобы `<a>` и spacer стали прямыми layout-детьми `<td>`; тогда `inset:0` якоря резолвится против самого td.
- `<a class="crm-id-cell--link">` — `position:absolute; inset:0; display:flex` → физически заполняет td corner-to-corner. Паддинг `.5rem .75rem` возвращён внутрь якоря (часть кликабельной зоны). Это и есть hit-target.
- Иконка `.crm-id-cell__icon` — `position:absolute; top/right:.15rem; pointer-events:none` (маркер top-right, клик не крадёт).

### Защита от схлопывания строки
`<a>` теперь `position:absolute` (вне потока) → у `<td>` не остаётся in-flow контента для высоты. Если CRM-ячейка окажется самой высокой в строке, она бы схлопнулась в 0. Добавлен **in-flow spacer** `<span class="crm-id-cell-spacer" aria-hidden>` — невидимая (`visibility:hidden; pointer-events:none`) копия значения с тем же паддингом/line-height. Держит реальную высоту td независимо от соседних ячеек. Якорь никогда не схлопывается.

### Изменённые файлы
- `front/src/pages/ReportPage/index.vue`
  - **Template:** для `col.is_crm_id` + резолвнутый href — рендерится `<span class="crm-id-cell-spacer">` (height-spacer) + `<a class="crm-id-cell crm-id-cell--link">` (значение + иконка). `:body-class="...'crm-id-cell-td'"` и wrapper-класс `crm-id-cell-wrapper` сохранены. Fallback `<span class="crm-id-cell">` (без href) не менялся.
  - **SCSS (`:deep()`):** `crm-id-cell-td { position:relative; padding:0 !important }`; `crm-id-cell-wrapper { display:contents }`; новый `crm-id-cell-spacer { display:block; padding:.5rem .75rem; visibility:hidden; pointer-events:none }`; `crm-id-cell--link { position:absolute; inset:0; display:flex; padding:.5rem .75rem }`; `crm-id-cell:not(.crm-id-cell--link) { display:block; padding:.5rem .75rem }` (fallback восстанавливает паддинг, обнулённый на td). Иконка осталась absolute top-right + `pointer-events:none`. Старый `&::after`-overlay удалён. BEM-суффиксы внутри `:deep()` — явные (`.crm-id-cell__icon`), без `&--`/`&__` вложенности (Sass-блокер сборки).

### Почему теперь весь td кликабелен
td → `position:relative`, `padding:0`. `<a>` → `position:absolute; inset:0` → заполняет content-box td = весь td (паддинга нет). `elementFromPoint` в любой точке (углы, пустые поля) попадает на сам `<a>` (он рисуется поверх in-flow spacer, spacer всё равно `pointer-events:none`). Иконка `pointer-events:none` → клик на её месте тоже идёт на `<a>`. Sort заголовка (`<th>`) и hover строки не затронуты — якорь живёт только в body-`<td>`, прозрачного overlay над фоном строки больше нет.

### Проверки
- `npm run type-check` — без ошибок.
- `npm run build` — успешно, SCSS-блокера нет.

### Риски
- `<a>` вне потока: высоту td держит in-flow spacer (копия значения). Без spacer'а CRM-ячейка могла бы схлопнуть строку, если бы стала самой высокой. Spacer закрывает этот сценарий.
- `padding:0 !important` на td — точечно по `crm-id-cell-td`, только для CRM-колонки; паддинг возвращён внутрь якоря и fallback-спана, выравнивание ID с соседними колонками сохранено.

---

## 2026-05-25 — Колонки с одинаковым `field` схлопывались в один заголовок («ID объекта»)

### Симптом
В отчётах Реестр договоров / Ежедневник / Дебиторка / Акты сверки колонки «Номер договора», «Номер объекта» и «ID объекта» рендерились ВСЕ с заголовком «ID объекта» (2-3 дубля), значения тоже схлопывались — хотя API отдаёт их с разными заголовками.

### Root cause
Несколько колонок имеют ОДИНАКОВЫЙ `field` (`estateSells.estate_sell_id` — у «Номер договора» [label_field=agreement_number], «Номер объекта» [label_field=geo_flatnum], «ID объекта» [is_crm_id]). Весь column-order / visibility / link-ref / footer-align flow строил `Map` по ключу `field` → хранилась только ПОСЛЕДНЯЯ колонка → `byField.get(field)` для всех трёх возвращал одну (последнюю). `<Column>` v-for рендерил три одинаковых заголовка, link-refs тоже резолвились к последней колонке.

### Решение — стабильный уникальный `_key`
Введён синтетический ключ колонки `_key = ${index}|${field}|${label_field ?? ''}`:
- ведущий config-index гарантирует уникальность даже при коллизии `field` + `label_field`;
- field + label_field делают ключ читаемым и дают back-compat-маппингу значение для сопоставления;
- детерминирован для данного конфига (порядок колонок стабилен) → persisted-порядок воспроизводится между загрузками идентично.

Весь column flow переведён с `field` на `_key`: построение, ordering, visibility, drag-n-drop, рендер `<Column>` key, link-ref lookup, footer-выравнивание, draggable item-key.

### Back-compat persisted-префов
Старые префы (по `field`) при загрузке мягко маппятся: если сохранённый id == `_key` колонки → применяется; если == `field` и `field` уникален (нет коллизии) → транслируется в её `_key`; при коллизии/несовпадении → запись игнорируется (колонки падают в дефолтный config-порядок, без падений). Новые сохранения (reorder / toggle visibility) всегда персистят `_key` и нормализуют `hidden` в текущие `_key`.

### Изменённые файлы
- `front/src/pages/ReportPage/composables/useReportPresentation.ts`
  - `PresentationColumn._key: string` + `FooterCell._key: string`; helper `buildColumnKey(index, field, labelField)`. `_key` присваивается в обеих ветках `tableColumns` (config-колонки + fallback из row-keys) и в `footerCells`. `tableStateKey` → по `_key`.
- `front/src/pages/ReportPage/composables/useColumnOrder.ts`
  - `orderedFields` → `orderedKeys` (Map по `_key`); `displayColumns` `byKey`. Helper `normalizePersistedId()` (back-compat field→key). `hiddenFields` резолвит legacy/новые id в текущие `_key`; `setColumnVisibility(key, ...)`; `applyReorder` оперирует `_key` и персистит нормализованный `hidden`. Helper `currentHiddenKeys()`.
- `front/src/pages/ReportPage/index.vue`
  - `<Column :key>` → `_key`; `visibleTableColumns` фильтр по `_key`; `onColumnReorder` findIndex по `_key`; bulk/single visibility передают `_key`; `originalColIndexByField`→`originalColIndexByKey` + `getLinkRefByKey(col._key)`; `visibleFooterCells` align `byKey` + `<Column :key="cell._key">`.
- `front/src/pages/ReportPage/components/ColumnManagerPopover.vue`
  - draggable `item-key="_key"`, чекбоксы/тоггл/bulkState по `_key`, emit `toggle-column` теперь `_key`.

### Проверки
- `npm run type-check` — без ошибок.
- `npm run lint` — 0 errors (1 pre-existing warning в `src/plugins/persist.ts`, не наш файл).
- `npm run build` — успешно.

### Риски
- Сырые row-данные по-прежнему field-keyed: для дублирующихся колонок значения берутся через `getLinkRefByKey` (link-колонки используют `label_field` для выбора значения), так что «Номер договора» показывает agreement_number, «Номер объекта» — geo_flatnum, «ID объекта» — estate_sell_id. Plain-fallback `rowData[col.field]` срабатывает только для уникальных по field колонок.
- Badge keying (`_badge_<field>`) не трогали — badge-данные приходят с бэка по field; на ID-колонках бейджи не используются.
- Sort идёт по `col.field` (реальный data-ключ) — сортировка дублирующихся колонок по общему field корректна.

---

## 2026-05-27 — Раздел «Документы», milestone M4a (фронт, часть 1 из 2: слой данных + foundation + список)

SSOT раздела — `DOCUMENTS.md`. Backend M1–M3 готов. M4a — только слой данных (api/entities/services), foundation (route/nav/capabilities/util) и страница списка `DocumentsPage`. **DocumentPage flow и настройки (брендинг/акции) — это M4b**, здесь не реализованы.

### Что сделано (по слоям)

**API-клиенты** (`front/src/api/`, через `apiClient`, `company_id` НЕ шлём — резолвится middleware):
- `documents.ts` — `DocumentsApi`: list / get / create / update / remove / publish / unpublish / generate (202 → `{generated_id}`) / getGeneratedStatus / downloadGenerated (responseType blob, format default `pdf`) / previewHtml (→ `{html}`).
- `branding.ts` — `BrandingApi`: getBranding / updateBranding / uploadLogo (FormData, `Content-Type: undefined` чтобы браузер сам выставил multipart-boundary).
- `promotions.ts` — `PromotionsApi`: list (`activeOnly?` → `?active=1`) / get / create / update / remove.
- `macrodata.ts` (новый файл) — `MacroDataApi`: searchEstateSells (`?q=&limit=`) / getEstateSell / getSchema (`?model=`). Отдельно от `macrodataMappings.ts` (разные endpoints, разная зона).
- `api/types/{documents,branding,promotions,macrodata}.ts` — DTO-зеркала (snake_case): DocumentTemplateDto/ListItemDto, GeneratedDocumentDto, BrandingDto, PromotionDto, EstateSellOptionDto/DetailDto, MacroDataSchemaDto + request-типы.

**entities/** (camelCase + mappers):
- `entities/document/{types,mappers,index}.ts` — DocumentTemplate, DocumentTemplateListItem, GeneratedDocument.
- `entities/promotion/{types,mappers,index}.ts` — Promotion.
- `entities/branding/{types,mappers,index}.ts` — Branding.

**services/** + регистрация в `services/index.ts` (Services type + createServices + re-export):
- `DocumentService`, `PromotionService`, `BrandingService` — бизнес-обёртки api+mappers. `DocumentService.generate()` возвращает `number` (generated_id), `downloadGenerated()` отдаёт `Blob`, `previewHtml()` → `string`.
- `MacroDataLookupService` — passthrough DTO (НЕ мапим в camelCase: payloads — живые MacroData field-bags, snake_case = реальные имена колонок шаблонов).

**Foundation:**
- Роуты `router/routes/base.ts`: `/documents` (name `Documents` → DocumentsPage) + `/documents/:id` (name `Document` → DocumentPage), meta `{requiresAuth, requiresCompanyScope}`, до catch-all.
- `pages/DocumentPage/{index.vue,index.ts}` — **ЗАГЛУШКА** (LoadingState), чтобы роут резолвился. Полный флоу — M4b.
- Навигация `components/Toolbox/Toolbox.vue` — пункт `documents` (иконка `pi-file-pdf`) между reports и company, виден всем ролям. Ключ `documents` в `Toolbox/locale/{ru,en}.json`.
- Capabilities `shared/auth/capabilities.ts`: `canManageDocuments` (analyst+), `canManageDocumentPublication` (admin+), `canDeleteDocument(role,isOwner,isSystem)` (копия canDeleteReport), `canManageBranding` (admin+), `canManagePromotions` (admin+), `canSetDiscount` (все роли).
- `utils/fileDownload.ts` — `downloadBlob(blob | Promise<blob>, filename)`: createObjectURL → программный клик `<a download>` (append/remove из DOM для кросс-браузерности) → revokeObjectURL через `setTimeout(0)`.

**DocumentsPage** (`pages/DocumentsPage/`):
- `index.vue` (тонкий) + `composables/{useDocumentsPage,useDocumentsPageData,useDocumentsPageActions}.ts` (orchestrator + data + actions, паттерн DashboardsPage).
- Трёхсекционный split (системные / опубликованные / личные) через `useScopedResource` + `useCompanySelection` (реактивность к активной компании).
- Фильтр по типу (`SelectButton`: all / html / docx) — клиентский `typeFilter` ref в data-composable.
- `components/DocumentCard.vue` (карточка: type-badge + status-badge + описание/заглушка), `components/DocumentSection.vue` (сворачиваемая секция; actions-menu абсолютным позиционированием в углу карточки), `components/DocumentActionsMenu.vue` (publish/unpublish/delete через capabilities + DeleteConfirmModal, эталон ReportActionsMenu; `@click.stop` чтобы тоггл попавера не открывал документ).
- Клик по карточке → `router.push('/documents/'+id)`.
- Локализация: `pages/DocumentsPage/locale/{ru,en}.json` (title/sections/filter/errors/actionsMenu), `pages/DocumentsPage/components/locale/{ru,en}.json` (badge/type/noDescription). RU↔EN симметрия проверена.

**Barrel-экспорты:** добавлены DTO/api в `api/index.ts` и `api/types/index.ts`.

### Проверки
- `npm run type-check` — без ошибок.
- `npm run lint` — 0 errors (1 pre-existing warning в `src/plugins/persist.ts`, не наш файл).
- RU↔EN симметрия по всем 3 парам locale-файлов — OK (скрипт-сверка ключей).

### Что осталось на M4b (НЕ реализовано здесь)
- `DocumentPage` html-флоу: async-поиск объекта (`macroDataLookupService.searchEstateSells`), калькулятор акций (выбор промо + слайдер скидки в диапазоне), авто-брендинг, sandboxed `<iframe :srcdoc>` HTML-превью (`documentService.previewHtml`), кнопка «Сгенерировать → polling статуса (`fetchGeneratedStatus`) → `downloadBlob`».
- Настройки компании: под-секция Брендинг (логотип через FileUpload→`brandingService.uploadLogo`, палитра/шрифты/шапка-подвал/реквизиты), под-секция Акции (CRUD-таблица промо, admin only).
- Кнопка-шестерёнка на DocumentPage → redirect в настройки акций.

### Риски / заметки для backend-сверки
- **`POST /api/documents/{id}/preview`** — endpoint для `previewHtml` ВЫВЕДЕН из формулировки задачи + `DOCUMENTS.md §Страницы` («HTML-превью: sandboxed iframe :srcdoc», `HtmlDocumentService` возвращает HTML и для превью, и для Gotenberg). В таблице endpoints DOCUMENTS.md он **явно не перечислен** — на M4b backend-сверить точный URL/метод (возможно `GET` с query вместо `POST`). M4a previewHtml не вызывается из UI, риск нулевой до M4b.
- `promotions.ts` list использует `?active=1` для `activeOnly` — DOCUMENTS.md не фиксирует имя query-параметра; сверить на M4b при подключении калькулятора.
- `branding.ts` uploadLogo шлёт поле `logo` в FormData — имя поля не зафиксировано в DOCUMENTS.md, сверить с backend-валидацией на M4b.
- DocumentActionsMenu рендерится поверх карточки (absolute, z-index:1) — на узких карточках может перекрывать длинный заголовок; на M4b при добавлении доп. бейджей проверить визуально (qa-tester).

## 2026-05-27 — Раздел «Документы», milestone M4b (фронт, часть 2 из 2: DocumentPage html-флоу + бренд-эдитор + акции)

Завершён код-этап M4. M4a сделал слой данных + список; M4b — рабочая страница КП и две под-секции настроек компании.

### Исправлены контракты M4a (сверено с реальными контроллерами)
- **`previewHtml`** — URL исправлен `POST /api/documents/{id}/preview` → **`POST /api/documents/{id}/preview-html`** (точное имя из `DocumentController::previewHtml`). Тело — `{estate_sell_id?, promotion_id?, discount?, locale?}` (`locale` — `'ru'|'en'`); ответ `{html}`. Добавлен отдельный тип `DocumentPreviewParams` (M4a переиспользовал `DocumentGenerateParams`, в котором нет `locale`).
- **`generate`** — ключ ответа исправлен `generated_id` → **`generated_document_id`** (точно из `DocumentController::generate`, 202). `DocumentService.generate()` читает новый ключ.
- **`GeneratedDocumentDto`** — урезан до tight-проекции, которую реально отдаёт `generatedStatus`: `{id, document_template_id, title, status, pdf_path, docx_path, error, created_at, updated_at}`. Удалены `company_id`, `user_id`, `params` (бэк их НЕ возвращает в статусе — мапились в `undefined`). Синхронно обрезаны `entities/document/types.ts` + `mappers.ts`.
- `DocumentGenerateParams` дополнен опциональным `title` (бэк принимает `title` в generate).
- Сверены остальные клиенты — `PromotionController` (`?active=1` подтверждён), `CompanyBrandingController` (поле `logo` в FormData, PUT branding, POST logo подтверждены), `MacroDataLookupController` (`estate-sells/search?q=&limit=` → `[{value,label}]`) — расхождений нет.

### Новое: DocumentPage (`pages/DocumentPage/`)
- `index.vue` (тонкий) + `composables/{useDocumentPage,useDocumentPageData,useDocumentPageActions}.ts` + `locale/{ru,en}.json`.
- **Данные** (`useDocumentPageData`): загрузка шаблона через `documentService.fetchTemplate` (watch `route.params.id` immediate, guard `id<=0`); async-поиск объекта (`macroDataLookupService.searchEstateSells`, debounce 300ms, синтетическая запись выбранного-но-неподгруженного значения — паттерн `AsyncSelectFilter`); загрузка активных промо (`fetchAllPromotions(true)`); калькулятор скидки с clamp в `[discountMin,discountMax]`; живое HTML-превью с debounce 400ms (`previewHtml`); reset формы при смене `route.params.id`.
- **Действия** (`useDocumentPageActions`): generate → polling `getGeneratedStatus` (1.5s, timeout 120s, до `done|error`) → blob-скачивание PDF через `downloadBlob`; phase-машина `idle|generating|ready|error`; «Скачать ещё раз»; шестерёнка → `router.push({path:'/company', query:{tab:'promotions'}})` (видна `canManagePromotions`). `onUnmounted` чистит таймер.
- **UI**: object Select + promotion Select + InputNumber/Slider скидки (% или сумма) + sandboxed `<iframe :srcdoc sandbox="">` превью + кнопки generate/download + Message-баннеры. docx-тип — заглушка `EmptyState` (M6). Адаптивная grid (форма/превью).

### Настройки компании — две под-секции (встроены в существующую `CompanyPage`, табы PrimeVue)
- Где: **`pages/CompanyPage/index.vue`** (маршрут `/company`, уже admin/superadmin-only). Добавлены табы `branding` и `promotions` (после «Настройки», перед «Маппинг»), gated `canManageBranding` / `canManagePromotions`. Активный таб сидируется из `?tab=branding|promotions` (deep-link с шестерёнки DocumentPage), дальше свободная навигация (`v-model:value="activeTab"`).
- **`components/Company/CompanyBrandingSection.vue`**: логотип через `FileUpload` (`:auto=false`, `custom-upload`, `@select` → `brandingService.uploadLogo`) + превью; палитра — `ColorPicker format=hex` (нормализация `#`-префикса) + hex-`InputText` на каждый из 5 токенов; шрифты (heading/body); шапка/подвал — локализованные `Textarea` (ru/en); реквизиты — free-form text (JSON или plain → `{text}`). Сохранение `updateBranding`, загрузка `fetchBranding`. Read-only при `!canManageBranding`.
- **`components/Company/CompanyPromotionsSection.vue`**: `DataTable` промо + create/edit (`Dialog`) / delete (`DeleteConfirmModal`). Поля: name(ru/en), description(ru/en), discount_type, min, max, sort_order, is_active. Клиентская валидация зеркалит backend (min≤max, percent≤100, name обязателен на одном языке). CRUD через `promotionService`, `fetchAllPromotions(false)` (весь список включая выключенные).
- Оба компонента экспортированы из `components/Company/index.ts`.

### i18n
- `pages/DocumentPage/locale/{ru,en}.json` (26 ключей, симметрично).
- `components/Company/locale/{ru,en}.json` — добавлены namespaces `branding.*` + `promotions.*` (errors вложены внутрь namespace, НЕ top-level — иначе shallow-merge `useLocalI18n` перетёр бы глобальный `errors`-блок). 214 ключей, симметрично.
- `pages/CompanyPage/locale/{ru,en}.json` — `brandingTab` / `promotionsTab`.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто (остаётся 1 pre-existing warning `plugins/persist.ts:91` comma-dangle, не из наших правок).
- RU↔EN симметрия — diff по всем 3 парам локалей: 0 рассинхрона.

### Риски / заметки для qa-tester и backend-сверки
- `branding.requisites` сохраняется как `{text: "..."}` при plain-вводе или как распарсенный JSON-объект — структурированного редактора реквизитов нет (nice-to-have, DOCUMENTS.md §Открытые вопросы).
- Превью КП требует поднятого MacroData у активной компании (`previewHtml` резолвит объект через `DocumentObjectDataResolver`) — на `<LOCAL_STACK_DOMAIN>` объекты должны искаться; пустой объект (без выбора) рендерит «голый» шаблон.
- Экспорт PDF требует контейнер **Gotenberg** (M0). Если на локальном стеке его нет — generate уйдёт в `error` по таймауту/ошибке job'а; это инфра, не фронт.
- Шестерёнка ведёт на `/company?tab=promotions` — таб доступен только admin/superadmin (как и весь `/company`); для analyst/viewer шестерёнка скрыта (`canManagePromotions`).
- happy path для qa-tester: `/documents` → открыть системный HTML-КП → выбрать объект → выставить скидку → превью обновляется → «Сформировать PDF» → файл скачивается; в `/company` → таб «Брендинг» (логотип/палитра) и «Акции» (создать промо).

## 2026-05-27 — Документы M6: Word (docx) фронт-флоу

Ветвление `DocumentPage` по `template.type`: html-флоу не тронут, добавлен docx-флоу (загрузка шаблона, маппинг плейсхолдеров, справочник полей, генерация → DOCX + PDF, viewer-ограничение).

### API / Service / Entities слой
- `api/types/documents.ts` — новые типы: `UploadDocumentSourceResponseDto` (`{message, source_path}`), `DocumentPlaceholdersResponseDto` (`{placeholders: string[]}`), `DocumentFieldCatalogEntryDto` (`{key, label:{ru,en}, group}`), `DocumentFieldCatalogResponseDto` (`{groups: {object, branding, discount}}`), `DocumentFieldCatalogGroup`, `DocumentFieldMappingDto`. `DocumentTemplateConfigDto` уточнён: `{field_mapping?: Record<string,string>, [key]: unknown}` (был opaque `Record<string,unknown>`).
- `api/documents.ts` — 3 новых метода: `uploadSourceFile(id, File)` (multipart, поле `file`, `Content-Type: undefined` чтобы браузер выставил boundary — паттерн `brandingApi.uploadLogo`), `getPlaceholders(id)`, `getFieldCatalog()`.
- `entities/document/{types,mappers,index}.ts` — domain-типы `DocumentFieldCatalog`/`DocumentFieldCatalogEntry`/`DocumentFieldMapping` + `mapFieldCatalogResponseToCatalog` (нормализует группы в фикс-порядок object/branding/discount).
- `services/DocumentService.ts` — обёртки `uploadSourceFile` (→ `source_path`), `fetchPlaceholders` (→ `string[]`), `fetchFieldCatalog` (→ domain catalog).

### DocumentPage — ветвление и docx-флоу
- `composables/useDocumentPage.ts` (orchestrator) — добавлены `isDocx` (`type==='docx'`), `canManage` (`canManageDocuments(role) && !isSystem`), композ `useDocumentDocx`, `autoDownloadFormat` (`pdf` для html / `null` для docx). Экспортит docx-стейт + `canManageDocuments`.
- `composables/useDocumentDocx.ts` (новый) — docx-only data-слой: lazy field-catalog, placeholders загрузка (422 при отсутствии source трактуется как «нет плейсхолдеров»), черновик `field_mapping` с **авто-подстановкой** (токен == литеральному catalog-key → авто-маппинг; `req_*`-wildcard исключён), сохранение через `updateTemplate({config: {...existing, field_mapping}})`, upload `.docx` + рефетч placeholders, справочник-модалка. Группы select'а: object/discount/branding.
- `composables/useDocumentPageActions.ts` — `autoDownloadFormat` ref-аргумент; `downloadReady(id, format)` параметризован по формату; на ошибке скачивания фаза НЕ откатывается в `error` (файл готов, можно повторить); `downloadAgain(format='pdf')`.
- `pages/DocumentPage/index.vue` — переписан: header + (html-body | docx-body | unsupported-fallback). docx-body = [DocxConfigPanel если canManage] + [generate-side: ProposalSelectors + кнопка генерации + DOCX/PDF download + статус] + FieldCatalogModal. Удалена `docxComingSoon`-заглушка.

### Новые компоненты (`pages/DocumentPage/components/`)
- `ProposalSelectors.vue` — извлечён общий блок (объект async-поиск + промо + скидка-калькулятор), переиспользуется html- и docx-флоу через emits.
- `DocxConfigPanel.vue` — загрузка `.docx` (PrimeVue `FileUpload` mode=basic, custom-upload, `:auto=false`, accept `.docx`, лимит 10MB) + таблица маппинга (token → grouped Select) + warning по незамапленным + кнопка «Сохранить сопоставление» + «?»-кнопка справочника.
- `FieldCatalogModal.vue` — модалка «Доступные поля» (группы object/branding/discount, `${key}` + локализ. label, кнопка копирования `${key}` через `navigator.clipboard`).

### Viewer-ограничение
- `canManage = canManageDocuments(role) && !isSystem`. Viewer (и любой без `canManageDocuments`, и системные шаблоны) НЕ видит DocxConfigPanel (upload/маппинг/«?»). Viewer видит только generate-side: ProposalSelectors + «Сформировать» + DOCX/PDF download. Generate-side показывается viewer'у всегда (`!canManageDocuments`), для manage-роли — только при наличии source (`hasSource`); до загрузки manage видит EmptyState «notReady».

### i18n
- `pages/DocumentPage/locale/{ru,en}.json` — добавлен блок `docx.*` (generate/ready/notReady/downloadDocx/downloadPdf/groups/upload/mapping/catalog/errors) + `generation.downloadedDocx` + `errors.unsupportedType`; удалён `docxComingSoon`. RU↔EN симметрия: 58/58 ключей, 0 рассинхрона.

### DocumentsPage (item 5)
- Изменений не потребовалось: фильтр all/html/docx и type-badge на `DocumentCard` (severity `warn` для docx) уже сделаны в M4. Проверено: `DocumentSection` прокидывает `:type="item.type"`.

### Контракты — сверка
- Все 3 endpoint'а сверены с реальным `DocumentController` (`uploadSourceFile`/`placeholders`/`fieldCatalog`) и `config/documents.php` (field_catalog: 25 object + 3 branding + 6 discount ключей). `req_*` — wildcard, не литерал → исключён из авто-маппинга и select-опций, но показан в справочнике как информационный.

### type-check / lint
- `npm run type-check` — чисто (vue-tsc, 0 ошибок).
- `npm run lint` — 0 errors; 1 pre-existing warning в `plugins/persist.ts` (не наш файл, не регрессия — см. memory `feedback_lint_preexisting`).

### Риски / заметки для qa-tester
- Генерация docx требует backend M5 (DocxTemplateService + Gotenberg для docx→pdf). Если на локальном стеке нет phpword/Gotenberg — generate уйдёт в `error`; это инфра/backend, не фронт.
- M6 happy path: `/documents` → открыть docx-шаблон → (admin) загрузить `.docx` с `${field}` → справочник по «?» → маппинг полей + сохранить → выбрать объект → «Сформировать» → скачать DOCX и PDF.
- Роли: viewer открывает docx-шаблон с уже загруженным source → видит только объект/скидку/генерацию/скачивание (без upload/маппинга/«?»). Системный docx-шаблон → manage-UI скрыт даже у admin (read-only, `!isSystem`).
- `navigator.clipboard.writeText` требует secure-context (https/localhost); на `<LOCAL_STACK_DOMAIN>` (https) работает, на голом http — fallback-ошибка «не удалось скопировать».
- Grouped Select (`optionGroupLabel`/`optionGroupChildren` + слоты `#optiongroup`/`#option`) — первое использование группированного Select в проекте; визуально проверить рендер групп в дропдауне маппинга.

---

## 2026-05-27 — Documents M6: создание нового шаблона (точка входа Word-флоу)

DocumentsPage не имел UI создания шаблона (M4 отложил «через AI» на M8). Добавлена кнопка «+ Создать» + диалог создания: основной сценарий — docx (имя + тип → навигация в DocumentPage, где грузится docx и делается маппинг M6); html создаётся пустым (откроется КП-редактор M4).

### Новые / изменённые файлы
- **`pages/DocumentsPage/components/CreateDocumentDialog.vue`** (новый) — PrimeVue `Dialog` (`v-model:visible`). Поля: тип (`SelectButton` docx/html, docx ведущий — основной сценарий), название ru (обязательно) + en (`InputText`), описание ru/en (`Textarea` auto-resize). Валидация: ru-название обязательно (inline `p-invalid` + сообщение, сбрасывается на `@input`); тип всегда задан (`:allow-empty="false"`, дефолт docx). Submit → `documentService.createTemplate({name:{ru[,en]}, description:{ru?,en?}|null, type, config:{}})` → тост успеха → `emit('created', id)`. Пустые en/описания не отправляются (description=null если оба пусты). `reset()` на каждое открытие (watch visible). Закрытие заблокировано во время сохранения (`saving`).
- **`pages/DocumentsPage/index.vue`** — кнопка `Button icon="pi pi-plus"` `v-if="canManage"` в хедере (новый flex-wrapper `.documents-actions` рядом с фильтром типа); `<CreateDocumentDialog v-model:visible @created="goToCreatedDocument">` в конце шаблона; импорт `Button` + диалога; добавлены в destructure `canManage`, `createDialogVisible`, `openCreateDialog`, `goToCreatedDocument`.
- **`pages/DocumentsPage/composables/useDocumentsPageActions.ts`** — стейт диалога (`createDialogVisible` ref) + `openCreateDialog`/`closeCreateDialog` + `goToCreatedDocument(id)` (закрывает диалог + `router.push('/documents/'+id)`). `canManage` уже существовал.
- **`pages/DocumentsPage/locale/{ru,en}.json`** — добавлен блок `create.*` (20 ключей: button/title/type_*/name_*/description_*/name_required/cancel/submit/success/error).

### Контракт
- `POST /api/documents` через существующий `documentService.createTemplate` + `CreateDocumentTemplateRequest` (уже были в сервисе/типах с M4 — менять не пришлось). Бэкенд сам ставит `is_system=false` + привязку к активной компании. ACL `canManageDocuments` (superadmin/admin/analyst).
- Навигация после создания: `/documents/{id}` — для docx там DocumentPage покажет upload+маппинг (M6), для html — КП-флоу (M4). html создаётся с пустым `config:{}`, создание не блокируется (AI-генерация html — M8).

---

## 2026-05-27 — Bugfix (QA M6 FAIL #2): vue-i18n SyntaxError на `${...}` в locale → DocxConfigPanel не рендерится

Симптом: открытие `/documents/:id` для свежесозданного docx-шаблона БЕЗ загруженного source — `DocxConfigPanel` не рендерился (пустой `aside`, `<!---->`), в консоли `SyntaxError` (vue-i18n message-compiler code 2, x2).

Root cause: locale-строки содержали литеральный `${...}` в тексте. vue-i18n при ленивой компиляции сообщения трактует `${` как linked-message/интерполяцию и падает с SyntaxError, ломая рендер всего компонента, который вызвал `t()`. Строки компилируются лениво в `useLocalI18n` (`composables/useLocalI18n.ts` → `useI18n` с plain-JSON messages), поэтому краш срабатывал именно в момент первого `t('docx.upload.noSource')` внутри `DocxConfigPanel`.

Фикс — экранирование через vue-i18n literal-синтаксис `{'$'}{'{'}...{'}'}`, чтобы сохранить точный вид `${...}` для пользователя без парсинга:
- `pages/DocumentPage/locale/ru.json` — `docx.upload.noSource`: `…вида {'$'}{'{'}поле{'}'}.`; `docx.mapping.noPlaceholders`: `…плейсхолдеров {'$'}{'{'}...{'}'}`.
- `pages/DocumentPage/locale/en.json` — `docx.upload.noSource`: `…with {'$'}{'{'}field{'}'}-style placeholders.`; `docx.mapping.noPlaceholders`: `No {'$'}{'{'}...{'}'} placeholders found…`.

Рендер проверен прогоном через vue-i18n runtime: строки выводятся ровно как `${поле}` / `${field}` / `${...}` без ошибок (смысл «плейсхолдеры пишутся как `${имя}`» сохранён).

### Проверка остальных locale `${}`
- Полный sweep `grep '\${'` по `pages/Document*` + `components/Company` locale-json: после фикса совпадений 0. Это были единственные две строки во всём фронте с литеральным `${...}` в locale.
- `FieldCatalogModal.vue` показывает `${key}` не из locale, а конкатенацией в шаблоне (`'${' + entry.key + '}'`) — не проходит через message-compiler, краша не вызывает, не трогал.

### type-check
- `npm run type-check` — чисто (vue-tsc, 0 ошибок). JSON обоих locale валиден.

### i18n
- RU↔EN симметрия `create.*` проверена скриптом: 0 рассинхрона (only-ru: [], only-en: []).

### type-check / lint
- `npm run type-check` — чисто (vue-tsc, 0 ошибок).
- `npm run lint` — 0 errors; 1 pre-existing warning в `plugins/persist.ts` (не наш файл, не регрессия — memory `feedback_lint_preexisting`).

### Риски / заметки для qa-tester
- Happy path (manage-роль): `/documents` → «+ Создать» → выбрать «Word (docx)» → ввести название (рус.) → «Создать» → тост → редирект на `/documents/{id}` (там загрузка docx + маппинг M6).
- HTML-ветка: тот же диалог, тип «КП (HTML)» → редирект в DocumentPage html-флоу (пустой шаблон, M4).
- Кнопка «+ Создать» скрыта у viewer (`v-if="canManage"`).
- Валидация: пустое ru-название → inline-ошибка, submit заблокирован; en/описание опциональны.
- Бэкенд `POST /api/documents` должен принимать `description: null` (когда оба описания пусты) — если бэк ждёт object/отсутствие ключа, проверить (тип допускает `null`).

---

## 2026-05-27 — Документы M8 (AI-слой фронт): генерация КП + авто-расстановка docx-полей

Финальный milestone раздела «Документы». Зеркало двухшагового AI-флоу виджетов (`WidgetGenerationModal` + `WidgetVariantsPanel`) и report-modal паттерна. Backend M7 готов: chat `type='document_template'`, `scope_type='document'`, `chats.document_id`, SSE `document_fields_proposed`, DocumentTool (probe_data + propose_document_fields + generate_document_template).

### Что сделано

**1. Type-plumbing (chat-слой)**
- `api/types/chats.ts`: `ChatType` += `'document_template'`; `ChatScopeType` += `'document'`; `document_id?` в `ChatListItemDto`/`ChatDetailDto`/`InlineCreateMessageRequest`/`ListChatsRequest`/`ResumeChatRequest`; `ChatMessageEventType` += `'document_fields_proposed'`; новые типы `DocumentFieldSource`, `DocumentFieldProposalDto`, `DocumentFieldsProposedPayload`.
- `entities/chat/{types,mappers}.ts`: `documentId: number|null` в `ChatDetail`/`ChatListItem` + маппинг `document_id`.

**2. SSE-обработка** — `useChatStream.ts`: `document_fields_proposed` (+ `widget_variants`, ранее отсутствовавший) добавлены в `TYPED_EVENT_TYPES`. Событие доходит до consumer-композаблов (проверяют `event.type === 'document_fields_proposed'` напрямую, как `widget_variants`).

**3. Генерация HTML-КП через AI (точка входа: CreateDocumentDialog при html-типе)**
- `stores/documentGenerationModal.ts` — зеркало `reportGenerationModal` (open/close/prefillPrompt + `signalDocumentUpdated`/`documentUpdatedTick`). Только create-режим (КП).
- `components/chat/composables/useDocumentGenerationModalChat.ts` — lazy-create `type='document_template'`, `scope_type='general'`; стрим через `useChatStream`; трекает `createdDocumentId` из `currentChat.documentId`; НЕ трогает `useChatsStore`.
- `components/chat/DocumentGenerationModal.vue` — диалог, EmptyState→ChatMessageList, CTA «Открыть шаблон»/«Готово» после создания (роутинг `/documents/{id}`).
- Смонтирован в `layouts/DefaultLayout/index.vue` рядом с Report/Widget модалками.
- Точка вызова: `CreateDocumentDialog.vue` — при выборе типа `html` показывается кнопка «Сгенерировать через AI» (закрывает диалог, открывает модалку). Manual-create остаётся для docx.

**4. AI авто-расстановка docx-полей (ключевая фича, DocumentPage docx-флоу)**
- `pages/DocumentPage/composables/useDocumentFieldsProposal.ts` — document-scoped чат: lazy-create `type='document_template'`, `scope_type='document'`, `document_id=<id>`, фиксированный prompt; ловит SSE `document_fields_proposed` в `proposals` (зеркало `variants`). Изолирован от `useChatsStore`.
- `pages/DocumentPage/components/DocumentFieldsProposedPanel.vue` + `DocumentFieldProposalCard.vue` — карточки `token → suggested_field`, source-бейдж (catalog/macrodata через `Tag severity success|warn`), confidence %, accept/dismiss per-card + accept-all/dismiss-all.
- Accept → `applyMappings()` в `useDocumentDocx` (новый bulk-merge helper, фильтрует по известным placeholder'ам) → `saveMapping()` → `PUT /api/documents/{id}` с `config.field_mapping`. Таблица маппинга в DocxConfigPanel синхронизируется (общий `mappingDraft`).
- Кнопка «AI: расставить поля» в docx manage-side `<aside>` (gated `canManageDocuments && hasSource`).

**5. documentContext store + mini-chat document-ветка**
- `stores/documentContext.ts` — зеркало `reportContext`/`dashboardContext` (id, type, title, placeholderCount, mappedCount). DocumentPage пишет snapshot через `watchEffect` в `useDocumentPage.ts`, чистит на `onUnmounted`.
- `useMiniChat.ts`: `MiniChatScope` += `documentId`; ветка `'document'` в `currentScope` (precedence report > dashboard > document); `document_id` прокинут в resume/inline-create/fetchChatsScoped/scope-change watcher.
- `MiniChatWidget.vue`: document-ветки в contextBadge/contextHint/placeholders/empty-states.

**6. Action-marker** `redirect_to_document_generation`
- `utils/markdown.ts`: добавлен в `KNOWN_ACTION_MARKERS`.
- `useChatActionMarker.ts`: ветка → `documentModalStore.open({ prefillPrompt })`. **Dormant** — backend M7 пока этот маркер не эмитит (на будущее, симметрия с widget/report).

**7. Capabilities** — `canManageDocuments` (analyst/admin/superadmin) уже существовал, переиспользован для AI-кнопок; mini-chat document-контекст под `canUseMiniChat` (как обычный mini-chat). Viewer не видит AI-фичи.

### i18n (RU↔EN симметрично, проверено key-diff'ом)
- chat locale: `documentGenerationModal.*`, `miniChat.{emptyOnDocument, previewPlaceholderOnDocument, placeholderOnDocument, documentContextHint}`.
- DocumentPage locale: `docx.ai.*` (autoMap, proposalsHint, accept/dismiss[All], confidence, source.{catalog,macrodata}, errors.*).
- DocumentsPage locale: `create.{ai_generate, ai_generate_hint}`.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто на всех M8-файлах; единственный warning (`plugins/persist.ts` trailing comma) — pre-existing, не мой файл.

### Заметки / риски
- Двухшаговый flow КП-модалки НЕ рендерит variant-карточки — это поведение docx-панели (scope=document). Модалка только генерит HTML-шаблон через `generate_document_template`.
- `useDocumentFieldsProposal` использует DocumentPage-локальные error-ключи (`docx.ai.errors.*`), не chat-локаль, т.к. работает с `data.t`.
- После accept маппинга `template.value` обновляется через `saveMapping` — refetch не нужен.

---

## DocumentPage UX: меню ⋮ + редактирование шаблона (ручное + AI) (2026-05-28)

Доработка UX страницы КП `/documents/:id`. Проблема: можно выбрать объект/акцию/скидку, но не было способа сгенерировать/отредактировать сам шаблон; шестерёнка в шапке вела на настройку акций и сбивала.

### Изменённые / созданные файлы

**Перенос ссылки настройки акций**
- `pages/DocumentPage/index.vue` — убрана standalone-кнопка-шестерёнка из шапки (вела на `/company?tab=promotions`).
- `pages/DocumentPage/components/ProposalSelectors.vue` — добавлен `small`-текст-link «Настроить акции» рядом с полем **Акция** (новый `form-label-row`). Props `canManagePromotions?` + emit `open-promotions`. Видим только `canManagePromotions` (admin/superadmin). Доступен с клавиатуры (`role=button`, `tabindex=0`, Enter/Space).
- Текст ключа `settings.openPromotions` изменён с «Настройки акций» → «Настроить акции» (RU) / «Promotion settings» → «Configure promotions» (EN) — теперь это CTA-ссылка, а не tooltip.

**Меню ⋮ в шапке DocumentPage**
- Переиспользован существующий `pages/DocumentsPage/components/DocumentActionsMenu.vue` (импорт cross-page через `@/pages/DocumentsPage/...`) — НЕ дублирован. Компонент обобщён:
  - prop `document` теперь union `DocumentTemplateListItem | DocumentTemplate`; добавлен info-row «Создан» (`createdAtDisplay`, читает `.createdAt` defensively — есть только в detail-shape, через `useFormatter` datetime).
  - новый prop `editable?` (default false) + emit `@edit`; пункт «Редактировать» (`pi-pencil`) под `canManageDocuments && !isSystem`. DocumentPage передаёт `editable`; DocumentsPage-сетка — нет.
  - инфо (автор/дата/статус) + Опубликовать/Снять с публикации (`canManageDocumentPublication`, не для system) + Удалить — без изменений логики.
- `pages/DocumentPage/index.vue` — `<DocumentActionsMenu :document="template" editable @edit @document-updated @document-deleted>` вместо шестерёнки. Delete → `router.push('/documents')`.

**Модалка редактирования (новый компонент)**
- `pages/DocumentPage/components/DocumentEditModal.vue` — открывается в РУЧНОМ режиме по умолчанию.
  - html-КП: форма name(ru/en) + description(ru/en) + редактор `config.html` (monospace Textarea) + `config.css`. Save спредит существующий `config` (defaults/fields/field_mapping переживают round-trip). Хелпер вставки плейсхолдеров — чипы из field-catalog, сгруппированные по bucket (object/branding/discount), вставка `{{key}}` по каретке; `req_*` (branding wildcard) отфильтрован. **HTML-шаблоны используют `{{token}}`** (двойные фигурные), не docx-`${token}`.
  - docx: только name/description + info-Message со ссылкой на on-page upload+mapping флоу (НЕ дублирую `DocxConfigPanel`).
  - Save → `documentService.updateTemplate(id, {name, description, config?})` → `setTemplate` + `reloadTemplate` (refetch + ре-рендер превью).
  - Кнопка «Редактировать с AI» → `documentGenerationModal.open({mode:'edit', documentId})`, закрывает ручную модалку.

**DocumentGenerationModal — режим edit**
- `stores/documentGenerationModal.ts` — добавлены `mode:'create'|'edit'` + `documentId` (зеркало `reportGenerationModal`).
- `components/chat/composables/useDocumentGenerationModalChat.ts` — `init` в edit-режиме делает `chatService.resume({scope_type:'document', document_id})` (204 → preview-state, первый send lazy-создаёт `scope_type='document'` document_template чат, привязанный к шаблону), reconnect in-flight через `reconnectInFlight`. Inline-create в edit-режиме шлёт `scope_type='document'` + `document_id` + `type='document_template'`.
- `components/chat/DocumentGenerationModal.vue` — `headerTitle`/`previewMessage` по `mode`; кнопка «Открыть шаблон» скрыта когда уже на странице редактируемого шаблона (route name `'Document'`), «Готово» остаётся.
- `pages/DocumentPage/composables/useDocumentPage.ts` — watch `documentUpdatedTick`: при settle AI-турна для текущего шаблона (`lastUpdatedDocumentId === documentId`) → `reloadTemplate()` (зеркало ReportPage ↔ reportUpdatedTick).
- `pages/DocumentPage/composables/useDocumentPageData.ts` — экспорт `reloadTemplate()` (refetch шаблона без сброса формы + ре-рендер превью) и `setTemplate()`.

Точки входа на генерацию полностью НОВОГО шаблона (CreateDocumentDialog «Сгенерировать через AI») — НЕ тронуты.

### Backend
- Изменения backend НЕ требуются. Все эндпоинты уже есть: `PUT /api/documents/{id}`, publish/unpublish, `GET /api/documents/field-catalog`, `chats` resume/inline-create со `scope_type=document` + `document_id`. AI-обновление шаблона (`generate_document_template` при выставленном `chat.document_id`) поддержано backend M7.

### i18n (RU↔EN симметрично, проверено key-diff'ом)
- DocumentPage locale: блок `edit.*` (title, editWithAi[+Hint], name/description ru/en label+placeholder, html_*, css_*, placeholders_title, docx_note, cancel/save, name_required, success, errors.{loadCatalog,save}); правка текста `settings.openPromotions`.
- DocumentsPage locale: `actionsMenu.created_at` + `actionsMenu.edit`.
- chat locale: `documentGenerationModal.title.edit` + `documentGenerationModal.previewEdit`.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто на всех изменённых файлах; единственный warning (`plugins/persist.ts` trailing comma) — pre-existing, не мой файл.

### Заметки / риски
- `DocumentActionsMenu` теперь общий для DocumentsPage-сетки и DocumentPage-шапки; cross-page импорт. Любая правка меню затрагивает обе поверхности.
- В edit-режиме `createdDocumentId` сидится сразу при открытии модалки → «Готово» CTA-строка видна до отправки сообщения (безвредно, Done = закрыть).
- docx-редактирование контента (upload+mapping) остаётся на странице; в модалке для docx — только name/description.

---

## Документы v2: ⋮ убран со списка, ручное редактирование = загрузка файла, богатый справочник полей, маппинг-UI выпилен (2026-05-28)

Доработка раздела «Документы» под v2-backend. SSOT — `DOCUMENTS.md` + `FRONTEND.md`. Backend v2 уже в рабочем дереве (незакоммичен). Главные перемены: плейсхолдеры стали **каноническими ключами каталога** (прямая подстановка, без ручного маппинга), `source-file` принимает и `.html`/`.htm`, и `.docx`, а field-catalog вернул 7 групп с фильтрами/примерами/PII.

### 1. ⋮ убран с карточек списка
- `pages/DocumentsPage/components/DocumentSection.vue` — удалён `DocumentActionsMenu` с карточек-плиток (по скрину владельца). Карточка списка — только клик→переход на `/documents/:id`. Убраны emit `updated` и absolute-позиционирование `&__actions`; `&__cell` упрощён до `display:flex`.
- `pages/DocumentsPage/index.vue` — убраны `@updated="refresh"` со всех трёх `DocumentSection` + `refresh` из деструктуризации (остаётся внутренним в `useDocumentsPage` для `documentUpdatedTick`-watch).
- Сам компонент `DocumentActionsMenu` НЕ удалён — он по-прежнему используется в шапке `DocumentPage` (деталь) с инфо/publish/edit/delete.

### 2. Ручное редактирование = загрузка файла (НЕ textarea)
- `pages/DocumentPage/components/DocumentEditModal.vue` — переписан. Textarea-редактор `config.html`/`config.css` + хелпер вставки чипов УДАЛЕНЫ. Теперь:
  - name(ru/en) + description(ru/en) редактируемы → `PUT /api/documents/{id}` (config больше не правится).
  - Блок «Файл-источник»: PrimeVue `FileUpload` custom-uploader → `documentService.uploadSourceFile(id, file)`. `accept` = `.html,.htm` для html, `.docx` для docx (по `template.type`). Сразу после загрузки `emit('saved', {...tpl, sourcePath})` (страница ре-рендерит) + рефетч плейсхолдеров.
  - Извлечённые плейсхолдеры — read-only список с пометкой ✓ известный / ✗ нет в справочнике (через `isKnownPlaceholder` против полного каталога). HTML-токены показываются как `{{token}}`, docx — `${token}`.
  - Кнопка «?» (`pi-question-circle`) → внутренний `FieldCatalogModal`.
  - Кнопка «Редактировать с AI» → `documentGenerationModal.open({mode:'edit', documentId})` (без изменений).

### 3. FieldCatalogModal — богатый каталог
- `pages/DocumentPage/components/FieldCatalogModal.vue` — переписан под группированный ответ. Принимает полный `catalog: DocumentFieldCatalog` + `locale` (раньше — плоский `reference`). Рендер 7 групп (object/deal/buyer/finances/discount/common/branding) с заголовками; по каждому полю: label, `${key}` + кнопка копировать, бейдж **ПДн** (severity=danger) если `pii`, `example`, чипы доступных filters — каждый чип копирует `${key|filter}`.

### 4. UI ручного маппинга убран
- `pages/DocumentPage/components/DocxConfigPanel.vue` — переписан. Таблица маппинга плейсхолдер→Select УДАЛЕНА. Теперь: загрузка `.docx` + read-only список извлечённых плейсхолдеров с пометкой известный/нет + кнопка «?» справочник + warning по неизвестным токенам. Кнопка «AI: расставить поля» (`proposeFields`) осталась на странице (`index.vue` `<aside>`, без изменений).
- `pages/DocumentPage/composables/useDocumentDocx.ts` — выпилены `catalogSelectGroups`, `mappingRows`, `unmappedTokens`, `hasUnmapped`, `onMappingChange`, `catalogReference`. Добавлены `fieldCatalog` (для модалки), `placeholderRows` (token + known), `unknownTokens`, `hasUnknown`. `applyMappings` + `saveMapping` ОСТАВЛЕНЫ — только для AI-accept-флоу (пишут `config.field_mapping` как опциональный fallback); вручную не редактируется.
- `pages/DocumentPage/index.vue` — docx-aside переключён на новые props/emits; `FieldCatalogModal` принимает `:catalog="fieldCatalog"` + `:locale`.

### Типы / маппер (v2 shape)
- `api/types/documents.ts` — `DocumentFieldCatalogGroup` расширена до 7 групп; новый `DocumentFieldFilter`; `DocumentFieldCatalogEntryDto` += `filters[]`, `example?`, `pii?`; `DocumentFieldCatalogResponseDto.groups` стал `Partial<Record<...>>`.
- `entities/document/types.ts` — `DocumentFieldCatalogEntry` += `filters/example/pii`; export `DocumentFieldFilter`.
- `entities/document/mappers.ts` — `FIELD_CATALOG_GROUPS` (7, экспортируется), `emptyCatalog()`, маппер backfill'ит пустые группы и новые поля; **новый helper `isKnownPlaceholder(token, catalog)`** — strip `|filter`, literal-match по всем группам + `req_*` → prefix-match.
- `entities/document/index.ts` — export `FIELD_CATALOG_GROUPS`, `isKnownPlaceholder`, `DocumentFieldFilter`.

### Оставлено без изменений
⋮ на странице документа (деталь); promo small-ссылка под Акцией (ProposalSelectors); DocumentGenerationModal create+edit; генерация→скачивание; ProposalSelectors объект/скидка; AI auto-map флоу (`useDocumentFieldsProposal` + карточки).

### i18n (RU↔EN симметрично, проверено key-diff'ом)
- DocumentPage locale `edit.*`: удалены `html_*`/`css_*`/`placeholders_title`/`docx_note`; добавлены `source_*` (label/has_html/has_docx/none_html/none_docx/choose/replace/uploading/uploaded/limit_html/limit_docx), `placeholders_found/none`, `placeholder_known/unknown`, `errors.{loadPlaceholders,upload}`.
- DocumentPage `docx.groups` += deal/buyer/finances/common; `docx.placeholders.*` (новый блок); `docx.mapping` сведён к `saved`; `docx.catalog` += copyWithFilter/empty/pii/example.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — чисто на изменённых файлах; единственный warning (`plugins/persist.ts` trailing comma) — pre-existing.

### Заметки / риски
- Плейсхолдеры теперь = канонические ключи каталога (`${estate.price}`), прямая подстановка. `config.field_mapping` оставлен как опциональный fallback — фронт пишет туда только из AI-accept, руками не правит.
- `req_*` (branding) — wildcard: в `isKnownPlaceholder` любой `req_<ключ>` считается известным (prefix-match).
- Нет SCSS-токенов `$success`/`$warning`: known-иконка = `$primary-color`, unknown = `$orange-500` (как в проекте).
- `DocumentEditModal` монтирует собственный `FieldCatalogModal` (для html, где on-page docx-панели нет); на docx-странице есть ещё один инстанс в `index.vue` — независимые visibility-стейты, безвредно.

---

## 2026-05-28 — Documents section behind a build-time feature flag (hide on dev/prod)

Цель: спрятать раздел «Документы» целиком за env-driven фича-флагом, чтобы при OFF не осталось НИ ОДНОЙ точки входа (меню / прямой URL / AI-чат). Причина: dev/prod будут задеплоены без Gotenberg, раздел должен быть невидим и недоступен. Бэкенд не тронут — `/api/documents/*` живые, но без UI-триггеров.

### Флаг
- **Имя:** `VITE_FEATURE_DOCUMENTS` (Vite build-time, инлайнится при `npm run build`).
- **Дефолт = ON.** Раздел скрыт ТОЛЬКО если значение строго `'false'`. Unset / пусто / любое другое значение = ON (локальная разработка и существующие сборки не ломаются).
- `src/shared/featureFlags.ts` — единственная точка чтения `import.meta.env.VITE_FEATURE_DOCUMENTS` → экспорт `DOCUMENTS_FEATURE_ENABLED`. Больше нигде `import.meta.env.VITE_FEATURE_DOCUMENTS` не читается напрямую.
- `env.d.ts` — типизирован `ImportMetaEnv.VITE_FEATURE_DOCUMENTS?: string` (+ заодно `VITE_MOCK_API`).
- `src/shared/auth/capabilities.ts` — производная capability `canUseDocuments(role?)` завязана на `DOCUMENTS_FEATURE_ENABLED` (игнорирует роль: при OFF недоступно даже superadmin; при ON работают существующие per-role гейты `canManageDocuments` / `canDeleteDocument` / `canManageDocumentPublication`).

### Закрытые точки входа (при OFF)
1. **Nav-item Toolbox** — `components/Toolbox/Toolbox.vue`: пункт `documents` в `navItems` стал условным (`canShowDocuments` через `canUseDocuments`), как `company`. При OFF не рендерится.
2. **Маршруты** `/documents` + `/documents/:id` — `router/routes/base.ts`: добавлен `meta.requiresFeature: 'documents'` (тип в `router/routes/types.ts`). `router/policy.ts` `resolveNavigation` редиректит на `getDefaultRoute(role)` (fallback `DEFAULT_HOME_PATH`) при `requiresFeature==='documents' && !canUseDocuments()`. Прямой URL / закладка не открывают страницу.
3. **DocumentGenerationModal** — `layouts/DefaultLayout/index.vue`: монтаж модалки гейтнут `v-if="showLayout && documentsEnabled"`. При OFF модалка не существует → не может быть открыта стрэй-вызовом стора.
4. **AI-чат action-marker** `redirect_to_document_generation` — `components/chat/composables/useChatActionMarker.ts`: при OFF `documentModalStore.open(...)` не вызывается (no-op, `onComplete()` всё равно отрабатывает). `components/chat/ChatMessageBubble.vue`: при OFF маркер `redirect_to_document_generation` обнуляется в `extracted` computed → CTA-кнопка не рендерится (raw JSON-блок уже вырезан из `cleanContent`, не протекает). Работает на всех чат-поверхностях (full-screen `/ai-chat`, mini-chat) — хендлер и бабл общие.
5. **Mini-chat document scope** — закрыт транзитивно: `documentContext` стор пишется ТОЛЬКО из `useDocumentPage.ts` (DocumentPage). Раз route гейтнут — стор не гидрируется → `useMiniChat` никогда не входит в `scopeType: 'document'`.

Все document-UI компоненты (`DocumentSection`, `DocumentCard`, `DocumentActionsMenu`, `CreateDocumentDialog`, `useDocumentsPage`) и их триггеры модалки живут внутри `DocumentsPage`/`DocumentPage` — route-gated. Стрэй deep-link'ов на `/documents` вне этих страниц нет (греп подтвердил: только API-строки `/api/documents/*` + nav-item + внутренний `router.push` модалки после успешной генерации, который достижим только при открытой модалке = ON).

### Доставка OFF на dev/prod
- `docker/frontend.Dockerfile` (build stage) — `ARG VITE_FEATURE_DOCUMENTS` + `ENV VITE_FEATURE_DOCUMENTS=${VITE_FEATURE_DOCUMENTS}` перед `npm run build`. Дефолт пустой = ON.
- `.github/workflows/build-and-push.yml` (frontend image) — `build-args: VITE_FEATURE_DOCUMENTS=false`. Образ `ghcr.io/skorpyone/vizion-frontend` (=dev и prod) собирается с OFF.
- **Локальный owner-стек `<LOCAL_STACK_DOMAIN>`** — rebuild через `docker build -f docker/frontend.Dockerfile --target production ...` БЕЗ `--build-arg` → флаг пустой → **ON** (Документы работают как сейчас). Здесь правка не требуется.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — единственный warning `plugins/persist.ts` (trailing comma) pre-existing, файл не трогал.

### Заметки / риски
- Флаг build-time, НЕ реактивный (Vite заменяет на строковый литерал при сборке) — менять можно только пересборкой образа.
- Бэкенд не тронут: `/api/documents/*` живые. При OFF до них нет UI-пути, но прямой API-вызов (curl) технически доступен — это явно приемлемо по ТЗ (бэкенд-код спящий).
- `requiresFeature` — расширяемый гейт (пока единственное значение `'documents'`); добавить новую фичу = новый литерал в `RouteMeta.requiresFeature` + ветка в `policy.ts`.

---

## 2026-05-29 — Прод-хотфикс рендера AI-чата (interim vs final vs error)

Цель: после перехода генерации на новый провайдер рендер «плыл» — interim-преамбула модели (`text_delta kind='content'` ДО tool_call / final_message) выливалась в ТЕЛО сообщения, пока блок «Thinking…» ещё был открыт; плюс зависший тёрн крутил вечный спиннер (новый гарантированный `error`-event не обрабатывался вообще).

### Корень
`applyTextDelta` во всех 5 чат-композаблах писал `kind='content'` дельты прямо в `msg.content`, а `ChatMessageBubble` рендерит `msg.content` в теле во время стриминга → interim протекал в тело. `error`-событие нигде не обрабатывалось в `onEvent` (только пост-фактум тост в `finalize`).

### Развязка interim / final / error
Введены два runtime-only поля в `ChatMessage` (`entities/chat/types.ts`):
- **`streamingContent`** — аккумулятор interim-`content`-дельт. Рендерится ВНУТРИ прогресс-блока, НЕ в теле.
- **`errorMessage`** — локализованная строка из `error.payload.user_message`. Драйвит inline error-state вместо спиннера.

`content` теперь = ТОЛЬКО канонический финальный ответ (приходит исключительно из `final_message`). Для buffered-провайдера (GLM, без дельт) тёрн = `started → thinking{connecting} → tool_call/tool_result → final_message` — тело заполняется разом из `final_message`, прогресс-блок схлопывается. Для streamed (Anthropic: thinking+content дельты) — interim в блоке, финал в теле.

### Централизация (анти-дрейф)
Логика роутинга вынесена в чистый хелпер `computeStreamEventPatch(msg, event)` в `composables/chatHelpers.ts` (+ `extractStreamErrorMessage`). Все 5 композаблов (`useChatMessaging`, `useMiniChat`, `useReportGenerationModalChat`, `useWidgetGenerationModalChat`, `useDocumentGenerationModalChat`) теперь зовут общий `applyStreamPatch(...)` вместо дублированных `applyTextDelta` + inline `started`/`final_message`. Дублированные локальные `isTextDeltaPayload`/`applyTextDelta` удалены из всех пяти.
- `started` → `{status:'running'}`
- `text_delta kind='content'` → `{streamingContent: ...+delta}`
- `text_delta kind='thinking'` → `{thinkingContent: ...+delta}` (как было)
- `final_message` → `{content}`
- `error` → `{errorMessage: user_message, status:'error'}` (спиннер гаснет в момент прихода события, не дожидаясь settle)

### Рендер
- `ChatThinkingTimeline.vue` — новый prop `streamingContent`; пока `isWorking` и есть interim — рендерит блок «Черновик ответа» (`interimLabel`) внутри прогресс-блока; autoscroll следит и за ростом interim. Заголовок/схлопывание блока — как было (status-watcher).
- `ChatMessageBubble.vue` — тело рендерит ТОЛЬКО `content` (как было). Новый error-state: при `errorMessage` или `status==='error'` (и без канонического `content`) — алерт-блок с `pi-exclamation-triangle` вместо спиннера. `errorText` = `errorMessage` → `metadata.error.message` → `errors.aiTurnFailed`. Переведён на `useLocalI18n` (для доступа к `errors.aiTurnFailed`). Партиал-успех (есть и финал, и ошибка) → показываем ответ, не ошибку.
- После settle: `finalize` рефетчит чат; error переживает рефетч через `metadata.error.message` + `status='error'` (оба маппятся). `streamingContent` после settle не нужен (канон — в теле/ошибке).

### i18n
- Новый ключ `thinkingTimeline.interimLabel` (RU «Черновик ответа» / EN «Draft response») — chat-local locale. Симметрия RU↔EN проверена (chat-local + global — оба без orphan-ключей).

### Типы
- `api/types/chats.ts` — `ChatErrorPayload` (`exception_class`/`message`/`category`/`user_message`) + `ChatErrorCategory` (`context_overflow|rate_limit|timeout|other`).

### Файлы
- `entities/chat/types.ts` — +`streamingContent`, +`errorMessage` на `ChatMessage`.
- `api/types/chats.ts` — +`ChatErrorPayload`, +`ChatErrorCategory`.
- `components/chat/composables/chatHelpers.ts` — +`computeStreamEventPatch`, +`extractStreamErrorMessage`, +`isTextDeltaPayload` (перенесён сюда).
- `components/chat/composables/{useChatMessaging,useMiniChat,useReportGenerationModalChat,useWidgetGenerationModalChat,useDocumentGenerationModalChat}.ts` — `applyTextDelta`→`applyStreamPatch`, `onEvent` упрощён.
- `components/chat/ChatThinkingTimeline.vue` — interim-блок + prop.
- `components/chat/ChatMessageBubble.vue` — error-state + `streaming-content` проброс + `useLocalI18n`.
- `components/chat/locale/{en,ru}.json` — +`interimLabel`.

### Проверки
- `npm run type-check` — чисто.
- `npm run lint` — единственный warning `plugins/persist.ts` (trailing comma) pre-existing, файл не трогал.

### Заметки / риски
- Все чат-поверхности рендерят через общий `ChatMessageList → ChatMessageBubble → ChatThinkingTimeline`, фикс рендера централизован.
- `error`-патч ставит `status:'error'` сразу на событии — `isInFlight` становится false → бар-тайпинг и каретка гаснут немедленно (нет вечного спиннера).
- `category` из `error`-payload пока не используется в UI (зарезервирован под будущие per-mode retry-аффордансы); тип добавлен.
