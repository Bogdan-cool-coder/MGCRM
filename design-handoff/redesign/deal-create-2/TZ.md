# ТЗ: Мгновенное создание сделки + карточка-first CRM (deal-create-2)

**Зачем:** убрать промежуточную форму `/deals/new` и создавать сделку в один клик, редиректя менеджера сразу в полноценную карточку. Незаполненные обязательные поля подсвечиваются прямо в карточке, а компания привязывается через нативный поиск. Плюс — усиление формы `/companies/new` и единство поля «План договора». User story: «Как менеджер, я хочу нажать *Новая сделка* и сразу оказаться в рабочей карточке, а система пусть подскажет, что дозаполнить».

**Где в коде:**
- `front/src/pages/DealPage/` (карточка сделки, instant-create-хуки, in-card AutoComplete компании)
- `front/src/pages/DealsPage/index.vue` (entry-point kanban/список)
- `front/src/pages/CompanyPage/` (форма `/companies/new`, returnTo-флоу)
- `front/src/pages/CompanyPage/components/CompanyDealsTab.vue`, `front/src/pages/ContactPage/components/ContactDealsTab.vue`, `front/src/shared/nav/quickActionRegistry.ts`, `front/src/components/Orbita/CommandPalette.vue` (все прочие entry-points)
- `front/src/pages/PipelineSettingsPage/` (настройка «Стадия для новых сделок»)
- `front/src/router/routes/base.ts` (маршрут `/deals/new` → триггер instant-create)

**Источник фич (old):** `examples/contracts/apps/web` (состав карточки/формы) + `examples/contracts/apps/api/.../automation_executor.py` (правило смены owner — сверяет `backend-architect`).

**Продуктовые решения (утверждены юзером 2026-07-07):** см. блок в задании. Это ТЗ описывает ИМЕННО их, без переизобретения.

> Дизайн-дисциплина (жёстко на весь документ): только токены (`$space-*`, `$radius-*`, `$font-*`, `var(--p-*)` / `var(--mg-*)`), никаких hex/px-литералов вне бренд-инвариантов; только PrimeIcons; обе темы (light + navy dark) обязательны; деньги `1 200 000 ₽`. Reuse-first — все компоненты ниже уже существуют, новых почти нет.

---

## Раздел A. Instant-create флоу

### A.1 Что меняется концептуально

**Было:** любая кнопка «Новая сделка» → `router.push('/deals/new')` → рендерится `DealCreateForm.vue` внутри `DealPage` (create-mode) → менеджер заполняет 4 обязательных поля → `POST /api/deals` → `router.replace('/deals/{id}')`.

**Стало:** любая кнопка «Новая сделка» → **сразу `POST /api/deals`** с дефолтами → `router.replace('/deals/{id}')` → полноценная карточка с подсветкой незаполненного. Промежуточная форма исчезает.

**Удаляется:**
- Компонент `DealCreateForm.vue` — удалить целиком.
- Ветка `isCreateMode` в `DealPage/index.vue` (шаблон `<template v-if="isCreateMode">`, `createInitialCompanyId/Name/PipelineId/StageId` computed, `onDealSaved`) — удалить.
- Все стили `deal-page-v2__create-*` в `DealPage/index.vue` — удалить.
- i18n-ветка `sales.deal.create.*` — почистить (оставить только реально используемые ключи, см. §F).

**Дефолты при instant-create (POST body):**
| Поле | Значение по умолчанию |
|------|----------------------|
| `title` | локализованное «Новая сделка» (`sales.deal.instant.defaultTitle`) |
| `pipeline_id` | из query `?pipeline_id=`; иначе первая воронка из `getPipelines('sales')` |
| `stage_id` | **не шлём** — backend сам берёт `pipelines.default_stage_id` (новая настройка, §D), fallback — первая не-won/lost/hidden стадия |
| `owner_user_id` | не шлём — backend ставит `creator.id` |
| `currency` | по существующей дефолт-логике (см. ОВ-1) |
| `company_id` | **NULL допустим** — из `?company_id=` если пришёл, иначе не шлём |

> **Backend-блокер BE-1 (обязателен до старта FE):** сейчас `StoreDealRequest` требует `company_id` (`required`) и `currency` (`required`), а `DealService::create()` кидает `ValidationException` без стадии. Для instant-create нужно: (1) `company_id` → `nullable`, (2) `currency` → серверный дефолт при отсутствии, (3) чтение `pipelines.default_stage_id` при выборе стартовой стадии. Оформляет `backend-architect` контрактом. **FE не стартует, пока `POST /api/deals` без `company_id`/`currency` не возвращает 201.**

### A.2 Механика на фронте (общий helper)

Создать единый composable/util `useCreateDeal()` (или функцию в `@/api/sales` обёрткой), чтобы все entry-points звали одно и то же:

```
createDealInstant({ pipeline_id?, company_id?, company_name?, contact_id? }):
  1. resolve pipeline_id (query → первая воронка). Если воронок нет → Toast error, стоп.
  2. POST /api/deals { title: t('sales.deal.instant.defaultTitle'), pipeline_id, company_id? }
  3. on 201 → router.replace(`/deals/${created.id}` , { query: contact_id ? { link_contact: contact_id } : {} })
  4. on error → Toast severity=error (errors.server_error)
```

