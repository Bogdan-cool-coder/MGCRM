# Periphery Uplift — Волна 3 аудита консистентности (ТЗ)

> **Что это.** Подтягивание «периферии предыдущего поколения» (Каталог · Документы ·
> Онбординг · Автоматизации) к тулбар/фильтр/таблица-канону ядра 2.0. Это **визуальный
> uplift шасси экрана** — функциональность 1:1, ноль изменений в логике/данных/composables/API.
> Источник: `docs/audit/Frontend-Consistency-Audit-2026-07.md` (§2 карта поколений, §3 семейства
> б/в/ж/з, §4 модульные находки, Приложение A канон) + чтение самих экранов в коде на HEAD после
> навигационного среза.
>
> **Волна vs волна (важно).** Аудит предлагал 4 волны. Эта спека закрывает **только Волну 3
> (структурное шасси)**. Часть Волны 1 (мёртвые Bootstrap-утилиты) уже **вычищена в дереве**
> (scoped `.w-full`, `__muted`, `.text-center`→scoped-центрирование, карточные scaffold'ы —
> проверено пофайлово; см. §0.1). Мёртвые dark-токены канваса (Волна 2 §3е) и «швы» money/dropdown
> (Волна 4) — **вне этой спеки**. Где по ходу uplift мы всё равно касаемся файла — попутные
> Волна-1/2 хвосты чиним заодно и помечаем в per-screen «попутно».

**Владелец:** `designer` · **Реализация:** `sales-frontender` / `crm-frontender` /
`onboarding-specialist` / `automation-specialist` (по модулю) · **QA:** `qa-tester` (визуальный
гейт обе темы) · **Дата:** 2026-07-04

---

## 0. Канон (эталон, к которому приводим)

Всё уже существует в коде — **переиспользуем композицию, не изобретаем**. Точные референсы:

| Паттерн | Эталон (file) | Что берём |
|---|---|---|
| **Тулбар списка** | `DealsPage/components/DealsToolbar.vue` · `ContactsPage/components/ContactsToolbar.vue` | однорядный flex: **икон-плитка 38×38** (`$primary-100` bg, `pi` внутри, dark `color-mix($primary-900 35%, transparent)`) → **title-block** (h1 19px semibold + subtitle-каунтер 12px muted) → `spacer flex:1` → segmented → фильтр-триггер+бейдж → ⋮ → primary Create. Border-bottom `$surface-200` (dark `surface-600`), bg `$surface-card` |
| **Segmented** | `ContactsToolbar__type-switch` · `PipelineSettingsPage` SelectButton | трек `var(--p-surface-100)` радиус `$radius-md` padding 3px, активная таблетка `var(--p-surface-0)`+`$primary-900`+`$shadow-sm` (dark `surface-200`+text-color). Для 2-режимных «Форма/Полотно» — PrimeVue `SelectButton` (уже канон в PipelineSettings) |
| **Фильтр паттерн Б** (тяжёлые, 3+ фильтра) | `ContactsToolbar__filter-btn` + `DealsFilterOverlay` | **кнопка-триггер** `Button icon="pi pi-filter" outlined severity=secondary` с **оранжевым бейджем-каунтером** активных фильтров (`$orange-500`, `top/right:-7px`, `$radius-badge`), панель фильтров в оверлее/Popover. НЕ вечный инлайн-бар |
| **Фильтр паттерн А** (компактные, ≤2 фильтра + поиск) | `InboxPage/components/InboxSearchFilters.vue` | пилюля поиска с иконкой + `pi pi-sliders-h`-триггер + `Popover` панель с чип-секциями. Для экранов где хватает поиска + 1-2 селекта |
| **Таблица-карточка** | `ProductsPage` `.products-page__card` + DataTable | подложка `$surface-card` + `$radius-lg` + `1px $surface-200` + `$shadow-card` + `overflow:hidden`; `striped-rows`; клик-строка `cursor:pointer` |
| **Пагинация** | ключ `common.paginator.showing` = «Показано {from}–{to} из {total}» + `Paginator` | `Paginator` (rows-per-page `[25,50,100]`) + строка-каунтер под ним. Единая формулировка **«Показано X из Y»** заменяет «Загрузить ещё» и `«« ‹ 1 › »»` |
| **KPI-плитка** | `DashboardPage/components/WidgetKpiCard.vue` | икон-**тайл 28×28** с семантическим тинтом (`$primary-100`/`$status-success-bg`/…) + label 13px semibold + value `$font-size-icon-lg` bold + `$shadow-sm` подложка. Семантика: total→neutral, completed→success, in_progress→info, overdue→danger |
| **Empty / not-found** | `ProductsPage__empty` (filtered/catalog) · `DocumentPage__error` | **колонка по центру**: иконка `$font-size-icon-xl`/`-2xl` (opacity .4 или `$surface-400`) → заголовок md/lg semibold → hint sm muted → **CTA-кнопка**. При активных фильтрах — иконка `pi pi-filter-slash` + «Сбросить» |

**Токен-дисциплина (обязательна во всех правках):** только `$…`/`var(--p-*)`/`--mg-*`, ноль
литералов hex/px мимо шкал; muted-текст = `var(--p-text-muted-color)` (dark → surface-600, НЕ
surface-400); обе темы из theme-reactive токенов; dark-override только `.app-dark &` на
собственном scoped-элементе (закон 4 мёртвых селекторов — charter §4). Иконки — только `pi pi-*`.

### 0.1 Что УЖЕ вычищено (не трогаем повторно, но проверить при uplift)

Проверено пофайлово на текущем HEAD — эти Волна-1-находки аудита **уже исправлены**, спека их не
переоткрывает:
- **FileUpload — не нативный input.** `TemplateUploadCard.vue` и `DocumentAttachmentsTab.vue`
  **уже используют PrimeVue `FileUpload mode="basic" custom-upload`**. Аудит-находка «нативный
  `<input type=file>` карточки шаблона» (§3и, визуальный проход) — **стала неактуальной**. См.
  §B.2 «FileUpload-паттерн» — там только полировка, не замена.
- **Мёртвые Bootstrap-утилиты** в онбординге (`.w-full`, `__muted`, `__deadline--overdue`
  вместо `text-danger`, `.text-center`→`text-align:center`+scoped padding, card-scaffold вместо
  `.card`) — **уже переписаны на scoped-токены** в `CoursesFilterPanel`, `MyCoursesPage`,
  `HrProgressTable`, `OnboardingAssignmentsPage`, `MyOnboardingCertificatesPage`,
  `OnboardingAdminCoursesPage`, `AutomationRunsPage`. При uplift эти scoped-хелперы **сохраняем**.

---

## Приоритизация внутри волны

Порядок реализации — от «самого видимого» к косметике (инструкция PO):
1. **B — Карточка шаблона** (`TemplatePage`) и **C — списки онбординга** (5 экранов) — самое
   заметное «старое поколение», делаем первыми.
2. **B — DocumentsPage/DocumentPage** (тулбар + фильтр-триггер + action-bar полировка).
3. **A — Каталог** (ProductPage тулбар-контекст + not-found; ProductsPage — добивка).
4. **D — Автоматизации** (AutomationRunsPage тулбар/пагинатор; PipelineSettings segmented-полировка).

Каждый модуль — независимый PR (раздельные worktree для BE/FE не нужны — это FE-only).

---

## A. Каталог

### A.1 ProductsPage (`front/src/pages/ProductsPage/index.vue`) — **добивка, близок к канону**

Экран уже несёт карточную подложку таблицы, зебру, `pi-filter-slash`-empty, `Paginator` + total.
Осталось три структурных дельты.

**Что меняется:**
1. **Шапка `PageHeader` → тулбар-канон** (когда `!embedded`). Заменить `<PageHeader>` на
   inline-тулбар по образцу `DealsToolbar`: икон-плитка 38×38 (`pi pi-box`) → h1
   `catalog.products.page.title` + subtitle-каунтер (`common.total`/`{count}`) → spacer →
   **фильтр-триггер+бейдж** (см. п.2) → ⋮ (Import-меню перенести сюда: «Загрузить файл» /
   «Скачать шаблон») → primary **Create**. В `embedded`-режиме (внутри `/settings`) тулбар не
   рендерим — как сейчас `v-if="!embedded"`.
2. **Инлайн-фильтр-бар → паттерн Б.** Сейчас 4 контрола (поиск + group + pricing_type + status +
   reset) всегда в строке `.products-page__toolbar`. Свернуть в **кнопку-триггер «Фильтры»** с
   оранжевым бейджем (число активных из group/pricing/status; поиск оставить как есть — либо в
   тулбаре пилюлей, либо первым полем панели) + панель фильтров в `Popover`/оверлее (те же 3
   Select + reset). Логику `filter`/`applyFilter`/`resetFilter`/`isFiltered` **не трогаем** —
   только перенос в панель. **[ОВ-A1]** поиск оставить видимым в тулбаре или спрятать в панель?
   (рекомендую видимым — как ContactsToolbar).
3. **Заголовки таблицы → канон.** `sortable`-колонки сейчас с дефолтными PrimeVue-заголовками.
   Uppercase/центрированные `th` — как в DealsListView (через scoped `:deep(th)` или
   column-class). Косметика, low.

**Что НЕ трогаем:** `useProductsPageData`/`useProductsPageActions`, expand-row + ценовая
подтаблица, `getBasePrice`/`formatCurrency`, ToggleSwitch активности, kebab-row-menu edit-mode,
`/admin/products/:id`-навигация, embedded-контракт.

**Попутно (Волна-1/2 хвост):** ссылка-товар `$primary-color` статик → `var(--p-primary-color)`
(dark); `$surface-400` no-price/empty muted — оставить (это placeholder-цвет, ок).

**Референс:** `DealsToolbar.vue` (шапка) · `ContactsToolbar__filter-btn`+бейдж (фильтр-триггер) ·
существующий `ProductsPage__card`/`__empty` (не трогаем — уже канон).

### A.2 ProductPage (`front/src/pages/ProductPage/index.vue`) — **MIXED: контекст шапки**

Карточка товара: PageHeader + back + PricingTypeTag + Edit; таб «Цены/Сделки»; правый рейл.

**Что меняется:**
1. **Шапка** — оставляем `PageHeader` (карточные страницы = легальный остаток PageHeader,
   charter §2.1; это НЕ list-экран). Дельта — привести action-slot к канону: back — `text`,
   Edit — `outlined secondary`, PricingTypeTag между ними. Уже почти так; проверить порядок
   (Create/primary справа — здесь primary нет, Edit — вторичка, ок).
2. **not-found → канон deal-not-found.** Сейчас голый `<Message severity="error">` + Back. Заменить
   на канонную колонку (как `DocumentPage__error`): иконка `pi pi-box` (или
   `pi-exclamation-triangle`) `$font-size-icon-2xl` opacity .4 → заголовок
   `catalog.product.page.errors.notFound` → hint (опц.) → **CTA** `Button outlined "Назад к списку"`
   → `/admin/products`. Это ключевая дельта экрана.
3. Табы-карточка `.product-page__tabs-card` уже канон (`$surface-card`+`$radius-lg`+`$shadow-card`)
   — не трогаем.

**Что НЕ трогаем:** `useProductPageData`/`useProductPageActions`, табы Цены/Сделки, правый рейл,
edit-drawer, plan-dialog, `#subtitle`-код/группа.

**Референс:** `DocumentPage/index.vue` `__error`-блок (эталон not-found колонки).

---

## B. Документы

### B.1 TemplatesPage (`front/src/pages/TemplatesPage/index.vue`) — **список: тулбар-канон**

Сейчас: PageHeader + инлайн `d-flex` фильтр-строка (Select kind + поиск) + `<Card>`→DataTable +
кастомный empty.

**Что меняется:**
1. **PageHeader → тулбар-канон** (`!embedded`): икон-плитка 38×38 (`pi pi-file-edit`) → h1
   `templates.list.title` + subtitle-каунтер → spacer → **фильтр (паттерн А)** — kind-Select ≤1
   фильтр, так что достаточно компактной строки поиск+kind, либо триггер+Popover; **реши: kind — 1
   селект → паттерн А (поиск-пилюля + один Select рядом), не заводи бейдж-триггер ради одного
   фильтра** → primary **Create** (canManage).