- Во время запроса — точка входа, которая его вызвала, показывает `:loading` (kebab «Новая сделка» в тулбаре, кнопка в пустом стейте таба и т.д.). Двойной клик защищён флагом `creating`.
- **`contact_id`:** если пришёл `?contact_id=` (из `ContactDealsTab`), после редиректа карточка должна привязать контакт к сделке через существующий `POST /deals/{id}/contacts`. Проще всего — прокинуть `?link_contact=<id>` в карточку и в `bootstrapDeal()` (после загрузки) дёрнуть `dealContactsComposable.add({ contact_id, is_primary: true })`, затем убрать query (`router.replace` без query). Сейчас `contact_id` игнорируется — это закрывает gap.

### A.3 Все entry-points (единый маршрут через helper)

| # | Место | Файл | Сейчас | Стало |
|---|-------|------|--------|-------|
| 1 | Kanban «Новая сделка» (тулбар) | `DealsPage/index.vue` `onCreateDeal()` | `push('/deals/new?pipeline_id=')` | `createDealInstant({ pipeline_id: currentPipelineId })` |
| 2 | Kanban «+ в стадии» | `DealsPage/index.vue` `onCreateDeal(stageId)` | `push('/deals/new?pipeline_id=&stage_id=')` | `createDealInstant({ pipeline_id })` — **`stage_id` из карточки-в-стадии больше не передаём** (стадия = дефолт воронки; см. ОВ-2) |
| 3 | Список сделок «Новая сделка» | `DealsPage/index.vue` (тот же `onCreateDeal`) | как п.1 | как п.1 |
| 4 | Карточка компании «Создать сделку» | `CompanyPage/index.vue` `onCreateDeal` | `push('/deals/new?company_id=&company_name=')` | `createDealInstant({ pipeline_id: <дефолт>, company_id })` |
| 5 | Таб «Сделки» компании (шапка + пустой стейт) | `CompanyPage/components/CompanyDealsTab.vue` `@createDeal` | эмит → как п.4 | как п.4 |
| 6 | Таб «Сделки» контакта | `ContactPage/components/ContactDealsTab.vue` `onCreateDeal` | `push('/deals/new?contact_id=')` | `createDealInstant({ contact_id: props.contactId })` |
| 7 | Command Palette | `Orbita/CommandPalette.vue` | `push('/deals/new')` | `createDealInstant({})` |
| 8 | Quick Action Registry | `shared/nav/quickActionRegistry.ts` | route `/deals/new` | action → `createDealInstant({})` (registry item становится action-типом, не route-типом; см. ОВ-3) |

### A.4 Маршрут `/deals/new` (совместимость)

- Оставляем маршрут `/deals/new?pipeline_id=X` **как shim**. При заходе на него (deep-link, старая закладка, внешняя ссылка):
  - `DealPage` в `onMounted`, если `route.name === 'DealCreate'`, **не рендерит форму**, а сразу вызывает `createDealInstant({ pipeline_id: query.pipeline_id, company_id: query.company_id })` и `router.replace` в карточку.
  - Показываем на это время центрированный `ProgressSpinner` (полноэкранный, тот же контейнер что и loading-skeleton) — не мигаем пустой формой.
- Роут остаётся в `routes/base.ts` с `name: 'DealCreate'`, компонент тот же `DealPage`.

### A.5 Состояние карточки сразу после создания

Карточка рендерится штатно (`DealInfoPanel` + `DealFeed` + composer). Отличия «свежесозданной» сделки:
- `title` = «Новая сделка» → подсвечен как «требует заполнения» (§A.6).
- `company` = отсутствует (`deal.company === null`) → блок компании в режиме поиска-привязки (§B), подсвечен.
- Лента пуста → штатный empty-state `DealFeed` (не трогаем).
- Открытых задач нет → штатный пустой `OpenTasksList`.

### A.6 Паттерн подсветки обязательных незаполненных полей

**Что подсвечиваем:**
1. **Название** — пока `deal.title === t('sales.deal.instant.defaultTitle')` (буквально автоназвание) ИЛИ пустое.
2. **Компания** — пока `deal.company === null`.

**Где живёт название в карточке:** `DealInfoHeader.vue`, `.deal-header__title` (h2 на navy-хедере). Название редактируется через ⋮ → «Переименовать» (`renameDialogVisible`).

**Где живёт компания:** `DealTabMain.vue`, строка «Компания» (`.deal-tab-main__quick-value--company`).

**Визуальный паттрен подсветки** (DS-совместимо, обе темы, только токены):

*Название на navy-хедере (тёмный фон всегда, обе темы):*
- Рядом с `.deal-header__title` показать **чип-подсказку** `sales.deal.instant.needsTitle` («Дайте сделке название»): маленький pill в стиле `.deal-header__tag-chip` (та же полупрозрачная навигационная заливка `rgba(255,255,255,0.12)`), но с акцентной иконкой-предупреждением `pi pi-exclamation-circle` в тёплом токене. На navy бренд-хедере нельзя тянуть светлые surface-токены — используем **амбер-акцент бренд-хедера**: иконка `color: var(--mg-orange-400)` (или ближайший warning-токен, читаемый на navy — уточнить у charter, см. ОВ-4), текст `rgba(255,255,255,0.9)`.
- Чип кликабелен → открывает тот же `renameDialogVisible` (reuse ⋮-«Переименовать»). `role="button"`, `aria-label` = текст подсказки, `tabindex="0"`, Enter/Space открывают диалог.