2. **Таблица.** `<Card>`-обёртка → канонная `.…__card`-подложка
   (`$surface-card`+`$radius-lg`+`$shadow-card`+`overflow:hidden`), `striped-rows`, клик-строка.
   Заголовки uppercase. `aiStatusSeverity`/Tag — не трогаем.
3. **Empty** — уже колонка с иконкой+текст+CTA (`#empty` слот). Привести к канону:
   иконка `$font-size-icon-xl`, заголовок, CTA primary-или-text. Добавить filtered-ветку
   (`pi-filter-slash` + «Сбросить») если активен kind/поиск.
4. **Дата** — `toLocaleDateString('ru-RU')` ок (уже ru-RU).

**Что НЕ трогаем:** `useTemplatesPage` (kindFilter/searchFilter/templates/goToTemplate),
`CreateTemplateDialog`, роль-гейт `canManage`, embedded-проп.

### B.2 TemplatePage — карточка шаблона (`front/src/pages/TemplatePage/`) — **OLD-GEN, самое видимое**

Сейчас: `padding: 0.75rem` (сырой rem), PageHeader, 2-колоночный `row g-4`, `<Card>`-подложки
(`TemplateUploadCard`/`TemplateAiCheckCard`/`TemplateVersionsCard`/`TemplateMetaCard`), not-found
голым `<p>`+Back.

**Что меняется:**
1. **Шапка — navy-контекст.** Инструкция PO: «navy-контекст шапки». Но `PageHeader` — не
   navy-компонент (это `$surface-card`-шапка list/детальных страниц). **Решение [спорное, см.
   §Спорные]:** карточка шаблона — это **детальная карточка сущности**, ближайший канон её
   шапки — **не** `EntityInfoHeader` (navy, только для Contact/Company/Deal), а обычный
   `PageHeader` (как у ProductPage/DocumentPage — они тоже детальные и остаются на PageHeader).
   **Не вводим navy-шапку** для шаблона: это раздуло бы паттерн (шаблон ≠ CRM-сущность с
   аватаром/KPI/лентой). Приводим PageHeader-action-slot к канону: back `text` + Edit
   `outlined secondary` (`canWrite`). Сырой `0.75rem` padding → `$space-3`.
2. **not-found → канон.** `<div class="template-page__error">` с `<p>`+Back-кнопкой → канонная
   колонка (как DocumentPage__error): иконка `pi pi-file-edit`/`pi-exclamation-triangle`
   `$font-size-icon-2xl` opacity .4 → заголовок `templates.card.notFound` → **CTA** Back
   `outlined`. Сырой `padding: 4rem` → `$space-8`+.
3. **Карточные подложки контента.** Аудит: «карточные подложки контента» — под-карточки
   (`TemplateUploadCard` и др.) уже на PrimeVue `<Card>`. Дельта: `<Card>` даёт дефолтный
   PrimeVue-фон/тень; чтобы совпасть с каноном ядра (одинаковая `$radius-lg`+`$shadow-card`
   подложка) — либо оставить `<Card>` (визуально приемлемо), либо привести к общему
   `.…__panel`-рецепту. **Реши per-card: оставляем `<Card>`** (это валидный PrimeVue-примитив,
   не самоделка); только убрать сырые rem-отступы внутри на `$space-*`. Косметика.
4. **FileUpload-паттерн.** `TemplateUploadCard` **уже** `FileUpload mode="basic" custom-upload`
   (`accept=".docx"`, `max-file-size 20MB`). Дельта — **только визуальная полировка**:
   - `choose-label` уже i18n (Заменить/Загрузить) — ок;
   - hint-текст `p.text-secondary` — `text-secondary` мёртвый Bootstrap-класс → заменить на
     scoped `.template-upload-card__hint { color: var(--p-text-muted-color) }` (уже частично есть
     `__hint`-класс — добавить туда цвет). `mb-3`/`mt-2` (Bootstrap spacing) — заменить на scoped.
   - **[ОВ-B1]** хотим ли drop-zone-стиль (drag&drop area) вместо `mode="basic"` (компактная
     кнопка)? В системе **нет готового dropzone**-компонента; `FileUpload mode="advanced"` даёт
     drag&drop, но это заметное изменение UX и веса. **Рекомендую оставить `mode="basic"`**
     (консистентно с `DocumentAttachmentsTab`, минимально) — если PO хочет dropzone, это отдельная
     задача не в рамках uplift.

**Что НЕ трогаем:** `useTemplatePage`, upload/AI-check/versions/meta-логику, edit-dialog, роль-гейт
`canWrite`, обработку версий/`uploadVersion`/`recheckVersion`/`confirmOverride`.

**Референс:** `DocumentPage/index.vue` (2-колоночный `row g-4` + not-found + PageHeader — сестра
шаблона по паттерну).

### B.3 DocumentsPage (`front/src/pages/DocumentsPage/index.vue`) — **тулбар + фильтр-триггер**

Сейчас: PageHeader + **`DocumentsFilterPanel`** (вечная `<Card>` с 4 контролами: status/kind/поиск/
archived-checkbox/reset) + `<Card>`→DataTable + `#empty` (уже канон с filtered-веткой) + Paginator.

**Что меняется:**
1. **PageHeader → тулбар-канон:** икон-плитка `pi pi-file-edit` → h1 `documents.list.title` +
   subtitle-каунтер (`common.total`/{count}) → spacer → **фильтр-триггер+бейдж** (паттерн Б — тут
   3-4 фильтра, оправдан бейдж) → primary **Create** (canCreate).
2. **`DocumentsFilterPanel` вечная Card → панель за триггером.** Содержимое панели (status/kind/
   поиск/archived/reset) **сохраняем 1:1**, но рендерим в `Popover`/оверлее по клику на триггер,
   не вечной строкой. Бейдж-каунтер = число активных из `hasActiveFilters`-компонентов (status/
   kind/search непусты/archived=true). `v-model="filter"` + `@reset` — интерфейс панели не меняем,
   только обёртку.
3. **Таблица** `<Card>` → канонная `.…__card`-подложка (или оставить Card — она визуально близка;
   **реши: привести к `$surface-card`+`$radius-lg`+`$shadow-card`** для единства с ProductsPage).
   `striped-rows`, uppercase-th. `DocumentStatusTag`/row-menu — не трогаем.
4. **Empty** — уже канон (filtered + пустой варианты с иконкой/CTA). Не трогаем.
5. **Paginator** — уже есть; добавить строку-каунтер «Показано X из Y» (`common.paginator.showing`)
   под таблицей для единства (сейчас только Paginator без каунтера).

**Что НЕ трогаем:** `useDocumentsPage`, `DocumentsFilterPanel`-контролы (только обёртка),
`CreateDocumentDialog`, row-menu (Открыть/В архив), `formatDate`, canCreate-гейт.

### B.4 DocumentPage (`front/src/pages/DocumentPage/index.vue`) — **шапка/action-bar полировка**

Сейчас: PageHeader + StatusTag; отдельная строка **`DocumentActionBar`** (back + генерация/скачать/
submit/sign/archive/⋮); 2-колонка (табы + approval/meta); not-found уже канон (`__error` с иконкой).

**Что меняется:**
1. **Шапка** — оставляем PageHeader (детальная карточка). StatusTag в action-slot — ок.
   Сырой `padding: 0.75rem` → `$space-3`; `4rem 1rem` в `__error` → `$space-8`.
2. **`DocumentActionBar` полировка.** Сейчас `d-flex flex-wrap ms-auto` + куча Button.
   `d-flex`/`ms-auto`/`flex-wrap` — валидные grid+flex-утилиты (в бандле есть), scoped `gap`
   уже задан → **работает, не трогаем структуру**. Дельта — привести severity к канону
   (аудит §4 catalog+docs low: «внеканонные severity кнопок в тулбаре»): sign = `success` (ок),
   unsign = `danger outlined` (ок), archive = `secondary text` (ок), generate = primary (ок).
   Проверить, что download-кнопки — `secondary outlined` (ок). По сути action-bar **уже
   консистентен** — только визуальная сверка, минимальная дельта.
3. Табы-карточка `<Card>` + `:deep(.p-card-body) padding 0.75rem` → `$space-3`. Косметика.

**Что НЕ трогаем:** `useDocumentPage`/`useDocumentApproval`, все табы (context/items/revisions/
remarks/attachments), ApprovalPanel, DocumentMetaCard, DecideDialog, docMenu, autosave, все
show*-computed action-bar'а (status-машина).

**Референс:** `DocumentPage__error` уже эталон not-found (используем как референс для B.2/A.2).

---

## C. Онбординг (5 list/hub-экранов)

Общий диагноз: все на `PageHeader`; фильтр-бары инлайн; таблицы без карточной подложки (но
scoped-хелперы для мёртвых Bootstrap-классов уже добавлены — §0.1); KPI свой рецепт; пагинация
разнобойная (PrimeVue-`paginator` встроен в DataTable, но без единого каунтера).

### C.1 MyCoursesPage (`front/src/pages/MyCoursesPage/index.vue`) — карточки курсов студента

Сейчас: PageHeader + Tabs(active/completed/overdue с Badge) + `row g-3` карточек `MyCourseCard`.

**Что меняется:**
1. **PageHeader → тулбар-канон:** икон-плитка `pi pi-book` → h1 `onboarding.myCourses.title` +
   subtitle-каунтер (`allCount`) → spacer → **segmented Активные/Завершённые/Просроченные** (сейчас
   `Tabs`+`Badge` — заменить на segmented-таблетки как ContactsToolbar `type-switch`, с
   count-суффиксом; overdue-таблетка с danger-акцентом бейджа). Create тут нет (студент). Табы
   были 3-режимные → segmented допускает 3 сегмента (трек + 3 таблетки).
   **[ОВ-C1]** segmented vs оставить PrimeVue Tabs? Канон тулбара = segmented-таблетки; но Tabs с
   Badge тоже читаемы. **Рекомендую segmented** для единства с Задачами (Мои/Команда) и Контактами.
2. **Карточки** `MyCourseCard` — сетка `row g-3` ок; сами карточки — отдельный компонент (не в
   скоупе шасси, но проверить: `text-danger` в MyCourseCard уже? §0.1 говорит fixed).
3. **Empty** — уже колонка (2 варианта: нет назначений / пусто в табе). Привести иконку к
   `$font-size-icon-2xl`, добавить CTA если уместно (напр. «нет назначений» — без CTA, ок).
4. `p-4` (Bootstrap padding) обёртки → scoped `$space-4`.

**Что НЕ трогаем:** `useMyCoursesPage` (loading/error/activeTab/filteredAssignments/counts/load),
`MyCourseCard`, skeleton-карточки (scaffold уже добавлен).

### C.2 MyOnboardingCertificatesPage (`front/src/pages/MyOnboardingCertificatesPage/index.vue`)

Сейчас: PageHeader + `row g-3` карточек `CertificateCard` + empty (иконка+заголовок+hint+CTA — уже
канон!).

**Что меняется:**
1. **PageHeader → тулбар-канон:** икон-плитка `pi pi-verified` → h1 + subtitle-каунтер
   (`certificates.length`). Фильтров/сегментов нет → просто плитка+title+spacer. Create нет.
2. **Empty** — уже эталонный (иконка `$font-size-icon-3xl` + заголовок + hint + CTA «К курсам»).
   Не трогаем — это референс для остальных.
3. `p-4` → scoped `$space-4`.

**Что НЕ трогаем:** `useMyCertificatesPage`, `CertificateCard`, download-логику.

### C.3 OnboardingAdminCoursesPage (`front/src/pages/OnboardingAdminCoursesPage/index.vue`) — admin-список

Сейчас: PageHeader + Create; **`CoursesFilterPanel`** инлайн (`row g-3`: status/policy Select +
поиск + reset); DataTable **без карточной подложки** (`paginator` встроенный) + `#empty` (канон);
россыпь action-иконок в последней колонке (pencil/publish/unpublish/delete).

**Что меняется:**
1. **PageHeader → тулбар-канон:** икон-плитка `pi pi-graduation-cap` → h1
   `onboarding.courses.title` + subtitle-каунтер (`totalRecords`) → spacer → **фильтр-триггер+бейдж**
   (status+policy+поиск = 3 фильтра → паттерн Б оправдан) → primary **Create**.