*Компания в `DealTabMain` (светлый surface, обе темы):*
- Обёртку строки компании подсвечиваем классом `--needs-attention`:
  - `background: var(--p-amber-50)` (light) / `var(--p-amber-950)` (dark) — recessed тёплая заливка;
  - `border-left: 2px solid var(--p-amber-400)` (обе темы через token; в dark `--p-amber-400` инвертируется палитрой PrimeVue сам);
  - `border-radius: $radius-sm`, `padding: $space-1 $space-2`.
- Плюс лид-текст-подсказка мелким шрифтом под полем: `sales.deal.instant.needsCompany` («Привяжите компанию»), `font-size: $font-size-xs`, `color: var(--p-amber-700)` light / `var(--p-amber-300)` dark.

> **Почему amber, а не red:** это не ошибка валидации (сделка валидна и сохранена), а «требует внимания / дозаполни». Красный (`$red-500`) в системе = ошибка/destructive. Amber/warning = «обрати внимание». Это консистентно с `Tag severity="warning"` в статус-семантике проекта.

**Как подсветка гаснет:**
- Название: как только `deal.title !== t('sales.deal.instant.defaultTitle')` и не пустое (менеджер переименовал через диалог) — чип и подсветка исчезают реактивно (computed `titleNeedsAttention`).
- Компания: как только `deal.company !== null` (менеджер привязал/создал компанию) — подсветка гаснет реактивно (computed `companyNeedsAttention`).
- Никаких таймеров/анимаций затухания — просто `v-if`/`:class` по computed. Появление/исчезновение — мгновенное (без transition, чтобы не «дёргалось» при сохранении).

**Что НЕ делаем:** пустая сделка **не удаляется автоматически** — висит в дефолт-стадии с подсветкой (продрешение №2). Никакого «отмените создание» / «черновик».

---

## Раздел B. AutoComplete компании внутри карточки сделки

Точка: `DealTabMain.vue`, строка «Компания». Сейчас там два режима: `companyPickerOpen=false` (ссылка + карандаш) и `companyPickerOpen=true` (AutoComplete). Нужно расширить под кейс `company === null` (свежесозданная сделка) и убрать шеврон-дропдаун + починить «+ Создать компанию».

### B.1 Три состояния строки «Компания»

| Состояние | Условие | Вид |
|-----------|---------|-----|
| **Привязана** | `deal.company !== null`, пикер закрыт | `RouterLink` (имя компании) + карандаш-edit (появляется на hover). Как сейчас. |
| **Не привязана (нужно внимание)** | `deal.company === null`, пикер закрыт | AutoComplete-поле в режиме поиска **сразу открыто/готово к вводу**, обёртка подсвечена amber (§A.6). Плейсхолдер `sales.deal.info.fields.searchOrCreateCompany` («Найдите или создайте компанию»). |
| **Редактирование** | `companyPickerOpen === true` | AutoComplete (замена привязанной). Как сейчас, но с правками B.2–B.3. |

### B.2 AutoComplete без шеврона, открытие по клику/фокусу

Текущий in-card AutoComplete (`DealTabMain.vue`) уже использует `complete-on-focus` + `:min-length="0"`, но с `dropdown` (шеврон-кнопка). Правки:
- **Убрать `dropdown`** — пропадает кнопка-шеврон справа. Дропдаун открывается кликом/фокусом по самому полю (`complete-on-focus` уже это обеспечивает — при фокусе грузится начальный список из 20 компаний).
- Оставить `force-selection` (нельзя ввести произвольный текст, только выбрать из списка), `:delay="250"`, `append-to="body"`, out-of-order guard (уже есть).
- Строка компании в состоянии «не привязана» рендерит AutoComplete напрямую (без промежуточного «карандаша»).

> Продрешение №3 буквально: «AutoComplete-поиск компаний БЕЗ кнопки-шеврона (дропдаун открывается кликом/фокусом по самому полю)». `dropdown` prop у PrimeVue AutoComplete — это и есть шеврон-триггер; убираем его.

### B.3 Пункт «+ Создать компанию» — один «+»

- В `#footer` слоте AutoComplete — кнопка «Создать компанию».
- **Один знак «+»:** сейчас в удаляемом `DealCreateForm` было `<i class="pi pi-plus">` + литерал текста, где литерал i18n `sales.deal.create.createCompany` = **«+ Создать компанию»** (плюс уже в тексте → визуально два плюса). В новом футере:
  - Иконка `pi pi-plus` (одна, слева).
  - Текст **без плюса**: `sales.deal.info.fields.createCompany` = «Создать компанию».
- Стиль футер-кнопки — переиспользовать паттерн `.deal-create-form__company-create-btn` (перенести в `DealTabMain` scoped-стили под именем `.deal-tab-main__company-create-btn`): full-width, `border-top: 1px solid var(--p-surface-200/300)`, `color: var(--p-primary-color)`, hover `background: var(--p-surface-50/100)`.
- `@mousedown.prevent` (чтобы blur не съел клик до навигации).

### B.4 returnTo-флоу в карточку с автопривязкой