2. **`CoursesFilterPanel` инлайн → панель за триггером.** Контролы 1:1 (status/policy Select +
   поиск + reset), в Popover. Бейдж = число активных (`filters.status`/`completion_policy`/`search`
   непусты). `@change`/`@reset` — не трогаем.
3. **DataTable → карточная подложка.** Обернуть в `.…__card` (`$surface-card`+`$radius-lg`+
   `$shadow-card`+`overflow:hidden`), `striped-rows`, uppercase-th. Клик-строка ведёт в
   CourseBuilder (уже router-link в колонке title — оставить).
4. **Россыпь action-иконок → kebab-меню.** Сейчас в колонке 4 icon-Button (pencil/publish/
   unpublish/delete) в ряд. **По инструкции PO: kebab здесь логичен (не Волна 4).** Заменить на
   один `Button icon="pi pi-ellipsis-v" text` → PrimeVue `Menu popup` с пунктами: «Редактировать»
   (pencil), «Опубликовать»/«Снять с публикации» (условно по `is_published`), «Удалить» (danger,
   только если `!is_published`). Действия/handlers (`onPublish`/`onUnpublish`/`onDelete`,
   `$router.push CourseBuilder`) **1:1**.
5. **Пагинация.** DataTable уже `paginator` встроенный (`[25,50,100]`). Добавить единый
   каунтер-строку «Показано X из Y» под таблицей (`common.paginator.showing`, from/to из
   filters.page/per_page/totalRecords). **[ОВ-C2]** оставить встроенный DataTable-paginator +
   каунтер, или вынести отдельный `<Paginator>` как в ProductsPage? **Рекомендую оставить
   встроенный** (меньше изменений) + добавить каунтер-строку.
6. **Empty** — уже канон (filtered/пустой + CTA/reset). Не трогаем.

**Что НЕ трогаем:** `useAdminCoursesPage` (courses/loading/totalRecords/filters/handlers/
policyLabel), `CreateCourseDialog`, `CourseStatusTag`, router-link title.

### C.4 OnboardingAssignmentsPage (`front/src/pages/OnboardingAssignmentsPage/index.vue`) — назначения

Сейчас: PageHeader + Assign-Button; **`AssignmentsFilterPanel`** инлайн; DataTable без подложки
(ProgressBar в progress-колонке; overdue-дедлайн через scoped `__deadline--overdue`); 3 action-иконки
(calendar/archive/delete); `#empty`.

**Что меняется:**
1. **PageHeader → тулбар-канон:** икон-плитка `pi pi-users` → h1 `onboarding.assignments.title` +
   subtitle-каунтер → spacer → **фильтр-триггер+бейдж** (`AssignmentsFilterPanel`; **[ОВ-C3]**
   сколько фильтров? если ≤2 — паттерн А, если 3+ — паттерн Б; реши по факту содержимого панели —
   прочитай `AssignmentsFilterPanel.vue`) → primary **Assign**.
2. **Панель фильтров** — контролы 1:1 в Popover/оверлее (или компактный inline по образцу
   Контактов, если ≤2 фильтра). `@change`/`@reset` не трогаем.
3. **DataTable → карточная подложка** + `striped-rows` + uppercase-th. ProgressBar-колонка и
   `__deadline--overdue` (scoped `var(--p-red-500)`) — оставить.
4. **Action-иконки → kebab.** 3 icon-Button (calendar «Изменить дедлайн» / box «В архив» / trash
   «Удалить» — последняя только если `progress_pct===0`) → kebab `Menu popup`. Действия 1:1
   (`openEditDeadline`/`archiveAssignment`/`deleteAssignmentConfirm`).
5. **Empty** — сейчас только иконка + `<p>` (нет заголовка/hint/CTA). Довести до канона: заголовок
   + hint + (для не-filtered) CTA «Назначить». Для filtered — `pi-filter-slash` + reset.
6. **Пагинация** — DataTable-встроенный paginator + каунтер-строка (как C.3).

**Что НЕ трогаем:** `useAssignmentsPage`, `EditDeadlineDialog`, `AssignCourseDrawer`,
`AssignmentStatusTag`, ProgressBar, `formatDate`.

### C.5 HrProgressPage (`front/src/pages/HrProgressPage/index.vue`) — HR-дашборд

Сейчас: PageHeader + **`HrKpiCards`** (4 плитки, свой рецепт) + charts row (pie + top-courses
ECharts) + `<Card>` с `HrProgressFilterPanel` (инлайн) + `HrProgressTable`.

**Что меняется:**
1. **PageHeader → тулбар-канон** (hub-экран, аналог DashboardPage): икон-плитка `pi pi-chart-bar`
   → h1 `onboarding.hrProgress.title` → spacer. Фильтров в шапке нет (фильтр живёт над таблицей).
   Create нет.
2. **`HrKpiCards` → WidgetKpiCard-рецепт.** Сейчас 4 `<Card>` со своим `.hr-kpi-card` (иконка
   `$font-size-icon-lg` без тайла-подложки, value-цвета palette). Привести к канону
   `WidgetKpiCard`: **икон-тайл 28×28** с семантическим тинтом-фоном (total→neutral `$surface-100`/
   `$surface-600`; completed→`$status-success-bg`/`$status-success-text`; in_progress→info
   `$status-info-bg`; overdue→`$status-danger-bg`/`$status-danger-text`), label 13px semibold,
   value `$font-size-icon-lg` bold `$surface-900`, подложка `$surface-card`+`$radius-lg`+
   `$shadow-sm`. **Реши [спорное]: узаконить общий `WidgetKpiCard`?** — `WidgetKpiCard` жёстко
   завязан на `StatusGroup`-модель дашборда (trend/amount/deal-семантика). Для HR у нас другой shape
   (total/completed/in_progress/overdue — счётчики без денег/тренда). **Рекомендую: не
   переиспользовать `WidgetKpiCard` напрямую, а привести `HrKpiCards` к тому же визуальному рецепту
   плитки** (икон-тайл 28×28 + семантический тинт + shadow-подложка), оставив HR-специфичный shape.
   Это «узаконенный summary-стиль по образцу WidgetKpiCard», а не общий компонент. См. §Спорные.
3. **`HrProgressFilterPanel` инлайн → канон.** Живёт внутри `<Card>` над таблицей. **[ОВ-C4]**
   сколько фильтров? если 1-2 (напр. course-Select + поиск) — компактный inline по образцу
   Контактов (не прячем — панель уже в контексте таблицы); если 3+ — триггер+Popover. Реши по
   содержимому. Дубль-логику с `AssignmentsFilterPanel` не унифицируем (вне скоупа).
4. **`HrProgressTable`** — сейчас DataTable без явной карточной подложки, но живёт внутри
   `<Card>`-обёртки страницы. Привести таблицу к канону (`striped-rows` уже? — добавить; uppercase-th).
   ProgressBar/`__deadline--overdue`/`AssignmentStatusTag` — не трогаем. Пагинация — встроенный
   paginator + каунтер-строка.
5. **Charts** (`HrStatusPieChart`/`HrTopCoursesChart`) — ECharts в `<Card>`. Аудит (§4 onboarding
   low): «Card графиков дублирует widget-card не переиспользуя». Косметика — привести Card к
   `$surface-card`+`$radius-lg`+`$shadow-card` подложке для единства с KPI. Не трогаем сами графики.

**Что НЕ трогаем:** `useHrProgressPage` (summary/rows/loading/filters/handlers), ECharts-конфиги,
`HrProgressTable`-логику, `AssignmentStatusTag`, `HrProgressFilterPanel`-контролы.

**Референс:** `WidgetKpiCard.vue` (KPI-рецепт) · `HrProgressPage` charts-Card.

---

## D. Автоматизации

### D.1 AutomationRunsPage (`front/src/pages/AutomationRunsPage/index.vue`) — журнал запусков

Сейчас: PageHeader (`!embedded`); **инлайн фильтр-строка** (4 Select/DatePicker + «Применить» +
«Пробный запуск»); Message-error; DataTable **без карточной подложки** (`striped-rows`,
`:deep(th) surface-50`); **«Загрузить ещё» + счётчик** вместо пагинатора; DryRunDrawer.

**Что меняется:**
1. **PageHeader → тулбар-канон** (`!embedded`): икон-плитка `pi pi-clock` → h1
   `automation.runs.pageTitle` + subtitle-каунтер (число загруженных / total если есть) → spacer →
   **фильтр-триггер+бейдж** (4 фильтра: automation/status/action/date → паттерн Б) → **«Пробный
   запуск»** `Button secondary outlined` (справа, disabled без выбранной автоматизации).
   В `embedded` (внутри /settings) — тулбар не рендерим (как сейчас `v-if="!embedded"`), но фильтры
   всё равно показываем.
2. **Инлайн-фильтр-строка → панель за триггером.** 4 контрола (automation/status/action Select +
   date-range DatePicker) + «Применить» → в Popover/оверлее по триггеру. «Применить»-кнопка внутри
   панели. Бейдж = число активных фильтров. `page.filter*`/`fetchRuns` — не трогаем.
3. **DataTable → карточная подложка** (`$surface-card`+`$radius-lg`+`$shadow-card`+`overflow:hidden`).
   `striped-rows` есть. `:deep(th) surface-50` → uppercase-th. Status-Tag/target/error-tooltip — не
   трогаем.
4. **«Загрузить ещё» → канон-пагинатор ИЛИ узаконенный infinite.** **Решение [спорное, см.
   §Спорные]:** журнал запусков — по природе **растущий лог**, «Загрузить ещё» + счётчик
   («Показано N») — валидный infinite-паттерн. Аудит клеймит его как расхождение с
   «Показано X из Y», но `useAutomationRuns` — cursor/offset-based load-more (`hasMore`/`loadMore`),
   не total-paginated. **Рекомендую: узаконить load-more**, но привести формулировку счётчика к
   канону — вместо `automation.runs.count`={n} показать «Показано N» (если `total` доступен — «из
   Y»), кнопку «Загрузить ещё» оставить `secondary outlined` (уже так). Если backend отдаёт `total`
   → можно и полноценный `<Paginator>`; **[ОВ-D1]** отдаёт ли `useAutomationRuns` total? — прочитать
   composable; если нет — оставляем load-more.
5. **Empty** — сейчас иконка + `<p>` (без заголовка/CTA — тут CTA не нужен, это лог). Добавить
   заголовок; при активных фильтрах — `pi-filter-slash` + «Сбросить фильтры».

**Что НЕ трогаем:** `useAutomationRuns` (runs/loading/filters/hasMore/loadMore/fetchRuns/
selectedAutomation), DryRunDrawer, `statusSeverity`/`formatTarget`/`formatDateTime`/`truncate`,
Message-error, embedded-проп.

**Попутно (Волна-1 §3з):** битые иконки в связанных automation-файлах (`pi-pencil-square`→
`pi-pencil`, `pi-arrow-right-circle`→`pi-arrow-circle-right`) — **вне этого экрана**, но если
касаемся — чинить; формально Волна 1, не переоткрываем здесь.

### D.2 PipelineSettingsPage (`front/src/pages/PipelineSettingsPage/index.vue`) — тулбар «Форма/Полотно»

Сейчас: PageHeader (скрыт в canvas-режиме); canvas-bar (Select + spacer + **SelectButton
Форма/Полотно**); form-layout (rail + StageEditorList + AutomationListPanel); mode-bar
(SelectButton).

**Что меняется — минимально (segmented уже канон):**
1. **SelectButton «Форма/Полотно» — уже канон** (`SelectButton :allow-empty="false"`,
   option-label/value). PO просит «плитка-иконка + заголовок по канону, segmented как в хабе».
   Дельта:
   - **Плитка-иконка тулбара**: PageHeader form-режима сейчас без икон-плитки-по-канону. При uplift
     привести к тулбар-канону: икон-плитка 38×38 `pi pi-sliders-h` + h1 `sales.pipelineEditor.
     pageTitle`. Но **осторожно** — form-режим имеет 2-колоночный rail-layout (Гэп-4), PageHeader
     тут держит верх; canvas-режим намеренно **без** PageHeader (экономия высоты). **Решение:**
     заменить `PageHeader` form-режима на тулбар-строку с икон-плиткой (как DealsToolbar) + справа
     SelectButton — но **только в form-режиме**; canvas-bar оставить как есть (компактный).
   - **SelectButton** визуально сверить с segmented хаба (`HubToolbar`): если хаб использует
     `SelectButton` с тем же токен-набором — ничего не меняем; если различается padding/радиус —
     подтянуть. По сути **это самая лёгкая часть волны** — segmented уже есть.