Продрешение №3: клик «Создать компанию» → `/companies/new?returnTo=deal-{id}` → после сохранения возврат в карточку сделки с автопривязкой.

**Изменение дискриминатора:** сейчас `returnTo=deal-new` (литерал, возврат на форму). Теперь возврат — в конкретную карточку `/deals/{id}`, поэтому дискриминатор параметризуется id:
- Клик → `router.push('/companies/new', { query: { returnTo: 'deal-' + deal.id } })`.
- В `CompanyPage.onCompanySaved()`: если `returnTo` начинается с `deal-` и хвост — число → после создания компании `router.replace('/deals/' + dealId)` **и передать флаг автопривязки**. Проще всего — query `?link_company=<newCompanyId>`:
  - `router.replace('/deals/' + dealId + '?link_company=' + created.id)`.
- В `DealPage`: если пришёл `?link_company=<id>` — после `bootstrapDeal()` вызвать `salesApi.updateDeal(dealId, { company_id })` (или ту же ветку что и `selectCompany` в `DealTabMain`), затем `router.replace` без query. Toast success `sales.deal.info.fields.companyLinked`.
- `onCompanyCreateCancel()`: если `returnTo` начинается с `deal-` → `router.replace('/deals/' + dealId)` без привязки (просто возврат).

> **Совместимость:** старое значение `returnTo=deal-new` больше не генерируется, но `CompanyPage` должен продолжать понимать его как «вернуться на список сделок» (мягкий fallback), чтобы внешние закладки не 500-или. Впрочем формы `/deals/new` больше нет — при `deal-new` редиректим на `/deals` (список).

> **Backend-блокер BE-2 (мягкий):** привязка компании к сделке уже есть (`PATCH /api/deals/{id}` с `company_id`) — это не блокер, работает. Проверить только, что смена `company_id` с NULL на значение проходит валидацию (см. BE-1: если `company_id` стал `nullable`, PATCH на него должен принимать и значение).

---