2. **canvas-mode chrome** (canvas-bar) — не трогаем (намеренно компактный, отдельный UX).

**Что НЕ трогаем:** `usePipelineSettings`/`usePipelineAutomations`, form/canvas-layout, PipelineList,
StageEditorList, AutomationListPanel, PipelineCanvas, все диалоги/дравер/визард, `viewMode`-логику,
`canvasMountSeq`.

**Примечание:** мёртвые PrimeVue-3 токены канваса (§3е, `--p-surface-card`/`-border`/`-ground`) —
**Волна 2, вне этой спеки**. Здесь только тулбар form-режима + segmented-сверка.

---

## FileUpload-паттерн (сводно)

**Вывод: замена не требуется.** Оба места загрузки файлов в модуле Документы **уже** используют
PrimeVue `FileUpload mode="basic" custom-upload`:
- `TemplatePage/components/TemplateUploadCard.vue` — `.docx`, 20 MB, `@select`;
- `DocumentPage/components/DocumentAttachmentsTab.vue` — `.pdf/.docx/.jpg/.png`, 20 MB, в Dialog.

**В системе НЕТ готового dropzone-компонента** (grep: file-input встречается только в этих +
`EntityFilesTab`/`ProfileHeroCard`/`PriceImportDialog`/`LessonEditDrawer`/`TerminationDocumentDrawer`).
`FileUpload mode="basic"` = компактная кнопка выбора (канон). `mode="advanced"` = drag&drop-зона
(тяжелее, другой UX). **Рекомендация: держим `mode="basic"` везде** для консистентности; полировка —
только заменить мёртвые `text-secondary`/`mb-*` Bootstrap-классы на scoped-токены в
`TemplateUploadCard` (§B.2 п.4). Если PO хочет визуальную drop-зону — **отдельная задача**, не uplift.

---

## Спорные решения (где выбрал между вариантами)

1. **navy-шапка карточки шаблона (B.2).** PO написал «navy-контекст шапки». **Выбрал: НЕ вводить
   navy-шапку.** Navy-хедер (`EntityInfoHeader`/`$brand-header-bg #172747`) — бренд-инвариант
   **только для CRM-сущностей** (Contact/Company/Deal: аватар + KPI + лента). Шаблон документа —
   детальная карточка-настройка, её сёстры (ProductPage/DocumentPage) сидят на обычном `$surface-card`
   PageHeader и остаются на нём. Ввод navy для шаблона раздул бы паттерн и создал ложную аналогию
   «шаблон = сущность». Привожу к канону PageHeader-action-slot + not-found, без navy. **Если PO
   имел в виду именно navy-плашку — вернуть на доработку.**

2. **HrKpiCards → WidgetKpiCard vs узаконенный summary-стиль (C.5).** **Выбрал: узаконенный
   summary-стиль по рецепту WidgetKpiCard, НЕ прямое переиспользование компонента.** `WidgetKpiCard`
   жёстко завязан на `StatusGroup` (amount_kopecks/trend_pct/deal-семантика/formatMoney) — HR-shape
   иной (счётчики без денег/тренда). Форсить общий компонент = либо ломать его props, либо мимикрия.
   Привожу `HrKpiCards` к **визуальному рецепту** плитки (икон-тайл 28×28 + семантический тинт +
   shadow-подложка), сохраняя HR-специфичный shape. Это «узаконенный summary-стиль», зафиксирую в
   charter §2.2 при поставке.

3. **AutomationRuns: load-more vs пагинатор (D.1).** **Выбрал: узаконить infinite load-more**,
   привести формулировку счётчика к «Показано N (из Y)». Журнал запусков — растущий cursor/offset
   лог (`hasMore`/`loadMore`), не total-paginated список. Полный `<Paginator>` требует стабильного
   `total` и page-based fetch — переписывать composable ради косметики пагинации не оправдано (это
   меняло бы поведение, а волна = визуал 1:1). **[ОВ-D1]** — если composable уже отдаёт `total`,
   можно и Paginator; проверить при реализации.

4. **Onboarding segmented vs Tabs (C.1).** **Выбрал: segmented-таблетки** (Активные/Завершённые/
   Просроченные) вместо PrimeVue Tabs+Badge — для единства с Задачами (Мои/Команда) и Контактами.
   Tabs тоже читаемы, но тулбар-канон = segmented в правой части однорядной шапки.

5. **Фильтры паттерн А vs Б per-screen.** Правило: **≥3 фильтров → паттерн Б** (кнопка-триггер +
   оранжевый бейдж + Popover-панель: DocumentsPage, OnboardingAdminCourses, AutomationRuns,
   ProductsPage). **≤2 фильтра + поиск → паттерн А/компактный inline** (TemplatesPage: 1 kind-Select;
   HrProgress/Assignments — зависит от факта, помечено [ОВ]). Не заводим бейдж-триггер ради одного
   фильтра.

6. **Kebab в списках курсов/назначений (C.3/C.4).** PO явно разрешил («kebab в списках курсов
   логичен тут»). Свернул россыпь 3-4 icon-Button в один `pi-ellipsis-v` → `Menu popup`. Условные
   пункты (publish/unpublish по `is_published`; delete только при `!is_published`/`progress===0`)
   сохранены через `v-if`/disabled в пунктах меню. Действия 1:1.

7. **Card vs `.__card` подложка таблиц.** Где таблица уже в PrimeVue `<Card>` (TemplatesPage/
   DocumentsPage) — **привожу к общей `$surface-card`+`$radius-lg`+`$shadow-card` подложке** (как
   ProductsPage) для единства, а не оставляю дефолтный Card. Card — валидный примитив, но его
   дефолтная тень/радиус слегка отличаются от карточного канона ядра; единый рецепт важнее.