## Раздел C. Wireframe карточки свежесозданной сделки

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  DealInfoPanel (420px)                    │  DealFeed + OpenTasks + Composer   │
│  ┌──────────────────────────────────────┐│  ┌───────────────────────────────┐ │
│  │ NAVY HEADER (#172747 / #111E38 dark) ││  │  Лента пуста                  │ │
│  │ ┌─────────────────────────────┐ ← ⋮ ││  │  (empty-state DealFeed)       │ │
│  │ │ Новая сделка                │      ││  │                               │ │
│  │ └─────────────────────────────┘      ││  │                               │ │
│  │ ⚠ Дайте сделке название  (amber чип) ││  │                               │ │
│  │ [Новые лиды ▸] ⏱ 0 дн.               ││  │                               │ │
│  │ ▓▓░░░░░░ stage-progress              ││  ├───────────────────────────────┤ │
│  └──────────────────────────────────────┘│  │ OpenTasksList (пусто)         │ │
│  ┌─ Быстрые поля ───────────────────────┐│  ├───────────────────────────────┤ │
│  │ Ответственный  [аватар] Иванов И.    ││  │ DealComposer                  │ │
│  │ ┌──────────────────────────────────┐ ││  │ [Заметка][Задача][...]        │ │
│  │ │⚠ Компания                        │ ││  └───────────────────────────────┘ │
│  │ │  [🔍 Найдите или создайте…    ▾] │ ││                                    │
│  │ │  ── Создать компанию (+ 1 плюс)  │ ││   (amber border-left на строке     │
│  │ │  Привяжите компанию (amber hint) │ ││    компании; чип на названии)      │
│  │ └──────────────────────────────────┘ ││                                    │
│  │ План договора   [__.__.____ 📅]      ││                                    │
│  │ План оплаты     [__.__.____ 📅]      ││                                    │
│  └──────────────────────────────────────┘│                                    │
└─────────────────────────────────────────────────────────────────────────────┘
```
(На мобиле/планшете — существующая адаптивная раскладка `DealPage`; подсветки те же.)

---

## Раздел D. Настройка воронки — «Стадия для новых сделок»

Продрешение №4: `pipelines.default_stage_id` (nullable FK, валидация принадлежности стадии воронке) в существующем редакторе воронки.

**Где в UI:** `PipelineSettingsPage`, form-mode, правая колонка (`.pipeline-settings-page__form-content`). Над `StageEditorList` или отдельной секцией-карточкой добавить блок **«Параметры воронки»** с одним контролом:
- Лейбл `sales.pipelineEditor.defaultStage.label` = «Стадия для новых сделок».
- `Select` (PrimeVue): options = стадии текущей воронки, исключая won/lost/hidden (`is_won=false && is_lost=false && hidden_by_default=false`) — те же, что валидны как стартовые. `option-label="name"`, `option-value="id"`, `show-clear`.
- Плейсхолдер / очищенное значение = `sales.pipelineEditor.defaultStage.autoNote` = «Автоматически — первая стадия».
- Подпись-хинт под селектом: `sales.pipelineEditor.defaultStage.hint` = «В эту стадию попадают все новые сделки. Менеджер стадию при создании не выбирает».
- Сохранение — `PATCH /api/pipelines/{id}` с `{ default_stage_id }` (см. BE-1: поле должно приниматься и валидироваться на принадлежность воронке). Toast success `common.saved`, ошибка → Toast error.
- Роль-гейт — как весь `PipelineSettingsPage` (admin/director).

**States:**
- loading (стадии грузятся) → `Select :disabled` + мелкий spinner.
- empty (в воронке нет валидных стадий) → `Select :disabled` + хинт «Добавьте хотя бы одну рабочую стадию».
- Значение указывает на удалённую стадию → показать `show-clear`-состояние (backend сбрасывает FK на null через `nullOnDelete` — на UI просто «Автоматически»).

---

## Раздел E. Форма «Новая компания» (/companies/new)

Точка: `CompanyPage/components/CompanyCreateForm.vue` + шапка в `CompanyPage/index.vue`.

### E.1 Изменения секций (продрешения №5)

**Секции ПОСЛЕ правок (сверху вниз):**

1. **Обязательные поля** (как есть): `name` (required).
2. **Контактные данные** *(НОВАЯ секция)* — `sales`/`company.create.sections.contacts` = «Контактные данные»:
   - **Сайт** (`website`) — `InputText`, **required**, плейсхолдер `https://...`.
   - **Адрес** (`address`) — `InputText` (или `Textarea` autoResize, 2 строки), **required**.
   - **Телефон** (`phone`) — `InputText`, **required**, плейсхолдер `+7 ...`.
   - У всех трёх — красная звёздочка `*` (`.company-create-form__req`, `$red-500`), т.к. это настоящая валидация ошибки (в отличие от amber-подсветки в карточке сделки).
3. **Реквизиты** (как есть): `legal_form`, `tax_id` (остаются опциональными).
4. **Классификация** — `company_type_id`, `country_code`, `source` — **все три становятся required** (звёздочка + инлайн-ошибка). Убрать `show-clear` у этих трёх Select (нельзя очистить обязательное).
5. ~~**Ответственность**~~ — **секцию УДАЛИТЬ целиком** (поле `responsible_user_id` уходит с формы; owner = автор на бэке).

**Итоговый порядок:** Обязательные → Контактные данные → Реквизиты → Классификация. (Ответственность удалена.)

### E.2 Валидация формы (клиент)

- Клиентская `validate()` расширяется: `name`, `website`, `address`, `phone`, `company_type_id`, `country_code`, `source` — все required. Пустое → инлайн-ошибка под полем (`.p-error`, мелкий красный текст).
- `website` — плюс базовая проверка формата URL (уже есть на бэке `url`); при невалидном — `company.create.errors.websiteInvalid`.
- blur-гарды по образцу существующего `onNameBlur` для каждого required-поля (мягкая ранняя ошибка).
- 422 с бэка — маппинг в `errors` (уже реализован через `getValidationErrors`).

> **Backend-блокер BE-3 (обязателен):** `StoreCompanyRequest` сейчас требует только `name`. Нужно сделать `website`, `address`, `phone`, `company_type_id`, `country_code`, `source` **required — ТОЛЬКО на ручном создании через эту форму** (FormRequest). Импорты/миграции/merge/дедуп **не ломать** — они создают компании в обход FormRequest (через сервис напрямую) или через отдельный ImportRequest. `backend-architect` фиксирует, что required-правила живут в `StoreCompanyRequest`, а не в `CompanyService::create()`. Плюс убрать `responsible_user_id` из полезной нагрузки формы (owner = creator, уже дефолтится).

### E.3 Шапка страницы — убрать «⋮»

- Сейчас в create-mode `EntityInfoHeader :menu-items="[]"` — меню уже **пустое и не показывается** (по разведке `menu-items=[]` не рендерит ⋮). **Проверить визуально:** если пустой массив всё равно рисует кнопку-триггер ⋮ — скрыть её через `v-if="menuItems.length"` в `EntityInfoHeader` (или проп `hide-menu`). Продрешение №5: «меню „⋮" в шапке этой страницы убрать (пустое)». Цель — на `/companies/new` кнопки ⋮ визуально нет.

### E.4 returnTo из карточки сделки (см. B.4)

- `CompanyCreateForm` не меняется под returnTo — вся логика в `CompanyPage.onCompanySaved/onCompanyCreateCancel`, обрабатывающих `returnTo=deal-{id}`.

### E.5 Wireframe формы компании (после правок)

```
┌─ EntityInfoHeader: «Новая компания»   ← (без ⋮) ─────────────────┐
├──────────────────────────────────────────────────────────────────┤
│ ┌─ ОБЯЗАТЕЛЬНЫЕ ПОЛЯ ────────────────────────────────────────┐   │
│ │ Название *   [___________________________]                 │   │
│ └────────────────────────────────────────────────────────────┘   │
│ ┌─ КОНТАКТНЫЕ ДАННЫЕ (новая) ────────────────────────────────┐   │
│ │ Сайт *       [https://______________]                      │   │
│ │ Адрес *      [___________________________]                 │   │
│ │ Телефон *    [+7 ______________]                           │   │
│ └────────────────────────────────────────────────────────────┘   │
│ ┌─ РЕКВИЗИТЫ ────────────────────────────────────────────────┐   │
│ │ Правовая форма  [ТОО / ООО / ИП]                           │   │
│ │ БИН / ИНН       [___________]                              │   │
│ └────────────────────────────────────────────────────────────┘   │
│ ┌─ КЛАССИФИКАЦИЯ ────────────────────────────────────────────┐   │
│ │ Тип компании *  [Select ▾]                                 │   │
│ │ Страна *        [Select ▾]                                 │   │
│ │ Источник *      [Select ▾]                                 │   │
│ └────────────────────────────────────────────────────────────┘   │
│                                        [Отменить]  [✓ Создать]    │
└──────────────────────────────────────────────────────────────────┘
```

---

## Раздел F. Переименование «Планируемая дата закрытия» → «План договора»

Продрешение №7: «Планируемая дата закрытия» — это то же поле, что «План договора» в карточке. Убедиться в единстве поля, переименовать оставшиеся подписи (i18n), не сломать фильтры/отчёты.

**Разбор текущего состояния (ключевой нюанс — это РАЗНЫЕ backend-поля!):**
| i18n-ключ / место | Текущий RU | Backend-колонка |
|-------------------|-----------|-----------------|
| `sales.deal.info.fields.plannedContract` (карточка, DealTabMain) | «План договора» | `expected_sign_date` |
| `sales.deal.info.fields.plannedPayment` (карточка) | «План оплаты» | `expected_payment_date` |
| `sales.deal.create.fields.expectedCloseDate` (удаляемая форма) | «Ожидаемая дата закрытия» | `expected_close_date` |
| `sales.deal.*.fields.expectedCloseDate` (move-dialog контекст, ru.json:2482) | «Планируемая дата закрытия» | `expected_close_date` |
| `crm.log ... expected_close_date` (ru.json:4563) | «Планируемая дата закрытия» | `expected_close_date` (лейбл в ленте) |

> **ВАЖНО — открытый вопрос ОВ-5:** в карточке «План договора» маппится на `expected_sign_date`, а «Планируемая дата закрытия» — на `expected_close_date`. Это **два разных столбца БД**. Продрешение №7 говорит «это ТО ЖЕ поле». Нужно подтверждение бизнеса: (а) действительно ли `expected_close_date` и `expected_sign_date` — семантические дубли, которые надо слить в одно поле «План договора»? Или (б) достаточно просто **переименовать все подписи `expected_close_date` в «План договора»** и оставить столбец? Слияние столбцов — backend-миграция + правка фильтров/отчётов/reports-export, это `backend-architect` + `sales-backender`. **Без ответа на ОВ-5 FE делает только (б) — переименование лейблов.**

**Что делает FE (безопасный минимум — переименование лейблов `expected_close_date`):**
- Все места, где `expected_close_date` показывается пользователю под подписью «Планируемая дата закрытия» / «Ожидаемая дата закрытия», переименовать в **«План договора»**:
  - `ru.json:2482` (`expectedCloseDate` в move/детальном контексте) → «План договора».
  - `ru.json:4563` (`crm.log.fields.expected_close_date`) → «План договора» (лента).
  - EN-зеркало: «Contract plan» (или согласовать, см. EN-задел ниже).
- **НЕ трогать** ключи фильтров/отчётов, если под ними стоит `expected_close_date` как параметр (переименовывается только человекочитаемый лейбл, не имя параметра/колонки) — фильтры и reports работают по имени поля, не по лейблу; переименование строки лейбла их не ломает.
- Если после ОВ-5 бизнес подтверждает слияние — отдельная задача backend (`sales-backender`), FE тогда убирает дубль-строку из `DealTabMain` (одно поле «План договора» вместо двух).

---

## Список PrimeVue-компонентов (все существующие, reuse-first)

| Компонент | Где | Props/примечание |
|-----------|-----|------------------|
| `AutoComplete` | in-card компания (`DealTabMain`) | `force-selection`, `complete-on-focus`, `:min-length="0"`, `:delay="250"`, `append-to="body"`, **без `dropdown`**, `#footer` = кнопка «Создать компанию» |
| `Select` | воронка default_stage_id (`PipelineSettings`), классификация (`CompanyCreateForm`) | `option-label`/`option-value`, `show-clear` (только для default_stage_id), обязательные Select — без `show-clear` |
| `InputText` | website/address/phone (`CompanyCreateForm`), название (rename-dialog) | `:class="{ 'p-invalid': errors.* }"` |
| `Textarea` | адрес (опционально, `autoResize`) | если адрес многострочный |
| `Button` | action-bar формы, entry-points | `icon="pi pi-check"`/`pi pi-plus`, `:loading` |
| `Dialog` | rename (существующий `DealInfoHeader`) | reuse для клика по amber-чипу названия |
| `ProgressSpinner` | shim `/deals/new` во время instant-create | центрированный |
| `Skeleton` | карточка при загрузке (существует) | не меняем |
| `Toast` | success/error всех действий | severity success/error |
| `Tag` / чип | amber-подсказки | пилюли на своих токенах (не новый компонент — span-чип) |

**Новые компоненты:** нет обязательных. amber-чип названия и amber-строка компании — inline-разметка на существующих паттернах (`.deal-header__tag-chip`, `DealFieldRow`-обёртка). Если `frontend-specialist` захочет вынести amber-подсказку в `<NeedsAttentionHint>` — допустимо, но с обоснованием (переиспользуется в 2 местах — название и компания).

---

## Пустые / ошибочные состояния (сводно)

| Место | Состояние | Поведение |
|-------|-----------|-----------|
| instant-create | нет воронок в `getPipelines('sales')` | Toast error `sales.deal.instant.noPipeline` («Не настроена ни одна воронка»), навигации нет |
| instant-create | сеть/500 | Toast error `errors.server_error`, остаёмся на текущей странице, `:loading` снят |
| `/deals/new` shim | загрузка | `ProgressSpinner` центрированный, потом replace в карточку |
| in-card AutoComplete | поиск ничего не нашёл | нативный пустой список PrimeVue + видимый футер «Создать компанию» |
| in-card AutoComplete | ошибка поиска | `companyOptions=[]` (уже есть out-of-order guard), футер «Создать компанию» доступен |
| carточка после create | `deal.company === null` | amber-строка компании + AutoComplete в режиме поиска |
| carточка после create | `title` = автоназвание | amber-чип на хедере |
| форма компании | required пустые | инлайн `.p-error` под каждым полем, `Button ✓ Создать` не блокируется (валидация на клике), 422 маппится |
| default_stage_id | нет валидных стадий | `Select :disabled` + хинт |

---

## i18n-ключи (RU обязательно, EN — задел)

```jsonc
// НОВЫЕ
"sales.deal.instant.defaultTitle": "Новая сделка",                    // "New deal"
"sales.deal.instant.needsTitle": "Дайте сделке название",             // "Name this deal"
"sales.deal.instant.needsCompany": "Привяжите компанию",              // "Link a company"
"sales.deal.instant.noPipeline": "Не настроена ни одна воронка",      // "No pipeline configured"

"sales.deal.info.fields.searchOrCreateCompany": "Найдите или создайте компанию", // "Find or create a company"
"sales.deal.info.fields.createCompany": "Создать компанию",           // "Create company"  (БЕЗ плюса — плюс = иконка)
"sales.deal.info.fields.companyLinked": "Компания привязана",         // "Company linked"

"sales.pipelineEditor.defaultStage.label": "Стадия для новых сделок",  // "Stage for new deals"
"sales.pipelineEditor.defaultStage.autoNote": "Автоматически — первая стадия", // "Automatic — first stage"
"sales.pipelineEditor.defaultStage.hint": "В эту стадию попадают все новые сделки. Менеджер стадию при создании не выбирает.", // "..."

"company.create.sections.contacts": "Контактные данные",              // "Contact details"
"company.create.errors.websiteRequired": "Укажите сайт",              // "Website is required"
"company.create.errors.websiteInvalid": "Неверный формат сайта",      // "Invalid website"
"company.create.errors.addressRequired": "Укажите адрес",             // "Address is required"
"company.create.errors.phoneRequired": "Укажите телефон",             // "Phone is required"
"company.create.errors.companyTypeRequired": "Выберите тип компании", // "Select company type"
"company.create.errors.countryRequired": "Выберите страну",           // "Select a country"
"company.create.errors.sourceRequired": "Выберите источник",          // "Select a source"

// ПЕРЕИМЕНОВАТЬ (значение)
"...expectedCloseDate (ru.json:2482)": "План договора",               // was "Планируемая дата закрытия"
"crm.log.fields.expected_close_date (ru.json:4563)": "План договора", // was "Планируемая дата закрытия"

// УДАЛИТЬ (форма /deals/new больше не существует)
// вся ветка sales.deal.create.* — оставить только реально ссылающиеся ключи после чистки
```
EN-задел — согласовать точные формулировки с существующим стилем `en.json`; выше — черновик.

---

## Референс-экраны

- Карточка сделки (визуал/раскладка) — `design-handoff/redesign/deal-card.html` + `DealCard-spec.md` §§1–11.
- Реальный код карточки — `front/src/pages/DealPage/` (`DealInfoHeader`, `DealTabMain`, `DealFieldGroup`, `DealFieldRow`).
- Форма компании — `front/src/pages/CompanyPage/components/CompanyCreateForm.vue` (текущая), редизайн-паттерн секций — `EntityCard-spec.md`.
- Токены amber/warning + закон обеих тем — `.claude/skills/macroglobal-design/tokens/*.css`, `docs/designer-charter.md` §«Обе темы».
- Настройка воронки — `front/src/pages/PipelineSettingsPage/index.vue` (form-mode).

---

## Acceptance-чеклист для QA (обе темы, light + navy dark)

**A. Instant-create:**
1. Клик «Новая сделка» в тулбаре kanban → сразу `POST /api/deals` (201) → редирект в `/deals/{id}`, форма `/deals/new` не мелькает.
2. Клик «+ в стадии» на kanban → сделка создаётся в дефолт-стадии воронки (не в той, где нажали) — стадия из настройки, а не из карточки-в-стадии (ОВ-2).
3. Кнопка «Создать сделку» на карточке компании → сделка создаётся с уже привязанной этой компанией (amber-подсветки компании НЕТ), название = «Новая сделка» (чип названия ЕСТЬ).
4. Кнопка «Создать сделку» в табе контакта → сделка создаётся, контакт автоматически привязан (виден в блоке контактов карточки).
5. Command Palette / Quick Action «Новая сделка» → создаёт и открывает карточку.
6. Deep-link `/deals/new?pipeline_id=X` → spinner → редирект в созданную карточку (совместимость).
7. Нет воронок → Toast error, навигации нет.
8. Двойной быстрый клик «Новая сделка» → создаётся ОДНА сделка (guard).

**B. Подсветка:**
9. Свежая сделка: на navy-хедере amber-чип «Дайте сделке название» (иконка читаема на navy, обе темы).
10. Клик по amber-чипу → открывается rename-диалог; после сохранения имени чип исчезает реактивно.
11. Свежая сделка без компании: строка компании подсвечена amber (border-left + fon + hint «Привяжите компанию»), обе темы, контраст ≥ AA.
12. После привязки компании amber-подсветка гаснет реактивно, без перезагрузки.
13. Пустая сделка НЕ удаляется — при возврате в kanban висит в дефолт-стадии.

**C. AutoComplete компании в карточке:**
14. У поля компании НЕТ кнопки-шеврона; дропдаун открывается кликом/фокусом по полю.
15. Футер списка — «Создать компанию» с ОДНИМ «+» (иконка `pi pi-plus`, текст без плюса).
16. Клик «Создать компанию» → `/companies/new?returnTo=deal-{id}` → после сохранения возврат в ту же карточку сделки, новая компания автоматически привязана, Toast «Компания привязана».
17. Cancel на форме компании (с returnTo=deal-{id}) → возврат в карточку сделки без привязки.

**D. Настройка воронки:**
18. В редакторе воронки есть «Стадия для новых сделок» (Select без won/lost/hidden), сохраняется (`PATCH`), Toast success.
19. Очистка (show-clear) → «Автоматически — первая стадия».
20. После установки default_stage_id новые сделки создаются именно в ней.

**E. Форма /companies/new:**
21. Есть секция «Контактные данные»: сайт/адрес/телефон — все с красной `*`.
22. Классификация (тип/страна/источник) — все с красной `*`, без show-clear.
23. Секция «Ответственность» отсутствует.
24. Кнопки ⋮ в шапке нет.
25. Пустые required → инлайн-ошибки; заполнение → создание проходит (201), owner = текущий юзер.
26. Импорт/merge/дедуп компаний по-прежнему работают без обязательных контактных полей (регресс BE-3).

**F. Переименование:**
27. «Планируемая дата закрытия» / «Ожидаемая дата закрытия» под `expected_close_date` теперь «План договора» везде (карточка move-контекст + лента), обе темы.
28. Фильтры и отчёты, использующие `expected_close_date`, не сломаны (работают по имени поля).

**Общее:**
29. `npm run lint:ds` = 0, `vue-tsc` = 0, `build` зелёный.
30. Нет hex/px-литералов вне бренд-инвариантов; amber-подсветка — только через `var(--p-amber-*)`.
31. Обе темы: computed-styles amber-подсветки и navy-чипа читаемы (контраст AA), скриншот-сверка.

---

## Открытые вопросы

- **ОВ-1 (currency default):** сейчас currency-дефолт вычислялся на фронте по стране компании (`COUNTRY_CURRENCY_MAP`). При instant-create компании может не быть. Какой дефолт валюты на бэке при отсутствии company и currency? (RUB? По воронке? По юзеру?) — решает бизнес/`backend-architect`, BE-1.
- **ОВ-2 (stage_id при «+ в стадии»):** продрешение №4 говорит «руками стадию при создании больше никто не выбирает». Значит ли это, что клик «+ в конкретной стадии» на kanban тоже игнорирует эту стадию и кладёт в дефолт? (Спорно — менеджер явно указал стадию.) Подтвердить: (а) игнорировать явную стадию → всегда дефолт, или (б) для «+ в стадии» разрешить `stage_id` как исключение. ТЗ по умолчанию принимает (а).
- **ОВ-3 (Quick Action Registry):** элемент реестра сейчас route-типа (`/deals/new`). Переделать в action-тип (вызов `createDealInstant`) — нужно подтверждение, что реестр поддерживает action-элементы, или оставить route-shim `/deals/new` (тогда п.8 entry-points работает через shim A.4, без переделки реестра). Проще — оставить shim.
- **ОВ-4 (amber-токен на navy):** какой именно warning/amber токен читается на navy бренд-хедере (`#172747`/`#111E38`) с контрастом AA? `--mg-orange-400`? Уточнить у `docs/designer-charter.md` / charter-владельца, т.к. бренд-хедер не тянет обычные surface-токены.
- **ОВ-5 (слияние expected_close_date / expected_sign_date):** «План договора» в карточке = `expected_sign_date`, «Планируемая дата закрытия» = `expected_close_date` — это РАЗНЫЕ столбцы. Продрешение №7 утверждает «то же поле». Нужно бизнес-решение: слить столбцы (backend-миграция + правка фильтров/reports) или только переименовать лейблы `expected_close_date`? Без ответа FE делает только переименование лейблов.
- **ОВ-6 (owner auto-logic, продрешение №6):** правило синхронизации owner (сделка→компания/контакты; точечная задача) — это backend-логика, не UI. `backend-architect` сверяет с `examples/contracts/.../automation_executor.py` и фиксирует финальное правило в контракте (правило старой системы приоритетно). UI-часть: смена owner логируется в ленту (уже есть `FieldLabelResolver` → «Ответственный»); проверить, что авто-смена owner тоже попадает в ленту. Требуется backend — вне этого FE-ТЗ, отмечено как зависимость.