---

## Acceptance-чеклист (qa-tester, по модулям — обе темы light+dark)

**Общее (каждый экран):**
- [ ] Шапка = однорядный тулбар с икон-плиткой 38×38 (`$primary-100` bg, dark `color-mix`), h1 19px,
      subtitle-каунтер muted; НЕ `PageHeader` (кроме детальных карточек ProductPage/TemplatePage/
      DocumentPage — там PageHeader легален).
- [ ] Ноль литеральных hex/px мимо токенов (`npm run lint:ds` зелёный); muted = `var(--p-text-muted-color)`.
- [ ] Dark: текст читаем (нет surface-100/200 как текст, нет surface-700/800 как светлые плиты-фоны);
      акцент = `var(--p-primary-color)` (не статик navy).
- [ ] Функциональность 1:1 с prod-baseline (фильтры/CRUD/навигация/пагинация работают как до uplift).

**A. Каталог:**
- [ ] ProductsPage: тулбар + фильтр-триггер+бейдж (число активных) + Popover-панель; таблица-карточка;
      Import в ⋮; Create primary справа; embedded-режим без тулбара.
- [ ] ProductPage: not-found = колонка (иконка `$font-size-icon-2xl` + заголовок + CTA «Назад к списку»),
      НЕ голый `<Message>`.

**B. Документы:**
- [ ] TemplatesPage: тулбар (плитка `pi-file-edit`) + компактный фильтр (kind+поиск) + Create;
      таблица-карточка; empty с CTA + filtered-ветка.
- [ ] TemplatePage: PageHeader-action канон (back text + Edit outlined); not-found = канон-колонка;
      FileUpload `mode="basic"` работает (выбор .docx → upload); hint через `var(--p-text-muted-color)`
      (не мёртвый `text-secondary`); padding токенизирован.
- [ ] DocumentsPage: тулбар + фильтр-триггер+бейдж (Popover, содержимое DocumentsFilterPanel 1:1);
      таблица-карточка; Paginator + каунтер «Показано X из Y».
- [ ] DocumentPage: PageHeader + StatusTag; action-bar severity-канон (sign success / unsign danger
      outlined / archive secondary text / generate primary); padding токенизирован; not-found уже канон.

**C. Онбординг:**
- [ ] MyCoursesPage: тулбар + segmented (Активные/Завершённые/Просроченные с count, overdue danger);
      карточки-грид; empty канон.
- [ ] MyOnboardingCertificatesPage: тулбар (`pi-verified`); empty эталон (не трогать); карточки-грид.
- [ ] OnboardingAdminCoursesPage: тулбар + фильтр-триггер+бейдж + Create; таблица-карточка;
      **kebab-меню** вместо россыпи иконок (Ред./Опубл./Снять/Удалить условно); каунтер «Показано X из Y».
- [ ] OnboardingAssignmentsPage: тулбар + фильтр (А/Б по факту) + Assign; таблица-карточка; **kebab**
      (Дедлайн/Архив/Удалить условно); empty с заголовком+CTA; каунтер.
- [ ] HrProgressPage: тулбар-hub (`pi-chart-bar`); **KPI-плитки по рецепту WidgetKpiCard** (икон-тайл
      28×28 + семантический тинт: total-neutral/completed-success/in_progress-info/overdue-danger +
      shadow-подложка); фильтр канон; таблица-карточка; charts-Card единая подложка; каунтер.

**D. Автоматизации:**
- [ ] AutomationRunsPage: тулбар (`pi-clock`, `!embedded`) + фильтр-триггер+бейдж + «Пробный запуск»
      справа; таблица-карточка; load-more узаконен со счётчиком «Показано N (из Y)» ИЛИ Paginator
      (если total есть); empty с заголовком + filtered-ветка.
- [ ] PipelineSettingsPage: form-режим — тулбар с икон-плиткой (`pi-sliders-h`) + SelectButton
      «Форма/Полотно» справа (segmented как в хабе); canvas-режим chrome не изменён; переключение
      режимов работает.

**Регресс-фокус:**
- [ ] Все kebab-меню: условные пункты появляются/скрываются по статусу; действия идентичны прежним
      icon-кнопкам.
- [ ] Все фильтр-триггеры: бейдж-каунтер = реальному числу активных; сброс работает; результаты
      фильтрации не изменились.
- [ ] embedded-режимы (ProductsPage/TemplatesPage/AutomationRunsPage внутри `/settings`) —
      тулбар не рендерится, фильтры/таблица работают.

---

## Открытые вопросы (сводно)

- **[ОВ-A1]** ProductsPage: поиск видимым в тулбаре или спрятать в фильтр-панель? (рек. — видимым).
- **[ОВ-B1]** TemplateUpload: оставить `FileUpload mode="basic"` (рек.) или PO хочет drag&drop drop-зону
  (отдельная задача, dropzone-компонента в системе нет)?
- **[ОВ-C1]** MyCoursesPage: segmented (рек.) vs оставить Tabs+Badge?
- **[ОВ-C2]** AdminCourses/Assignments: встроенный DataTable-paginator + каунтер (рек.) vs отдельный
  `<Paginator>` как ProductsPage?
- **[ОВ-C3]** OnboardingAssignments: сколько фильтров в `AssignmentsFilterPanel` → паттерн А или Б?
  (прочитать компонент при реализации).
- **[ОВ-C4]** HrProgress: сколько фильтров в `HrProgressFilterPanel` → компактный inline (рек. — панель
  в контексте таблицы) или триггер?
- **[ОВ-D1]** AutomationRuns: отдаёт ли `useAutomationRuns` `total`? Если да — можно Paginator; если
  нет — узаконить load-more со счётчиком (рек.).
- **[ОВ-глоб]** navy-шапка карточки шаблона (B.2 / Спорное-1) — подтвердить, что PO согласен с
  обычным PageHeader (не navy).

> **Backend:** не требуется — весь uplift чисто фронтовый (шасси/токены). Единственный потенциальный
> backend-вопрос — ОВ-D1 (наличие `total` в runs-ответе) — не блокер, фолбэк = load-more.
